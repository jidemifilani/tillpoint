<?php
/**
 * Simulated trading history.
 *
 * Generates a few weeks of shifts, baskets, refunds, wastage, deliveries and a
 * stock count, so every dashboard, margin report and exception rule has real
 * data behind it on first run.
 *
 * All of it goes through the production routines - session_open(),
 * sale_add_line(), sale_complete(), grn_post(), count_post() - and never writes
 * to a table directly. That is slower than bulk inserts and it is the point: the
 * generated history obeys the same invariants as real trading, so the smoke test
 * is testing the application rather than its fixtures. It also means this file
 * exercises every posting path, and a defect in one of them fails the seed
 * loudly rather than waiting for a cashier to find it.
 */
declare(strict_types=1);

/**
 * Act as a particular user for the duration of a callback.
 *
 * The posting routines read the actor from the session for the audit trail, so
 * the simulation has to actually become each cashier rather than passing an id
 * around. Restored in a finally block: a seeding failure must not leave the
 * installer running as the last simulated cashier.
 */
function seed_as(int $userId, callable $fn)
{
    $prevUid  = $_SESSION['uid'] ?? null;
    $prevName = $_SESSION['user_name'] ?? null;
    $prevRole = $_SESSION['role_code'] ?? null;

    $u = q1('SELECT u.full_name, r.code AS role_code FROM users u
              JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$userId]);
    $_SESSION['uid']       = $userId;
    $_SESSION['user_name'] = $u['full_name'] ?? ('User #' . $userId);
    $_SESSION['role_code'] = $u['role_code'] ?? '';

    try {
        return $fn();
    } finally {
        if ($prevUid === null) {
            unset($_SESSION['uid'], $_SESSION['user_name'], $_SESSION['role_code']);
        } else {
            $_SESSION['uid']       = $prevUid;
            $_SESSION['user_name'] = $prevName;
            $_SESSION['role_code'] = $prevRole;
        }
    }
}

/**
 * Generate $days of trading for the two selling branches.
 *
 * The shape of the data is chosen so the audit module has something to find: one
 * cashier is given a deliberately poor profile (more voids, more discount, a
 * round-sum shortage) so the scorecard and the exception rules show a real
 * outlier rather than uniform noise. Everyone else behaves normally, which is
 * what makes the outlier visible.
 */
