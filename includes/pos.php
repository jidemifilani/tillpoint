<?php
/**
 * The till: sessions, baskets, sale completion, refunds and the cash drawer.
 *
 * Money arithmetic, stated once so the rest of the file can be read:
 *
 *   products.sell_price is VAT-INCLUSIVE, because that is what is on the shelf
 *   edge and what the customer pays. So for each line:
 *
 *       line_total = the VAT-inclusive amount charged, after any promotion
 *       vat_amount = line_total * rate / (1 + rate)
 *       net        = line_total - vat_amount
 *
 *   and for the sale:
 *
 *       subtotal  = SUM(net)
 *       vat_total = SUM(vat_amount)
 *       total     = SUM(line_total) = subtotal + vat_total
 *
 * Deriving VAT out of an inclusive price rather than adding it to an exclusive
 * one is the only way the receipt total matches the shelf prices the customer
 * added up in their head. tests/smoke.php asserts those three equalities on
 * every completed sale.
 */
declare(strict_types=1);

class PosError extends RuntimeException
{
}

/* ================================================================== */
/* TILL SESSIONS                                                      */
/* ================================================================== */

/** The signed-in user's open session, if any. */
function session_current(?int $userId = null): ?array
{
    $userId = $userId ?? (int) ($_SESSION['uid'] ?? 0);
    if ($userId === 0) {
        return null;
    }
    return q1(
        'SELECT ts.*, t.code AS terminal_code, t.name AS terminal_name, st.name AS store_name
           FROM till_sessions ts
           JOIN terminals t ON t.id = ts.terminal_id
           JOIN stores st ON st.id = ts.store_id
          WHERE ts.user_id = ? AND ts.status = \'open\'
          ORDER BY ts.opened_at DESC LIMIT 1',
        [$userId]
    );
}

/**
 * Open a till session.
 *
 * One open session per user and one per terminal. Both are enforced, because
 * either overlap destroys cash accountability: a cashier with two open sessions
 * can move a shortfall between them, and two cashiers on one drawer means
 * neither can be held to its count.
 */
function session_open(int $storeId, int $terminalId, int $userId, float $openingFloat): array
{
    return db_tx(function () use ($storeId, $terminalId, $userId, $openingFloat): array {
        if ($openingFloat < 0) {
            throw new PosError('The opening float cannot be negative.');
        }
        $existing = q1('SELECT session_no FROM till_sessions WHERE user_id = ? AND status = \'open\'', [$userId]);
        if ($existing) {
            throw new PosError('You already have an open till session (' . $existing['session_no'] . '). Close it first.');
        }
        $busy = q1(
            'SELECT ts.session_no, u.full_name FROM till_sessions ts
               JOIN users u ON u.id = ts.user_id
              WHERE ts.terminal_id = ? AND ts.status = \'open\'',
            [$terminalId]
        );
        if ($busy) {
            throw new PosError('That terminal is already open under ' . $busy['full_name']
                . ' (' . $busy['session_no'] . '). It must be closed first.');
        }
        $term = q1('SELECT * FROM terminals WHERE id = ? AND is_active = 1', [$terminalId]);
        if (!$term || (int) $term['store_id'] !== $storeId) {
            throw new PosError('That terminal does not belong to this store.');
        }

        $no = next_number('session', $storeId);
        $id = db_insert('till_sessions', [
            'session_no'    => $no,
            'store_id'      => $storeId,
            'terminal_id'   => $terminalId,
            'user_id'       => $userId,
            'opening_float' => money_round($openingFloat),
            'status'        => 'open',
        ]);

        if ($openingFloat > 0) {
            db_insert('cash_movements', [
                'session_id' => $id,
                'store_id'   => $storeId,
                'type'       => 'float_in',
                'amount'     => money_round($openingFloat),
                'note'       => 'Opening float',
                'user_id'    => $userId,
            ]);
        }

        audit_log('session.open', 'till_session', $id,
            sprintf('Till %s opened on %s with a float of %s', $no, $term['code'], money($openingFloat)),
            null, null, 'notice', $storeId);

        return ['id' => $id, 'session_no' => $no];
    });
}

/**
 * What should be in the drawer right now.
 *
 * Only cash moves a drawer, so card and transfer takings are excluded; change
 * given is already excluded because sale_payments.amount holds the amount
 * applied to the sale while `tendered` holds what the customer handed over.
 */