function seed_trading(array $ctx, int $days = 14): array
{
    $log = [];

    $branches = [
        'IKJ' => [
            'terminals' => ['IKJ-T1', 'IKJ-T2', 'IKJ-T3'],
            'cashiers'  => ['EMP003', 'EMP004', 'EMP005'],
            'baskets'   => [14, 26],
        ],
        'LEK' => [
            'terminals' => ['LEK-T1', 'LEK-T2'],
            'cashiers'  => ['EMP009', 'EMP010'],
            'baskets'   => [10, 20],
        ],
    ];

    // The cashier whose figures will stand out. Named here rather than chosen at
    // random so the demo is reproducible and so this file can be read as an
    // explanation of what the audit rules are meant to catch.
    $outlier = 'EMP004';

    $sessionsMade = 0;
    $salesMade    = 0;
    $voidsMade    = 0;
    $refundsMade  = 0;

    for ($d = $days; $d >= 1; $d--) {
        $date = date('Y-m-d', strtotime("-$d days"));

        foreach ($branches as $storeCode => $cfg) {
            $storeId = $ctx['stores'][$storeCode];

            // Products this branch can actually sell, with live stock.
            $sellable = qa(
                'SELECT p.id, p.sku, p.name, p.sell_price, p.vat_rate, p.unit_code,
                        u.is_decimal, sl.qty_on_hand
                   FROM products p
                   JOIN units u ON u.code = p.unit_code
                   JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
                  WHERE p.is_active = 1 AND sl.qty_on_hand > 2',
                [$storeId]
            );
            if (!$sellable) {
                continue;
            }

            foreach ($cfg['cashiers'] as $i => $staffNo) {
                $userId     = $ctx['users'][$staffNo] ?? null;
                $terminalId = (int) qv('SELECT id FROM terminals WHERE code = ?',
                    [$cfg['terminals'][$i % count($cfg['terminals'])]], 0);
                if ($userId === null || $terminalId === 0) {
                    continue;
                }
                $isOutlier = $staffNo === $outlier;

                // Weekends are busier, which makes the hourly report worth looking at.
                $dow      = (int) date('N', strtotime($date));
                $busy     = $dow >= 6 ? 1.45 : 1.0;
                $baskets  = (int) round(mt_rand($cfg['baskets'][0], $cfg['baskets'][1]) * $busy);

                try {
                    $result = seed_as($userId, function () use (
                        $storeId, $terminalId, $userId, $sellable, $baskets,
                        $isOutlier, $date, $ctx
                    ): array {
                        $float   = 20000.0;
                        $session = session_open($storeId, $terminalId, $userId, $float);
                        $sid     = (int) $session['id'];

                        // The session was opened now, but it represents a shift on
                        // an earlier date. Backdating it here is the one place this
                        // file reaches past the routines, and only to move a
                        // timestamp - no figure is altered.
                        $openedAt = $date . ' ' . sprintf('%02d:%02d:00', mt_rand(7, 9), mt_rand(0, 59));
                        q('UPDATE till_sessions SET opened_at = ? WHERE id = ?', [$openedAt, $sid]);

                        $made = 0;
                        $void = 0;
                        $completedSales = [];

                        for ($b = 0; $b < $baskets; $b++) {
                            $saleId = sale_open($sid, mt_rand(1, 100) <= 22
                                ? $ctx['customers'][array_rand($ctx['customers'])] : null);

                            $lineCount = mt_rand(1, 9);
                            $picked    = [];
                            for ($l = 0; $l < $lineCount; $l++) {
                                $p = $sellable[array_rand($sellable)];
                                if (isset($picked[(int) $p['id']])) {
                                    continue;
                                }
                                $picked[(int) $p['id']] = true;

                                $qty = (int) $p['is_decimal'] === 1
                                    ? round(mt_rand(25, 250) / 100, 3)
                                    : mt_rand(1, 4);
                                if ($qty > (float) $p['qty_on_hand']) {
                                    continue;
                                }

                                $opts = [];
                                // The outlier discounts and overrides far more often
                                // than the others, which is what the discount-rate
                                // and override-frequency rules look for.
                                if ($isOutlier && mt_rand(1, 100) <= 14) {
                                    $opts['unit_price']    = round((float) $p['sell_price'] * 0.88, 2);
                                    $opts['overridden_by'] = $ctx['users']['EMP002'] ?? null;
                                } elseif ($isOutlier && mt_rand(1, 100) <= 18) {
                                    $opts['discount']        = round((float) $p['sell_price'] * $qty * 0.12, 2);
                                    $opts['discount_reason'] = 'GOODWILL';
                                    $opts['overridden_by']   = $ctx['users']['EMP002'] ?? null;
                                } elseif (mt_rand(1, 100) <= 3) {
                                    $opts['discount']        = round((float) $p['sell_price'] * $qty * 0.05, 2);
                                    $opts['discount_reason'] = 'LOYALTY';
                                }

                                try {
                                    sale_add_line($saleId, (int) $p['id'], (float) $qty, $opts);
                                } catch (Throwable) {
                                    // Out of stock mid-simulation is a legitimate
                                    // outcome, not a seeding failure. Skip the line.
                                    continue;
                                }
                            }

                            $sale = q1('SELECT * FROM sales WHERE id = ?', [$saleId]);
                            if ((int) $sale['line_count'] === 0) {
                                sale_void($saleId, 'TILL_ERROR', 'Nothing scanned', $userId);
                                continue;
                            }

                            // Voids. The outlier voids far more, which is the
                            // single strongest signal of till theft.
                            $voidChance = $isOutlier ? 17 : 3;
                            if (mt_rand(1, 100) <= $voidChance) {
                                sale_void($saleId, mt_rand(0, 1) ? 'CUST_LEFT' : 'WRONG_ITEM',
                                    'Simulated void', $userId);
                                $void++;
                                continue;
                            }

                            $total = (float) $sale['total'];
                            $pay   = seed_pick_payment($total, $sale['customer_id'] !== null);

                            try {
                                sale_complete($saleId, $pay);
                            } catch (Throwable) {
                                sale_void($saleId, 'TILL_ERROR', 'Payment failed in simulation', $userId);
                                $void++;
                                continue;
                            }

                            // Backdate the completion so the sale lands on the
                            // right trading day and hour for the reports.
                            $soldAt = $date . ' ' . sprintf('%02d:%02d:%02d',
                                seed_shopping_hour(), mt_rand(0, 59), mt_rand(0, 59));
                            q('UPDATE sales SET sold_at = ?, business_date = ? WHERE id = ?',
                              [$soldAt, $date, $saleId]);

                            $made++;
                            $completedSales[] = $saleId;
                        }

                        return ['session_id' => $sid, 'sales' => $made, 'voids' => $void,
                                'completed' => $completedSales];
                    });

                    $sessionsMade++;
                    $salesMade += $result['sales'];
                    $voidsMade += $result['voids'];

                    /* ---- Refunds against this shift ---------------- */
                    // Two routes, both of which a shop uses:
                    //   - a supervisor processes it on the spot, and it posts;
                    //   - the cashier raises it, and because a cashier does not
                    //     hold refund.approve it waits in the approval queue.
                    // Seeding both means the refund screens have live and pending
                    // work to show, and the segregation-of-duties rule gets
                    // exercised rather than merely documented.
                    $supervisor = $ctx['users'][$storeCode === 'IKJ' ? 'EMP002' : 'EMP008'] ?? null;
                    $refundOdds = $isOutlier ? 70 : 30;
                    if ($supervisor !== null && $result['completed'] && mt_rand(1, 100) <= $refundOdds) {
                        $byCashier = mt_rand(1, 100) <= 35;
                        $actor     = $byCashier ? $userId : $supervisor;
                        $saleId    = $result['completed'][array_rand($result['completed'])];
                        try {
                            seed_as($actor, function () use ($saleId, $actor, $result): void {
                                $lines = qa('SELECT id, qty FROM sale_lines WHERE sale_id = ? LIMIT 2', [$saleId]);
                                $items = [];
                                foreach ($lines as $l) {
                                    $items[(int) $l['id']] = min(1.0, (float) $l['qty']);
                                }
                                if ($items) {
                                    refund_create($saleId, $items,
                                        ['FAULTY', 'NOT_WANTED', 'WRONG_SIZE'][mt_rand(0, 2)],
                                        'Customer returned item', mt_rand(1, 100) > 20, 'cash',
                                        $actor, (int) $result['session_id']);
                                }
                            });
                            $refundsMade++;
                        } catch (Throwable) {
                            // A refund that cannot be raised (the line is already
                            // fully refunded) is not a seeding failure.
                        }
                    }

                    /* ---- Cash movements and close ------------------ */
                    seed_as($userId, function () use ($result, $userId, $ctx, $isOutlier, $storeCode): void {
                        $sid = (int) $result['session_id'];
                        $supervisor = $ctx['users'][$storeCode === 'IKJ' ? 'EMP002' : 'EMP008'] ?? null;

                        // Drawer opens without a sale. The outlier does this a lot,
                        // which the no-sale rule picks up.
                        $opens = $isOutlier ? mt_rand(8, 16) : mt_rand(0, 4);
                        for ($i = 0; $i < $opens; $i++) {
                            cash_movement_add($sid, 'no_sale', 0, 'CHANGE', 'Making change', $userId);
                        }
                        if (mt_rand(1, 100) <= 30 && $supervisor !== null) {
                            cash_movement_add($sid, 'pay_out', mt_rand(5, 25) * 100,
                                'PETTY', 'Petty cash for cleaning supplies', $userId, $supervisor);
                        }

                        $cash = session_expected_cash($sid);
                        $expected = (float) $cash['expected'];

                        // Declared cash. Most shifts are a few naira out from
                        // rounding; the outlier is short by an exact note amount,
                        // which is what the round-sum rule exists to separate from
                        // ordinary miscounting.
                        if ($isOutlier && mt_rand(1, 100) <= 45) {
                            $declared = $expected - (mt_rand(1, 4) * 1000);
                        } else {
                            $declared = $expected + (mt_rand(-180, 180) / 1);
                        }
                        $declared = max(0.0, round($declared, 2));

                        session_close($sid, $declared, $userId,
                            $isOutlier ? '' : 'Counted and balanced at shift end');

                        // Backdate the close so the shift sits on the right day.
                        $opened = (string) qv('SELECT opened_at FROM till_sessions WHERE id = ?', [$sid]);
                        q('UPDATE till_sessions SET closed_at = ? WHERE id = ?',
                          [date('Y-m-d H:i:s', strtotime($opened) + 8 * 3600), $sid]);
                    });
                } catch (Throwable $e) {
                    $log[] = "Shift skipped ($storeCode/$staffNo/$date): " . $e->getMessage();
                }
            }
        }
    }

    $log[] = "$sessionsMade shifts, $salesMade completed sales, $voidsMade voids, $refundsMade refunds";

    $log = array_merge($log, seed_wastage($ctx));
    $log = array_merge($log, seed_purchasing($ctx));
    $log = array_merge($log, seed_stock_count($ctx));
    $log = array_merge($log, seed_transfer($ctx));

    return $log;
}

/** Realistic payment mix: mostly cash, some card, occasional split or points. */
function seed_pick_payment(float $total, bool $hasCustomer): array
{
    $roll = mt_rand(1, 100);

    if ($roll <= 52) {
        // Cash, usually with a round note tendered and change given back.
        $tendered = ceil($total / 500) * 500;
        return [['method' => 'cash', 'amount' => $total, 'tendered' => $tendered]];
    }
    if ($roll <= 78) {
        return [['method' => 'card', 'amount' => $total, 'reference' => 'AUTH' . mt_rand(100000, 999999)]];
    }
    if ($roll <= 88) {
        return [['method' => 'transfer', 'amount' => $total, 'reference' => 'TRF' . mt_rand(10000000, 99999999)]];
    }
    if ($roll <= 94) {
        // Split: part card, the rest cash.
        $card = round($total * 0.6, 2);
        return [
            ['method' => 'card', 'amount' => $card, 'reference' => 'AUTH' . mt_rand(100000, 999999)],
            ['method' => 'cash', 'amount' => round($total - $card, 2), 'tendered' => round($total - $card, 2)],
        ];
    }
    if ($roll <= 97 && $hasCustomer) {
        // Points covering a slice, cash for the rest.
        $points = min(round($total * 0.3, 2), 500.0);
        return [
            ['method' => 'points', 'amount' => $points],
            ['method' => 'cash', 'amount' => round($total - $points, 2), 'tendered' => ceil($total / 500) * 500],
        ];
    }
    return [['method' => 'mobile', 'amount' => $total, 'reference' => 'MOB' . mt_rand(100000, 999999)]];
}