function session_expected_cash(int $sessionId): array
{
    $s = q1('SELECT * FROM till_sessions WHERE id = ?', [$sessionId]);
    if (!$s) {
        throw new PosError('That till session does not exist.');
    }

    $cashSales = (float) qv(
        'SELECT COALESCE(SUM(sp.amount), 0) FROM sale_payments sp
           JOIN sales sa ON sa.id = sp.sale_id
          WHERE sa.session_id = ? AND sp.method = \'cash\'
            AND sa.status IN (\'completed\',\'refunded\',\'partly_refunded\')',
        [$sessionId], 0.0
    );
    $cashRefunds = (float) qv(
        'SELECT COALESCE(SUM(total), 0) FROM refunds
          WHERE session_id = ? AND status = \'posted\' AND method = \'cash\'',
        [$sessionId], 0.0
    );
    $moves = qpairs(
        'SELECT type, COALESCE(SUM(amount), 0) FROM cash_movements
          WHERE session_id = ? GROUP BY type',
        [$sessionId]
    );
    $payIn  = (float) ($moves['pay_in'] ?? 0);
    $payOut = (float) ($moves['pay_out'] ?? 0);
    $drops  = (float) ($moves['drop'] ?? 0);
    // float_in rows include the opening float itself, so counting both would
    // double it. The opening float is taken from the session record.
    $extraFloat = (float) ($moves['float_in'] ?? 0) - (float) $s['opening_float'];
    $extraFloat = max(0.0, $extraFloat);

    $expected = (float) $s['opening_float'] + $cashSales + $payIn + $extraFloat
                - $cashRefunds - $payOut - $drops;

    return [
        'opening_float' => (float) $s['opening_float'],
        'cash_sales'    => $cashSales,
        'cash_refunds'  => $cashRefunds,
        'pay_in'        => $payIn + $extraFloat,
        'pay_out'       => $payOut,
        'drops'         => $drops,
        'expected'      => money_round($expected),
    ];
}

/** Everything a shift did: the X-report when open, the Z-report when closed. */
function session_report(int $sessionId): array
{
    $s = q1(
        'SELECT ts.*, u.full_name AS cashier, t.code AS terminal_code, st.name AS store_name
           FROM till_sessions ts
           JOIN users u ON u.id = ts.user_id
           JOIN terminals t ON t.id = ts.terminal_id
           JOIN stores st ON st.id = ts.store_id
          WHERE ts.id = ?',
        [$sessionId]
    );
    if (!$s) {
        throw new PosError('That till session does not exist.');
    }

    $sales = q1(
        'SELECT COUNT(*) AS txn, COALESCE(SUM(total),0) AS gross,
                COALESCE(SUM(subtotal),0) AS net, COALESCE(SUM(vat_total),0) AS vat,
                COALESCE(SUM(discount_total),0) AS discounts,
                COALESCE(SUM(cost_total),0) AS cost,
                COALESCE(SUM(line_count),0) AS items
           FROM sales WHERE session_id = ? AND status IN (\'completed\',\'refunded\',\'partly_refunded\')',
        [$sessionId]
    );
    $voids = q1(
        'SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS value
           FROM sales WHERE session_id = ? AND status = \'voided\'',
        [$sessionId]
    );
    $byMethod = qpairs(
        'SELECT sp.method, COALESCE(SUM(sp.amount),0) FROM sale_payments sp
           JOIN sales sa ON sa.id = sp.sale_id
          WHERE sa.session_id = ? AND sa.status IN (\'completed\',\'refunded\',\'partly_refunded\')
          GROUP BY sp.method',
        [$sessionId]
    );
    $refunds = q1(
        'SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS value
           FROM refunds WHERE session_id = ? AND status = \'posted\'',
        [$sessionId]
    );
    $cash = session_expected_cash($sessionId);

    $gross = (float) $sales['gross'];
    $cost  = (float) $sales['cost'];
    $net   = (float) $sales['net'];

    return [
        'session'      => $s,
        'txn_count'    => (int) $sales['txn'],
        'item_count'   => (float) $sales['items'],
        'gross_sales'  => $gross,
        'net_sales'    => $net,
        'vat_total'    => (float) $sales['vat'],
        'discounts'    => (float) $sales['discounts'],
        'cost_total'   => $cost,
        'gross_profit' => money_round($net - $cost),
        'margin_pct'   => $net > 0 ? (($net - $cost) / $net) * 100 : 0.0,
        'avg_basket'   => (int) $sales['txn'] > 0 ? money_round($gross / (int) $sales['txn']) : 0.0,
        'void_count'   => (int) $voids['n'],
        'void_value'   => (float) $voids['value'],
        'refund_count' => (int) $refunds['n'],
        'refund_value' => (float) $refunds['value'],
        'by_method'    => $byMethod,
        'cash'         => $cash,
    ];
}

/**
 * Close a session against a declared cash count.
 *
 * The cashier declares, the system computes, and the difference is recorded
 * whatever it is. Beyond tolerance the session stays unreconciled until someone
 * else signs it off - a cashier who can clear their own shortfall is not being
 * counted. Note the order: a parked basket must be dealt with first, because
 * stock reserved by an abandoned basket is stock nobody can sell.
 */
function session_close(int $sessionId, float $declaredCash, int $userId, string $note = ''): array
{
    return db_tx(function () use ($sessionId, $declaredCash, $userId, $note): array {
        $s = db_lock_row('till_sessions', 'id = :id', ['id' => $sessionId]);
        if (!$s) {
            throw new PosError('That till session does not exist.');
        }
        if ($s['status'] !== 'open') {
            throw new PosError('That session is already ' . $s['status'] . '.');
        }
        if ($declaredCash < 0) {
            throw new PosError('The declared cash cannot be negative.');
        }

        $parked = (int) qv('SELECT COUNT(*) FROM sales WHERE session_id = ? AND status = \'parked\'',
            [$sessionId], 0);
        if ($parked > 0) {
            throw new PosError("There are still $parked parked basket(s) on this till. "
                . 'Complete or void them before closing, or the stock they hold stays reserved.');
        }

        $cash      = session_expected_cash($sessionId);
        $expected  = (float) $cash['expected'];
        $variance  = money_round($declaredCash - $expected);
        $tolerance = setting_num('till_variance_tolerance', (float) TILL_VARIANCE_TOLERANCE);
        $withinTol = abs($variance) <= $tolerance;

        db_update('till_sessions', [
            'closed_at'     => date('Y-m-d H:i:s'),
            'closed_by'     => $userId,
            'expected_cash' => $expected,
            'declared_cash' => money_round($declaredCash),
            'variance'      => $variance,
            // Within tolerance it needs no second pair of eyes, so it closes
            // reconciled and does not clutter the manager's queue.
            'status'        => $withinTol ? 'reconciled' : 'closed',
            'reconciled_by' => $withinTol ? $userId : null,
            'reconciled_at' => $withinTol ? date('Y-m-d H:i:s') : null,
            'note'          => $note !== '' ? substr($note, 0, 255) : null,
        ], 'id = :id', ['id' => $sessionId]);

        audit_log('session.close', 'till_session', $sessionId,
            sprintf('Till %s closed: declared %s against %s expected, variance %s',
                $s['session_no'], money($declaredCash), money($expected), money($variance)),
            null, ['expected' => $expected, 'declared' => $declaredCash, 'variance' => $variance],
            $withinTol ? 'notice' : 'warning', (int) $s['store_id']);

        if (!$withinTol) {
            audit_raise_exception('TILL_VARIANCE', [
                'store_id'     => (int) $s['store_id'],
                'subject_user' => (int) $s['user_id'],
                'entity'       => 'till_session',
                'entity_id'    => $sessionId,
                'period_date'  => date('Y-m-d', strtotime((string) $s['opened_at'])),
                'metric'       => abs($variance),
                'threshold'    => $tolerance,
                'severity'     => abs($variance) > $tolerance * 5 ? 'high' : 'medium',
                'summary'      => sprintf('Till %s closed %s %s (tolerance %s)',
                    $s['session_no'], money(abs($variance)),
                    $variance < 0 ? 'short' : 'over', money($tolerance)),
                'detail'       => $cash,
            ]);
            notify_role('manager', 'warning', 'Till variance needs reconciling',
                sprintf('%s closed %s %s.', $s['session_no'], money(abs($variance)),
                    $variance < 0 ? 'short' : 'over'),
                base_url('pos/session', ['id' => $sessionId]));
        }

        return ['expected' => $expected, 'declared' => money_round($declaredCash),
                'variance' => $variance, 'within_tolerance' => $withinTol];
    });
}

/** Sign off a variance. Never the cashier whose drawer it was. */
function session_reconcile(int $sessionId, int $reconcilerId, string $note): void
{
    db_tx(function () use ($sessionId, $reconcilerId, $note): void {
        $s = db_lock_row('till_sessions', 'id = :id', ['id' => $sessionId]);
        if (!$s) {
            throw new PosError('That till session does not exist.');
        }
        if ($s['status'] !== 'closed') {
            throw new PosError('Only a closed, unreconciled session can be signed off.');
        }
        sod_assert_different($reconcilerId, (int) $s['user_id'], 'reconcile your own till');
        if (trim($note) === '') {
            throw new PosError('Record what explains the variance before signing it off.');
        }

        db_update('till_sessions', [
            'status'        => 'reconciled',
            'reconciled_by' => $reconcilerId,
            'reconciled_at' => date('Y-m-d H:i:s'),
            'note'          => substr(trim((string) ($s['note'] ?? '') . ' | Reconciled: ' . $note), 0, 255),
        ], 'id = :id', ['id' => $sessionId]);

        audit_log('session.reconcile', 'till_session', $sessionId,
            sprintf('Till %s variance of %s signed off: %s',
                $s['session_no'], money((float) $s['variance']), $note),
            null, null, 'notice', (int) $s['store_id']);
    });
}

/* ================================================================== */
/* THE BASKET                                                         */
/* ================================================================== */

/** Start a basket. It exists as a parked sale until it is paid for or voided. */
function sale_open(int $sessionId, ?int $customerId = null): int
{
    return db_tx(function () use ($sessionId, $customerId): int {
        $s = q1('SELECT * FROM till_sessions WHERE id = ? AND status = \'open\'', [$sessionId]);
        if (!$s) {
            throw new PosError('No open till session. Open your till before serving a customer.');
        }
        $receiptNo = next_number('receipt', (int) $s['store_id']);
        return db_insert('sales', [
            'receipt_no'    => $receiptNo,
            'store_id'      => (int) $s['store_id'],
            'terminal_id'   => (int) $s['terminal_id'],
            'session_id'    => $sessionId,
            'customer_id'   => $customerId,
            'cashier_id'    => (int) $s['user_id'],
            'business_date' => business_date($s),
            'status'        => 'parked',
        ]);
    });
}

/** Resolve a scan or a typed code to a product, honouring multipack barcodes. */
function pos_find_product(string $code, int $storeId): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }

    $sql = 'SELECT p.*, c.name AS category_name, u.is_decimal,
                   COALESCE(sl.qty_on_hand, 0) AS qty_on_hand,
                   COALESCE(sl.qty_reserved, 0) AS qty_reserved,
                   COALESCE(sl.avg_cost, p.cost_price) AS avg_cost
              FROM products p
              JOIN categories c ON c.id = p.category_id
              JOIN units u ON u.code = p.unit_code
              LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
             WHERE p.is_active = 1 AND ';

    $p = q1($sql . '(p.barcode = ? OR p.sku = ?) LIMIT 1', [$storeId, $code, $code]);
    if ($p) {
        $p['qty_per_scan'] = 1.0;
        return $p;
    }

    // A secondary barcode may represent a case, so the scan adds qty_per_scan.
    $alt = q1('SELECT product_id, qty_per_scan FROM product_barcodes WHERE barcode = ?', [$code]);
    if ($alt) {
        $p = q1($sql . 'p.id = ? LIMIT 1', [$storeId, (int) $alt['product_id']]);
        if ($p) {
            $p['qty_per_scan'] = (float) $alt['qty_per_scan'];
            return $p;
        }
    }
    return null;
}

/** Name/SKU search for the till's keyboard lookup. */
function pos_search_products(string $term, int $storeId, int $limit = 25): array
{
    $like = '%' . $term . '%';
    return qa(
        'SELECT p.id, p.sku, p.barcode, p.name, p.sell_price, p.unit_code, p.is_weighed,
                p.age_restricted, COALESCE(sl.qty_on_hand, 0) AS qty_on_hand
           FROM products p
           LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
          WHERE p.is_active = 1 AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)
          ORDER BY (p.name LIKE ?) DESC, p.name ASC
          LIMIT ' . (int) $limit,
        [$storeId, $like, $like, $like, $term . '%']
    );
}