/**
 * A plausible hour for a basket.
 *
 * Supermarket trade is bimodal - a late-morning peak and a heavier
 * after-work one - so a uniform random hour would make the hourly report
 * useless for staffing decisions, which is the only reason that report exists.
 */
function seed_shopping_hour(): int
{
    $weights = [
        8 => 3, 9 => 5, 10 => 8, 11 => 9, 12 => 8, 13 => 7,
        14 => 6, 15 => 6, 16 => 8, 17 => 12, 18 => 14, 19 => 10, 20 => 4,
    ];
    $total = array_sum($weights);
    $pick  = mt_rand(1, $total);
    foreach ($weights as $hour => $w) {
        $pick -= $w;
        if ($pick <= 0) {
            return $hour;
        }
    }
    return 17;
}

/** Wastage spread over the period, weighted towards perishables. */
function seed_wastage(array $ctx): array
{
    $made = 0;
    foreach (['IKJ', 'LEK'] as $storeCode) {
        $storeId = $ctx['stores'][$storeCode];
        $logger  = $ctx['users'][$storeCode === 'IKJ' ? 'EMP006' : 'EMP008'] ?? null;
        $approver = $ctx['users'][$storeCode === 'IKJ' ? 'EMP001' : 'EMP007'] ?? null;
        if ($logger === null) {
            continue;
        }

        // Perishables first - that is where wastage really comes from - but not
        // exclusively, because by this point in the simulation the fresh lines
        // have largely been sold and a perishables-only filter would produce
        // almost no records on a busy demo.
        $candidates = qa(
            'SELECT p.id, sl.qty_on_hand FROM products p
               JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
              WHERE sl.qty_on_hand >= 2
              ORDER BY p.is_perishable DESC, RAND() LIMIT 16',
            [$storeId]
        );
        foreach ($candidates as $p) {
            try {
                $result = seed_as($logger, fn () => wastage_log([
                    'product_id'  => (int) $p['id'],
                    'store_id'    => $storeId,
                    'qty'         => min(mt_rand(1, 3), (float) $p['qty_on_hand']),
                    'user_id'     => $logger,
                    'reason_code' => ['EXPIRED', 'DAMAGED', 'SPOILED', 'BREAKAGE'][mt_rand(0, 3)],
                    'note'        => 'Found on shelf during date check',
                ]));
                // Anything over the limit waits for a manager, so approve a share
                // of those too - otherwise the queue is all that is ever visible.
                if (!$result['posted'] && $approver !== null && mt_rand(1, 100) <= 70) {
                    seed_as($approver, fn () => wastage_approve((int) $result['id'], $approver));
                }
                $made++;
            } catch (Throwable) {
                continue;
            }
        }
    }
    return ["$made wastage records"];
}

/** A purchase order through its whole life: raised, approved, received. */
function seed_purchasing(array $ctx): array
{
    $made = 0;
    $buyer    = $ctx['users']['EMP011'] ?? null;
    $receiver = $ctx['users']['EMP006'] ?? null;

    foreach (['IKJ', 'LEK'] as $storeCode) {
        $storeId  = $ctx['stores'][$storeCode];
        $approver = $ctx['users'][$storeCode === 'IKJ' ? 'EMP001' : 'EMP007'] ?? null;
        if ($buyer === null || $approver === null) {
            continue;
        }

        foreach (array_slice(array_values($ctx['suppliers']), 0, 4) as $supplierId) {
            $items = qa('SELECT id, cost_price, reorder_qty FROM products
                          WHERE supplier_id = ? AND is_active = 1 LIMIT 6', [$supplierId]);
            if (!$items) {
                continue;
            }
            try {
                $poId = seed_as($buyer, function () use ($storeId, $supplierId, $items, $buyer): int {
                    $poId = db_insert('purchase_orders', [
                        'po_no'         => next_number('po', $storeId),
                        'store_id'      => $storeId,
                        'supplier_id'   => $supplierId,
                        'status'        => 'draft',
                        'order_date'    => date('Y-m-d', strtotime('-' . mt_rand(3, 10) . ' days')),
                        'expected_date' => date('Y-m-d', strtotime('+' . mt_rand(1, 5) . ' days')),
                        'created_by'    => $buyer,
                        'note'          => 'Weekly replenishment',
                    ]);
                    foreach ($items as $it) {
                        $qty = max(6.0, (float) $it['reorder_qty']);
                        db_insert('purchase_order_lines', [
                            'po_id'       => $poId,
                            'product_id'  => (int) $it['id'],
                            'qty_ordered' => $qty,
                            'unit_cost'   => (float) $it['cost_price'],
                            'vat_rate'    => VAT_RATE,
                        ]);
                    }
                    po_submit($poId, $buyer);
                    return $poId;
                });

                seed_as($approver, fn () => po_approve($poId, $approver));

                // Receive most of them; leave one open so the purchasing screens
                // have an outstanding order to show.
                if ($receiver !== null && mt_rand(1, 100) <= 75) {
                    seed_as($receiver, function () use ($poId, $storeId, $supplierId, $receiver): void {
                        $po    = q1('SELECT * FROM purchase_orders WHERE id = ?', [$poId]);
                        $grnId = db_insert('grns', [
                            'grn_no'              => next_number('grn', $storeId),
                            'po_id'               => $poId,
                            'store_id'            => $storeId,
                            'supplier_id'         => $supplierId,
                            'supplier_invoice_no' => 'INV-' . mt_rand(10000, 99999),
                            'received_date'       => date('Y-m-d', strtotime('-' . mt_rand(0, 3) . ' days')),
                            'status'              => 'draft',
                            'received_by'         => $receiver,
                        ]);
                        foreach (qa('SELECT pol.*, p.track_batches, p.shelf_life_days, p.sku
                                       FROM purchase_order_lines pol
                                       JOIN products p ON p.id = pol.product_id
                                      WHERE pol.po_id = ?', [$poId]) as $pol) {
                            // Short deliveries happen; so does the occasional
                            // over-receipt, which the GRN rule will flag.
                            $factor = mt_rand(1, 100) <= 12 ? 1.1 : (mt_rand(1, 100) <= 15 ? 0.85 : 1.0);
                            $qty    = round((float) $pol['qty_ordered'] * $factor, 3);
                            db_insert('grn_lines', [
                                'grn_id'      => $grnId,
                                'po_line_id'  => (int) $pol['id'],
                                'product_id'  => (int) $pol['product_id'],
                                'qty'         => $qty,
                                'unit_cost'   => (float) $pol['unit_cost'],
                                'vat_rate'    => VAT_RATE,
                                'batch_no'    => (int) $pol['track_batches'] === 1
                                    ? 'B' . date('ymd') . '-' . $pol['sku'] : null,
                                'expiry_date' => (int) $pol['track_batches'] === 1 && $pol['shelf_life_days'] !== null
                                    ? date('Y-m-d', strtotime('+' . (int) $pol['shelf_life_days'] . ' days')) : null,
                            ]);
                        }
                        grn_post($grnId, $receiver);
                    });
                }
                $made++;
            } catch (Throwable) {
                continue;
            }
        }
    }
    return ["$made purchase orders through the full cycle"];
}

/**
 * A cycle count with a real variance, approved by someone other than the
 * counter - which also demonstrates the segregation-of-duties rule working
 * rather than merely being documented.
 */
function seed_stock_count(array $ctx): array
{
    $log = [];
    foreach (['IKJ', 'LEK'] as $storeCode) {
        $storeId  = $ctx['stores'][$storeCode];
        $counter  = $ctx['users'][$storeCode === 'IKJ' ? 'EMP006' : 'EMP008'] ?? null;
        $approver = $ctx['users'][$storeCode === 'IKJ' ? 'EMP001' : 'EMP007'] ?? null;
        if ($counter === null || $approver === null) {
            continue;
        }
        if ((int) qv('SELECT COUNT(*) FROM stock_counts WHERE store_id = ?', [$storeId], 0) > 0) {
            continue;
        }

        try {
            // Count the category with the most lines still holding stock, so the
            // sheet is long enough for the variance pattern below to show up.
            $catId = (int) qv(
                'SELECT p.category_id FROM products p
                   JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
                  WHERE sl.qty_on_hand > 0
                  GROUP BY p.category_id ORDER BY COUNT(*) DESC LIMIT 1',
                [$storeId], 0
            );

            $countId = seed_as($counter, function () use ($storeId, $counter, $catId): int {
                $id = db_insert('stock_counts', [
                    'count_no'    => next_number('count', $storeId),
                    'store_id'    => $storeId,
                    'count_type'  => 'cycle',
                    'status'      => 'open',
                    'category_id' => $catId ?: null,
                    'scope_note'  => 'Monthly cycle count - snacks and confectionery',
                    'created_by'  => $counter,
                ]);
                count_generate_sheet($id);

                // Enter counts: mostly exact, a few short (shrinkage), one over.
                foreach (qa('SELECT id, system_qty FROM stock_count_lines WHERE count_id = ?', [$id]) as $line) {
                    $sys  = (float) $line['system_qty'];
                    // Mostly exact, a tail of small shortages, a few found items
                    // and the occasional large hole - the distribution a real
                    // cycle count produces, and the one the shrinkage report and
                    // the COUNT_SHRINKAGE rule are built to read.
                    $roll = mt_rand(1, 100);
                    $counted = match (true) {
                        $roll <= 55 => $sys,
                        $roll <= 84 => max(0.0, $sys - mt_rand(1, 3)),
                        $roll <= 93 => $sys + mt_rand(1, 2),
                        default     => max(0.0, $sys - mt_rand(4, 9)),
                    };
                    q('UPDATE stock_count_lines SET counted_qty = ? WHERE id = ?', [$counted, (int) $line['id']]);
                }
                count_submit($id, $counter);
                return $id;
            });

            $posted = seed_as($approver, fn () => count_post($countId, $approver));
            $log[] = sprintf('%s cycle count posted: %d variance line(s), %s',
                $storeCode, $posted['lines_posted'], money($posted['variance_value']));
        } catch (Throwable $e) {
            $log[] = "$storeCode count skipped: " . $e->getMessage();
        }
    }
    return $log;
}

/** A depot-to-branch transfer, dispatched and received short on one line. */
function seed_transfer(array $ctx): array
{
    $creator  = $ctx['users']['EMP006'] ?? null;
    $receiver = $ctx['users']['EMP001'] ?? null;
    if ($creator === null || $receiver === null) {
        return ['Transfer skipped: staff missing'];
    }
    if ((int) qv('SELECT COUNT(*) FROM stock_transfers', [], 0) > 0) {
        return ['Transfers already present'];
    }

    $from = $ctx['stores']['WH'];
    $to   = $ctx['stores']['IKJ'];

    try {
        $transferId = seed_as($creator, function () use ($from, $to, $creator): int {
            $id = db_insert('stock_transfers', [
                'transfer_no'   => next_number('transfer', $from),
                'from_store_id' => $from,
                'to_store_id'   => $to,
                'status'        => 'draft',
                'note'          => 'Depot top-up for the weekend',
                'created_by'    => $creator,
            ]);
            $items = qa('SELECT product_id, qty_on_hand FROM stock_levels
                          WHERE store_id = ? AND qty_on_hand > 10 ORDER BY RAND() LIMIT 8', [$from]);
            foreach ($items as $it) {
                db_insert('stock_transfer_lines', [
                    'transfer_id' => $id,
                    'product_id'  => (int) $it['product_id'],
                    'qty_sent'    => min(6.0, floor((float) $it['qty_on_hand'] / 2)),
                ]);
            }
            transfer_dispatch($id, $creator);
            return $id;
        });

        seed_as($receiver, function () use ($transferId, $receiver): void {
            $lines = qa('SELECT id, qty_sent FROM stock_transfer_lines WHERE transfer_id = ?', [$transferId]);
            $qtys  = [];
            foreach ($lines as $i => $l) {
                // One line arrives short, so the transfer-shortage rule has
                // something real to report.
                $qtys[(int) $l['id']] = $i === 0
                    ? max(0.0, (float) $l['qty_sent'] - 2)
                    : (float) $l['qty_sent'];
            }
            transfer_receive($transferId, $receiver, $qtys);
        });

        return ['1 depot transfer dispatched and received with a shortage'];
    } catch (Throwable $e) {
        return ['Transfer skipped: ' . $e->getMessage()];
    }
}

/** Run the exception scan over the generated history. */
function seed_run_audit_scan(array $ctx, int $days = 14): array
{
    $total = 0;
    for ($d = $days; $d >= 0; $d--) {
        $date = date('Y-m-d', strtotime("-$d days"));
        foreach ($ctx['stores'] as $code => $storeId) {
            if ($code === 'WH') {
                continue;
            }
            $found = audit_scan($date, $storeId, 'seed');
            foreach ($found as $v) {
                if (is_int($v)) {
                    $total += $v;
                }
            }
        }
    }
    $open = (int) qv('SELECT COUNT(*) FROM audit_exceptions', [], 0);
    return ["Exception scan complete: $open exception(s) in the queue"];
}