/**
 * Add a line to a basket.
 *
 * Stock is reserved, not consumed - the goods are in the trolley, not sold. The
 * reservation stops a second till selling the same last unit while this customer
 * is still shopping, and is released on completion (where the real issue
 * happens) or on void.
 *
 * $opts: unit_price (override), discount, discount_reason, overridden_by.
 */
function sale_add_line(int $saleId, int $productId, float $qty, array $opts = []): array
{
    return db_tx(function () use ($saleId, $productId, $qty, $opts): array {
        $sale = db_lock_row('sales', 'id = :id', ['id' => $saleId]);
        if (!$sale) {
            throw new PosError('That basket no longer exists.');
        }
        if ($sale['status'] !== 'parked') {
            throw new PosError('That sale is ' . $sale['status'] . ' and can no longer be changed.');
        }

        $storeId = (int) $sale['store_id'];
        $product = q1('SELECT p.*, u.is_decimal FROM products p JOIN units u ON u.code = p.unit_code
                        WHERE p.id = ? AND p.is_active = 1', [$productId]);
        if (!$product) {
            throw new PosError('That product is not on sale.');
        }

        $qty = (int) $product['is_decimal'] === 1 ? qty_round($qty) : round($qty);
        if ($qty <= 0) {
            throw new PosError('Enter a quantity greater than zero.');
        }
        if ((int) $product['is_decimal'] === 0 && abs($qty - round($qty)) > 0.0005) {
            throw new PosError($product['name'] . ' is sold in whole units.');
        }

        // Can we actually supply it? Count what this basket already holds, since
        // its own reservation is part of qty_reserved.
        $already = (float) qv('SELECT COALESCE(SUM(qty),0) FROM sale_lines WHERE sale_id = ? AND product_id = ?',
            [$saleId, $productId], 0.0);
        $available = stock_available($productId, $storeId) + $already;
        if (!setting_bool('negative_stock_allowed', NEGATIVE_STOCK_ALLOWED) && $qty > $available + 0.0005) {
            throw new PosError(sprintf('Only %s of %s available to sell.',
                qty_fmt(max(0, $available)), $product['name']));
        }

        $priced   = promo_price_line($product, $qty);
        $listPrice = (float) $product['sell_price'];
        $unitPrice = (float) $priced['unit_price'];
        $lineTotal = (float) $priced['line_total'];
        $discount  = (float) $priced['discount'];
        $promoId   = $priced['promotion_id'];
        $overridden = false;

        // A manual price override replaces the promotion rather than stacking on
        // it, and is only accepted with a supervisor already attached by the
        // calling page - pos.php never authorises itself.
        if (isset($opts['unit_price']) && $opts['unit_price'] !== null && $opts['unit_price'] !== '') {
            $override = money_round((float) $opts['unit_price']);
            if ($override < 0) {
                throw new PosError('An overridden price cannot be negative.');
            }
            if (empty($opts['overridden_by'])) {
                throw new PosError('A price override needs a supervisor authorisation.');
            }
            $maxPct = setting_num('price_override_max_pct', (float) PRICE_OVERRIDE_MAX_PCT);
            if ($listPrice > 0) {
                $offBy = abs(($listPrice - $override) / $listPrice) * 100;
                if ($offBy > $maxPct) {
                    throw new PosError(sprintf(
                        'That price is %s away from the shelf price of %s; the limit is %s.',
                        pct_fmt($offBy), money($listPrice), pct_fmt($maxPct)
                    ));
                }
            }
            $unitPrice  = $override;
            $lineTotal  = money_round($override * $qty);
            $discount   = money_round(max(0.0, ($listPrice * $qty) - $lineTotal));
            $promoId    = null;
            $overridden = true;
        }

        // A manual discount on top of the promoted price, within the cashier's
        // ceiling unless a supervisor authorised more.
        $manualDiscount = money_round((float) ($opts['discount'] ?? 0));
        if ($manualDiscount > 0) {
            if ($manualDiscount > $lineTotal) {
                throw new PosError('The discount cannot exceed the line value.');
            }
            $pct = $lineTotal > 0 ? ($manualDiscount / $lineTotal) * 100 : 100;
            $cap = setting_num('discount_max_pct', (float) DISCOUNT_MAX_PCT);
            if ($pct > $cap && empty($opts['overridden_by'])) {
                throw new PosError(sprintf('A discount of %s needs a supervisor authorisation (your limit is %s).',
                    pct_fmt($pct), pct_fmt($cap)));
            }
            $lineTotal = money_round($lineTotal - $manualDiscount);
            $discount  = money_round($discount + $manualDiscount);
            $unitPrice = $qty > 0 ? money_round($lineTotal / $qty) : 0.0;
        }

        $vatRate   = (float) $product['vat_rate'];
        $vatAmount = money_round($lineTotal * $vatRate / (1 + $vatRate));

        $lineId = db_insert('sale_lines', [
            'sale_id'          => $saleId,
            'product_id'       => $productId,
            'description'      => substr((string) $product['name'], 0, 160),
            'qty'              => $qty,
            'unit_price'       => $unitPrice,
            'list_price'       => $listPrice,
            'unit_cost'        => stock_avg_cost($productId, $storeId),
            'line_discount'    => $discount,
            'discount_reason'  => $opts['discount_reason'] ?? null,
            'promotion_id'     => $promoId,
            'vat_rate'         => $vatRate,
            'vat_amount'       => $vatAmount,
            'line_total'       => $lineTotal,
            'price_overridden' => $overridden ? 1 : 0,
            'overridden_by'    => $opts['overridden_by'] ?? null,
        ]);

        stock_reserve($productId, $storeId, $qty);
        sale_recalculate($saleId);

        if ($overridden) {
            audit_log('sale.price_override', 'sale', $saleId,
                sprintf('%s sold at %s instead of %s on %s (authorised by user #%s)',
                    $product['name'], money($unitPrice), money($listPrice),
                    $sale['receipt_no'], (string) $opts['overridden_by']),
                ['list_price' => $listPrice], ['charged' => $unitPrice], 'warning', $storeId);
        }

        return ['line_id' => $lineId, 'line_total' => $lineTotal, 'promo' => $priced['promo_label']];
    });
}

/** Remove a line from a parked basket and give the reservation back. */
function sale_remove_line(int $saleId, int $lineId): void
{
    db_tx(function () use ($saleId, $lineId): void {
        $sale = db_lock_row('sales', 'id = :id', ['id' => $saleId]);
        if (!$sale || $sale['status'] !== 'parked') {
            throw new PosError('Only a basket that has not been paid for can be changed.');
        }
        $line = q1('SELECT * FROM sale_lines WHERE id = ? AND sale_id = ?', [$lineId, $saleId]);
        if (!$line) {
            throw new PosError('That line is not on this basket.');
        }
        stock_release((int) $line['product_id'], (int) $sale['store_id'], (float) $line['qty']);
        q('DELETE FROM sale_lines WHERE id = ?', [$lineId]);
        sale_recalculate($saleId);
    });
}

/** Re-total a sale from its lines. The only place sale totals are written. */
function sale_recalculate(int $saleId): array
{
    $row = q1(
        'SELECT COUNT(*) AS line_rows,
                COALESCE(SUM(line_total), 0) AS gross,
                COALESCE(SUM(vat_amount), 0) AS vat,
                COALESCE(SUM(line_discount), 0) AS discount,
                COALESCE(SUM(qty * unit_cost), 0) AS cost
           FROM sale_lines WHERE sale_id = ?',
        [$saleId]
    );
    $gross = money_round((float) $row['gross']);
    $vat   = money_round((float) $row['vat']);

    $totals = [
        'subtotal'       => money_round($gross - $vat),
        'vat_total'      => $vat,
        'total'          => $gross,
        'discount_total' => money_round((float) $row['discount']),
        'cost_total'     => money_round((float) $row['cost']),
        'line_count'     => (int) $row['line_rows'],
    ];
    db_update('sales', $totals, 'id = :id', ['id' => $saleId]);
    return $totals;
}

/* ================================================================== */
/* COMPLETING A SALE                                                  */
/* ================================================================== */

/**
 * Take payment and close the sale.
 *
 * This is the most important routine in the application, so everything it has
 * to get right happens inside one transaction: the stock comes out of the right
 * batches, the payments cover the total, loyalty points move, and the sale flips
 * to completed. If any part fails, none of it happened - a sale that took money
 * without releasing stock, or released stock without taking money, is the one
 * outcome that cannot be cleaned up afterwards.
 *
 * $payments: [['method' => 'cash', 'amount' => 1000, 'tendered' => 2000,
 *              'reference' => null], ...]
 */
function sale_complete(int $saleId, array $payments, array $opts = []): array
{
    return db_tx(function () use ($saleId, $payments, $opts): array {
        $sale = db_lock_row('sales', 'id = :id', ['id' => $saleId]);
        if (!$sale) {
            throw new PosError('That basket no longer exists.');
        }
        if ($sale['status'] !== 'parked') {
            throw new PosError('That sale has already been ' . $sale['status'] . '.');
        }

        $lines = qa('SELECT * FROM sale_lines WHERE sale_id = ? ORDER BY id', [$saleId]);
        if (!$lines) {
            throw new PosError('There is nothing in the basket.');
        }

        $totals  = sale_recalculate($saleId);
        $total   = (float) $totals['total'];
        $storeId = (int) $sale['store_id'];

        // ---- Payments ------------------------------------------------
        $paid       = 0.0;
        $cashTender = 0.0;
        $clean      = [];
        $methods    = ['cash', 'card', 'transfer', 'mobile', 'voucher', 'points', 'credit'];

        foreach ($payments as $p) {
            $method = one_of($p['method'] ?? '', $methods, 'cash');
            $amount = money_round((float) ($p['amount'] ?? 0));
            if ($amount <= 0) {
                continue;
            }
            $clean[] = [
                'method'    => $method,
                'amount'    => $amount,
                'tendered'  => isset($p['tendered']) ? money_round((float) $p['tendered']) : null,
                'reference' => isset($p['reference']) && $p['reference'] !== '' ? substr((string) $p['reference'], 0, 60) : null,
            ];
            $paid += $amount;
            if ($method === 'cash') {
                $cashTender += (float) ($p['tendered'] ?? $amount);
            }
        }
        if (!$clean) {
            throw new PosError('Record how the customer paid.');
        }
        $paid = money_round($paid);

        // Underpayment is refused outright. Overpayment is only meaningful in
        // cash, where the difference is change; an overpaid card is a mistake.
        if ($paid + 0.005 < $total) {
            throw new PosError(sprintf('Payment of %s does not cover the total of %s.', money($paid), money($total)));
        }
        $change = money_round($paid - $total);
        if ($change > 0.005) {
            $hasCash = false;
            foreach ($clean as $c) {
                if ($c['method'] === 'cash') {
                    $hasCash = true;
                    break;
                }
            }
            if (!$hasCash) {
                throw new PosError(sprintf('Payment of %s exceeds the total of %s and no cash was tendered, '
                    . 'so there is nothing to give change from.', money($paid), money($total)));
            }
            // Cash settles only the balance; the rest is handed back as change,
            // so the drawer figure stays right.
            foreach ($clean as &$c) {
                if ($c['method'] === 'cash') {
                    $c['tendered'] = max((float) ($c['tendered'] ?? $c['amount']), (float) $c['amount']);
                    $c['amount']   = money_round((float) $c['amount'] - $change);
                    $change        = 0.0;
                    break;
                }
            }
            unset($c);
            $change = money_round($paid - $total);
        }

        // ---- Validate the non-cash tenders before moving any stock ----
        $customerId = $sale['customer_id'] !== null ? (int) $sale['customer_id'] : null;
        $pointsUsed = 0.0;

        foreach ($clean as $c) {
            if ($c['method'] === 'points') {
                if ($customerId === null) {
                    throw new PosError('Points can only be redeemed against a loyalty account.');
                }
                $pointValue = setting_num('loyalty_point_value', (float) LOYALTY_POINT_VALUE);
                $needed     = $pointValue > 0 ? $c['amount'] / $pointValue : 0.0;
                $balance    = (float) qv('SELECT points_balance FROM customers WHERE id = ? FOR UPDATE',
                    [$customerId], 0.0);
                if ($needed > $balance + 0.005) {
                    throw new PosError(sprintf('That account has %s points, worth %s.',
                        number_format($balance, 2), money($balance * $pointValue)));
                }
                $pointsUsed += $needed;
            }
            if ($c['method'] === 'voucher') {
                if ($c['reference'] === null) {
                    throw new PosError('Enter the voucher code.');
                }
                $v = db_lock_row('vouchers', 'code = :c', ['c' => $c['reference']]);
                if (!$v || $v['status'] !== 'active') {
                    throw new PosError('That voucher is not valid.');
                }
                if ($v['expires_at'] !== null && strtotime((string) $v['expires_at']) < strtotime(date('Y-m-d'))) {
                    throw new PosError('That voucher expired on ' . date_fmt((string) $v['expires_at']) . '.');
                }
                if ((float) $v['balance'] + 0.005 < $c['amount']) {
                    throw new PosError('That voucher only has ' . money((float) $v['balance']) . ' left on it.');
                }
            }
            if ($c['method'] === 'credit') {
                if ($customerId === null) {
                    throw new PosError('An account sale needs a customer.');
                }
                $cust = db_lock_row('customers', 'id = :id', ['id' => $customerId]);
                $newBalance = (float) $cust['balance'] + $c['amount'];
                if ($newBalance > (float) $cust['credit_limit'] + 0.005) {
                    throw new PosError(sprintf('That would take the account to %s against a limit of %s.',
                        money($newBalance), money((float) $cust['credit_limit'])));
                }
            }
        }

        // ---- Stock ---------------------------------------------------
        // Release the basket's reservation first, then issue for real; releasing
        // afterwards would make the product briefly look doubly committed and
        // could refuse a concurrent sale that should have succeeded.
        $costTotal = 0.0;
        foreach ($lines as $l) {
            $productId = (int) $l['product_id'];
            $qty       = (float) $l['qty'];

            stock_release($productId, $storeId, $qty);

            $issued = stock_issue([
                'product_id'    => $productId,
                'store_id'      => $storeId,
                'qty'           => $qty,
                'movement_type' => 'sale',
                'ref_type'      => 'sale',
                'ref_id'        => $saleId,
                'ref_no'        => (string) $sale['receipt_no'],
                'note'          => 'Sold at till',
            ]);

            // Write back the cost actually consumed, so margin reporting uses the
            // real cost of these units rather than today's average.
            q('UPDATE sale_lines SET unit_cost = ?, batch_id = ? WHERE id = ?',
              [round((float) $issued['unit_cost'], 4), $issued['batch_id'], (int) $l['id']]);
            $costTotal += $qty * (float) $issued['unit_cost'];
        }

        // ---- Record the payments -------------------------------------
        foreach ($clean as $c) {
            db_insert('sale_payments', [
                'sale_id'   => $saleId,
                'method'    => $c['method'],
                'amount'    => $c['amount'],
                'tendered'  => $c['tendered'],
                'reference' => $c['reference'],
            ]);
            if ($c['method'] === 'voucher' && $c['reference'] !== null) {
                $v = q1('SELECT * FROM vouchers WHERE code = ?', [$c['reference']]);
                $left = money_round((float) $v['balance'] - $c['amount']);
                db_update('vouchers', [
                    'balance'      => $left,
                    'status'       => $left <= 0.005 ? 'redeemed' : 'active',
                    'last_used_at' => date('Y-m-d H:i:s'),
                ], 'id = :id', ['id' => (int) $v['id']]);
            }
            if ($c['method'] === 'credit' && $customerId !== null) {
                q('UPDATE customers SET balance = balance + ? WHERE id = ?', [$c['amount'], $customerId]);
            }
        }

        // ---- Loyalty -------------------------------------------------
        $pointsEarned = 0.0;
        if ($customerId !== null) {
            if ($pointsUsed > 0) {
                loyalty_move($customerId, -$pointsUsed, 'redeem', $saleId,
                    'Redeemed against receipt ' . $sale['receipt_no']);
            }
            // Earned on what was actually paid for, not on redeemed value, so
            // points cannot be farmed by cycling them.
            $per = setting_num('loyalty_points_per_unit', (float) LOYALTY_POINTS_PER_UNIT);
            if ($per > 0) {
                $spendForPoints = max(0.0, $total - money_round($pointsUsed * setting_num('loyalty_point_value', (float) LOYALTY_POINT_VALUE)));
                $pointsEarned   = floor($spendForPoints / $per);
                if ($pointsEarned > 0) {
                    loyalty_move($customerId, $pointsEarned, 'earn', $saleId,
                        'Earned on receipt ' . $sale['receipt_no']);
                }
            }
        }

        // ---- Close the sale ------------------------------------------
        db_update('sales', [
            'status'          => 'completed',
            'sold_at'         => date('Y-m-d H:i:s'),
            'paid_total'      => $paid,
            'change_given'    => $change,
            'cost_total'      => money_round($costTotal),
            'points_earned'   => $pointsEarned,
            'points_redeemed' => round($pointsUsed, 2),
            'note'            => isset($opts['note']) && $opts['note'] !== '' ? substr((string) $opts['note'], 0, 255) : $sale['note'],
        ], 'id = :id', ['id' => $saleId]);

        audit_log('sale.complete', 'sale', $saleId,
            sprintf('Receipt %s: %d line(s), %s', $sale['receipt_no'], count($lines), money($total)),
            null, ['total' => $total, 'paid' => $paid, 'change' => $change], 'info', $storeId);

        // A sale whose cost exceeds what was charged is either a pricing error or
        // a favour to someone. Either way it should not pass unseen.
        $net = (float) $totals['subtotal'];
        if ($net > 0 && $costTotal > $net) {
            audit_raise_exception('SALE_BELOW_COST', [
                'store_id'     => $storeId,
                'subject_user' => (int) $sale['cashier_id'],
                'entity'       => 'sale',
                'entity_id'    => $saleId,
                'metric'       => money_round($costTotal - $net),
                'summary'      => sprintf('Receipt %s sold at %s net against a cost of %s',
                    $sale['receipt_no'], money($net), money($costTotal)),
            ]);
        }

        return [
            'sale_id'    => $saleId,
            'receipt_no' => (string) $sale['receipt_no'],
            'total'      => $total,
            'paid'       => $paid,
            'change'     => $change,
            'points'     => $pointsEarned,
        ];
    });
}

/**
 * Void a sale.
 *
 * A parked basket is voided before payment: nothing was taken, so the
 * reservation is simply given back. A completed sale cannot be voided - the
 * money has changed hands and the stock has gone - so that route is a refund,
 * which leaves both documents in place. Deleting a completed sale would be the
 * easiest possible way to steal from a till, so it is not implemented at all.
 */
function sale_void(int $saleId, string $reasonCode, string $note, int $userId): void
{
    db_tx(function () use ($saleId, $reasonCode, $note, $userId): void {
        $sale = db_lock_row('sales', 'id = :id', ['id' => $saleId]);
        if (!$sale) {
            throw new PosError('That sale no longer exists.');
        }
        if ($sale['status'] === 'completed') {
            throw new PosError('A completed sale cannot be voided. Raise a refund against it instead.');
        }
        if ($sale['status'] !== 'parked') {
            throw new PosError('That sale is already ' . $sale['status'] . '.');
        }

        $storeId = (int) $sale['store_id'];
        foreach (qa('SELECT * FROM sale_lines WHERE sale_id = ?', [$saleId]) as $l) {
            stock_release((int) $l['product_id'], $storeId, (float) $l['qty']);
        }

        db_update('sales', [
            'status'      => 'voided',
            'void_reason' => substr($reasonCode, 0, 20),
            'void_note'   => substr($note, 0, 255),
            'voided_by'   => $userId,
            'voided_at'   => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $saleId]);

        audit_log('sale.void', 'sale', $saleId,
            sprintf('Receipt %s voided (%s): %s', $sale['receipt_no'], $reasonCode, $note),
            ['total' => (float) $sale['total']], null, 'warning', $storeId);
    });
}

/* ================================================================== */
/* REFUNDS                                                            */
/* ================================================================== */

/**
 * Raise a refund against a completed sale.
 *
 * Always against an original receipt, and never for more than that receipt sold
 * and has not already had back - a refund with no sale behind it is the classic
 * way cash leaves a till, and the per-line qty_refunded check is what makes it
 * impossible here rather than merely discouraged.
 *
 * Above the approval limit it waits for a supervisor who is not the person who
 * raised it.
 *
 * $items: [sale_line_id => qty]
 */
function refund_create(int $saleId, array $items, string $reasonCode, string $note,
                       bool $restock, string $method, int $userId, ?int $sessionId): array
{
    return db_tx(function () use ($saleId, $items, $reasonCode, $note, $restock, $method, $userId, $sessionId): array {
        $sale = db_lock_row('sales', 'id = :id', ['id' => $saleId]);
        if (!$sale) {
            throw new PosError('That sale no longer exists.');
        }
        if (!in_array($sale['status'], ['completed', 'partly_refunded'], true)) {
            throw new PosError('Only a completed sale can be refunded; this one is ' . $sale['status'] . '.');
        }
        if (trim($reasonCode) === '') {
            throw new PosError('Choose a reason for the refund.');
        }

        $storeId  = (int) $sale['store_id'];
        $prepared = [];
        $total    = 0.0;

        foreach ($items as $lineId => $qty) {
            $qty = qty_round((float) $qty);
            if ($qty <= 0) {
                continue;
            }
            $line = db_lock_row('sale_lines', 'id = :id', ['id' => (int) $lineId]);
            if (!$line || (int) $line['sale_id'] !== $saleId) {
                throw new PosError('One of those lines is not on this receipt.');
            }
            $refundable = (float) $line['qty'] - (float) $line['qty_refunded'];
            if ($qty > $refundable + 0.0005) {
                throw new PosError(sprintf('%s: only %s of %s can still be refunded.',
                    $line['description'], qty_fmt($refundable), qty_fmt((float) $line['qty'])));
            }
            // Refund at the price actually charged, not today's shelf price, or a
            // promotion ending would turn returns into a profit centre.
            $unitPrice = (float) $line['qty'] > 0
                ? money_round((float) $line['line_total'] / (float) $line['qty'])
                : 0.0;
            $lineTotal = money_round($unitPrice * $qty);
            $vatRate   = (float) $line['vat_rate'];

            $prepared[] = [
                'sale_line_id' => (int) $line['id'],
                'product_id'   => (int) $line['product_id'],
                'qty'          => $qty,
                'unit_price'   => $unitPrice,
                'unit_cost'    => (float) $line['unit_cost'],
                'vat_amount'   => money_round($lineTotal * $vatRate / (1 + $vatRate)),
                'line_total'   => $lineTotal,
                'batch_id'     => $line['batch_id'] !== null ? (int) $line['batch_id'] : null,
                'restock'      => $restock ? 1 : 0,
            ];
            $total += $lineTotal;
        }

        if (!$prepared) {
            throw new PosError('Choose at least one item and quantity to refund.');
        }
        $total = money_round($total);

        $limit         = setting_num('refund_approval_limit', (float) REFUND_APPROVAL_LIMIT);
        $needsApproval = $total > $limit || !can('refund.approve');

        $refundNo = next_number('refund', $storeId);
        $refundId = db_insert('refunds', [
            'refund_no'    => $refundNo,
            'sale_id'      => $saleId,
            'store_id'     => $storeId,
            'session_id'   => $sessionId,
            'cashier_id'   => (int) $sale['cashier_id'],
            'reason_code'  => substr($reasonCode, 0, 20),
            'note'         => substr($note, 0, 255),
            'total'        => $total,
            'method'       => one_of($method, ['cash', 'card', 'transfer', 'mobile', 'voucher', 'credit'], 'cash'),
            'restock'      => $restock ? 1 : 0,
            'status'       => $needsApproval ? 'pending' : 'approved',
            'requested_by' => $userId,
            'approved_by'  => $needsApproval ? null : $userId,
            'approved_at'  => $needsApproval ? null : date('Y-m-d H:i:s'),
            // Posting straight through means the raiser held refund.approve and
            // the value was under the limit, so policy asked for no second
            // person. Recorded as such, rather than left looking like a breach
            // of a control that was never engaged.
            'self_approved' => $needsApproval ? 0 : 1,
        ]);

        foreach ($prepared as $p) {
            db_insert('refund_lines', [
                'refund_id'    => $refundId,
                'sale_line_id' => $p['sale_line_id'],
                'product_id'   => $p['product_id'],
                'qty'          => $p['qty'],
                'unit_price'   => $p['unit_price'],
                'unit_cost'    => $p['unit_cost'],
                'vat_amount'   => $p['vat_amount'],
                'line_total'   => $p['line_total'],
                'restock'      => $p['restock'],
            ]);
        }

        audit_log('refund.raise', 'refund', $refundId,
            sprintf('Refund %s raised against %s for %s (%s)',
                $refundNo, $sale['receipt_no'], money($total), $reasonCode),
            null, null, 'notice', $storeId);

        if ($needsApproval) {
            notify_role('supervisor', 'warning', 'Refund awaiting approval',
                sprintf('%s: %s against receipt %s.', $refundNo, money($total), $sale['receipt_no']),
                base_url('sales/refunds'));
            return ['id' => $refundId, 'refund_no' => $refundNo, 'total' => $total, 'posted' => false];
        }

        refund_post_internal($refundId, $userId);
        return ['id' => $refundId, 'refund_no' => $refundNo, 'total' => $total, 'posted' => true];
    });
}

/**
 * Approve a pending refund. Not the person who raised it.
 *
 * A cash refund approved after its original till closed is the awkward case. The
 * money has to leave some drawer, and it cannot be the one that was counted and
 * signed off an hour ago - crediting it there would reopen a settled variance
 * and make the cashier answerable for cash they never handled. So the refund is
 * re-attached to the approver's own open session, which is where the notes
 * actually come from. With no till open there is nowhere honest to take it from,
 * and the approval is refused rather than fudged.
 */
function refund_approve(int $refundId, int $approverId): void
{
    db_tx(function () use ($refundId, $approverId): void {
        $r = db_lock_row('refunds', 'id = :id', ['id' => $refundId]);
        if (!$r) {
            throw new PosError('That refund no longer exists.');
        }
        if ($r['status'] !== 'pending') {
            throw new PosError('This refund is already ' . $r['status'] . '.');
        }
        sod_assert_different($approverId, (int) $r['requested_by'], 'approve this refund');

        $update = [
            'status'      => 'approved',
            'approved_by' => $approverId,
            'approved_at' => date('Y-m-d H:i:s'),
        ];

        if ($r['method'] === 'cash') {
            $sessionOpen = $r['session_id'] !== null && (string) qv(
                'SELECT status FROM till_sessions WHERE id = ?', [(int) $r['session_id']], ''
            ) === 'open';

            if (!$sessionOpen) {
                $mine = session_current($approverId);
                if ($mine === null) {
                    throw new PosError(
                        'The till this refund was raised on has been closed, so the cash cannot come '
                        . 'out of it. Open your own till first, or approve the refund as a card or '
                        . 'transfer repayment.'
                    );
                }
                $update['session_id'] = (int) $mine['id'];
                audit_log('refund.reassign', 'refund', $refundId,
                    sprintf('Refund %s re-attached to till %s: the original session was closed',
                        $r['refund_no'], $mine['session_no']),
                    ['session_id' => $r['session_id']], ['session_id' => (int) $mine['id']],
                    'notice', (int) $r['store_id']);
            }
        }

        db_update('refunds', $update, 'id = :id', ['id' => $refundId]);
        refund_post_internal($refundId, $approverId);
    });
}

function refund_reject(int $refundId, int $approverId, string $note): void
{
    db_tx(function () use ($refundId, $approverId, $note): void {
        $r = db_lock_row('refunds', 'id = :id', ['id' => $refundId]);
        if (!$r || $r['status'] !== 'pending') {
            throw new PosError('Only a pending refund can be rejected.');
        }
        sod_assert_different($approverId, (int) $r['requested_by'], 'reject this refund');
        db_update('refunds', [
            'status'        => 'rejected',
            'approved_by'   => $approverId,
            'approved_at'   => date('Y-m-d H:i:s'),
            'rejected_note' => substr($note, 0, 255),
        ], 'id = :id', ['id' => $refundId]);

        audit_log('refund.reject', 'refund', $refundId,
            'Refund ' . $r['refund_no'] . ' rejected: ' . $note, null, null, 'warning', (int) $r['store_id']);
    });
}

/** Give the money back and put the stock back. Runs inside an open transaction. */
function refund_post_internal(int $refundId, int $userId): void
{
    $r = db_lock_row('refunds', 'id = :id', ['id' => $refundId]);
    if (!$r || $r['status'] !== 'approved') {
        throw new PosError('Only an approved refund can be posted.');
    }
    $storeId = (int) $r['store_id'];
    $lines   = qa('SELECT * FROM refund_lines WHERE refund_id = ?', [$refundId]);

    foreach ($lines as $l) {
        q('UPDATE sale_lines SET qty_refunded = qty_refunded + ? WHERE id = ?',
          [(float) $l['qty'], (int) $l['sale_line_id']]);

        // Spoiled or damaged returns come back as money only; putting them on the
        // shelf would be the whole point of the restock flag being optional.
        if ((int) $l['restock'] === 1) {
            $saleLine = q1('SELECT batch_id FROM sale_lines WHERE id = ?', [(int) $l['sale_line_id']]);
            $batchId  = $saleLine['batch_id'] ?? null;
            if ($batchId !== null) {
                // Back into the batch it came from, so expiry tracking stays true.
                stock_return_to_batch((int) $batchId, (float) $l['qty']);
                stock_post([
                    'product_id'    => (int) $l['product_id'],
                    'store_id'      => $storeId,
                    'movement_type' => 'refund_in',
                    'qty_delta'     => (float) $l['qty'],
                    'unit_cost'     => (float) $l['unit_cost'],
                    'batch_id'      => (int) $batchId,
                    'ref_type'      => 'refund',
                    'ref_id'        => $refundId,
                    'ref_no'        => (string) $r['refund_no'],
                    'reason_code'   => (string) $r['reason_code'],
                    'note'          => 'Customer return, restocked',
                ]);
            } else {
                stock_post([
                    'product_id'    => (int) $l['product_id'],
                    'store_id'      => $storeId,
                    'movement_type' => 'refund_in',
                    'qty_delta'     => (float) $l['qty'],
                    'unit_cost'     => (float) $l['unit_cost'],
                    'ref_type'      => 'refund',
                    'ref_id'        => $refundId,
                    'ref_no'        => (string) $r['refund_no'],
                    'reason_code'   => (string) $r['reason_code'],
                    'note'          => 'Customer return, restocked',
                ]);
            }
        }
    }

    // Account refunds reduce what the customer owes rather than handing out cash.
    $sale = q1('SELECT * FROM sales WHERE id = ?', [(int) $r['sale_id']]);
    if ($r['method'] === 'credit' && $sale['customer_id'] !== null) {
        q('UPDATE customers SET balance = GREATEST(0, balance - ?) WHERE id = ?',
          [(float) $r['total'], (int) $sale['customer_id']]);
    }

    // Claw back the points the original sale earned on the refunded value, or a
    // buy-and-return cycle mints points for nothing.
    if ($sale['customer_id'] !== null && (float) $sale['points_earned'] > 0 && (float) $sale['total'] > 0) {
        $share = (float) $r['total'] / (float) $sale['total'];
        $claw  = floor((float) $sale['points_earned'] * $share);
        if ($claw > 0) {
            loyalty_move((int) $sale['customer_id'], -$claw, 'adjust', (int) $sale['id'],
                'Reversed on refund ' . $r['refund_no']);
        }
    }

    db_update('refunds', ['status' => 'posted', 'posted_at' => date('Y-m-d H:i:s')],
        'id = :id', ['id' => $refundId]);

    $refundedTotal = (float) qv('SELECT COALESCE(SUM(total),0) FROM refunds
                                  WHERE sale_id = ? AND status = \'posted\'',
                                [(int) $r['sale_id']], 0.0);
    db_update('sales', [
        'refunded_total' => money_round($refundedTotal),
        'status'         => $refundedTotal + 0.005 >= (float) $sale['total'] ? 'refunded' : 'partly_refunded',
    ], 'id = :id', ['id' => (int) $r['sale_id']]);

    audit_log('refund.post', 'refund', $refundId,
        sprintf('Refund %s posted: %s back to the customer by %s against %s',
            $r['refund_no'], money((float) $r['total']), $r['method'], $sale['receipt_no']),
        null, null, 'warning', $storeId);
}

/* ================================================================== */
/* CASH DRAWER                                                        */
/* ================================================================== */

/**
 * Record money in or out of the drawer outside a sale.
 *
 * A pay-out or a drop needs a supervisor's authorisation, because they are the
 * two movements that legitimately take cash out of a till, and therefore the two
 * that an unsupervised cashier would reach for. A no_sale row carries no amount
 * and exists purely so opening the drawer leaves a trace.
 */
function cash_movement_add(int $sessionId, string $type, float $amount, string $reasonCode,
                           string $note, int $userId, ?int $authorisedBy = null): int
{
    return db_tx(function () use ($sessionId, $type, $amount, $reasonCode, $note, $userId, $authorisedBy): int {
        $s = q1('SELECT * FROM till_sessions WHERE id = ?', [$sessionId]);
        if (!$s) {
            throw new PosError('That till session does not exist.');
        }
        if ($s['status'] !== 'open') {
            throw new PosError('The till session is closed; no further cash movements can be recorded on it.');
        }
        $type   = one_of($type, ['float_in', 'pay_in', 'pay_out', 'drop', 'no_sale'], 'pay_in');
        $amount = $type === 'no_sale' ? 0.0 : money_round(abs($amount));

        if ($type !== 'no_sale' && $amount <= 0) {
            throw new PosError('Enter an amount greater than zero.');
        }
        if (in_array($type, ['pay_out', 'drop'], true) && $authorisedBy === null) {
            throw new PosError('A ' . ($type === 'drop' ? 'safe drop' : 'pay-out')
                . ' needs a supervisor authorisation.');
        }
        if ($type === 'pay_out' || $type === 'drop') {
            $cash = session_expected_cash($sessionId);
            if ($amount > (float) $cash['expected'] + 0.005) {
                throw new PosError(sprintf('The drawer only holds %s.', money((float) $cash['expected'])));
            }
        }

        $id = db_insert('cash_movements', [
            'session_id'    => $sessionId,
            'store_id'      => (int) $s['store_id'],
            'type'          => $type,
            'amount'        => $amount,
            'reason_code'   => $reasonCode !== '' ? substr($reasonCode, 0, 20) : null,
            'note'          => substr($note, 0, 255),
            'user_id'       => $userId,
            'authorised_by' => $authorisedBy,
        ]);

        audit_log('cash.' . $type, 'cash_movement', $id,
            sprintf('%s of %s on till %s%s',
                ucfirst(str_replace('_', ' ', $type)), money($amount), $s['session_no'],
                $note !== '' ? ': ' . $note : ''),
            null, null, $type === 'no_sale' ? 'info' : 'notice', (int) $s['store_id']);

        return $id;
    });
}

/* ================================================================== */
/* LOYALTY                                                            */
/* ================================================================== */

/** Move points and keep the running balance on the ledger row. */
function loyalty_move(int $customerId, float $delta, string $kind, ?int $saleId, string $note): void
{
    $cust = db_lock_row('customers', 'id = :id', ['id' => $customerId]);
    if (!$cust) {
        throw new PosError('That loyalty account no longer exists.');
    }
    $balance = round((float) $cust['points_balance'] + $delta, 2);
    if ($balance < 0) {
        throw new PosError('That would take the points balance below zero.');
    }
    db_update('customers', ['points_balance' => $balance], 'id = :id', ['id' => $customerId]);
    db_insert('loyalty_ledger', [
        'customer_id'   => $customerId,
        'sale_id'       => $saleId,
        'kind'          => one_of($kind, ['earn', 'redeem', 'adjust', 'expire'], 'adjust'),
        'points_delta'  => round($delta, 2),
        'balance_after' => $balance,
        'note'          => substr($note, 0, 160),
        'user_id'       => (int) ($_SESSION['uid'] ?? 0) ?: null,
    ]);
}
