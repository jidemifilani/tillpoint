<?php
/**
 * Posting routines for the stock documents: purchase orders, goods received,
 * transfers, counts, wastage and adjustments.
 *
 * Each follows the same shape, and the shape is the control:
 *
 *   1. re-read the document inside the transaction and check its status, so a
 *      double-clicked button cannot post the same receipt twice;
 *   2. check segregation of duties where an approval is involved;
 *   3. post every stock movement through stock_post();
 *   4. flip the document status and stamp who did it;
 *   5. write the audit entry.
 *
 * All of it inside one transaction, so a document is never left half-posted
 * with stock moved and status unchanged - the state that makes a stock figure
 * impossible to trust afterwards.
 */
declare(strict_types=1);

/* ================================================================== */
/* PURCHASE ORDERS                                                    */
/* ================================================================== */

function po_recalculate(int $poId): void
{
    $rows = qa('SELECT qty_ordered, unit_cost, vat_rate FROM purchase_order_lines WHERE po_id = ?', [$poId]);
    $sub = 0.0;
    $vat = 0.0;
    foreach ($rows as $r) {
        $line = (float) $r['qty_ordered'] * (float) $r['unit_cost'];
        $sub += $line;
        $vat += $line * (float) $r['vat_rate'];
    }
    // Line totals are stored net of VAT; a PO is a commitment to the supplier at
    // cost, and the VAT is reclaimable rather than part of the stock value.
    q('UPDATE purchase_order_lines SET line_total = ROUND(qty_ordered * unit_cost, 2) WHERE po_id = ?', [$poId]);
    db_update('purchase_orders', [
        'subtotal'  => money_round($sub),
        'vat_total' => money_round($vat),
        'total'     => money_round($sub + $vat),
    ], 'id = :id', ['id' => $poId]);
}

function po_submit(int $poId, int $userId): void
{
    db_tx(function () use ($poId, $userId): void {
        $po = db_lock_row('purchase_orders', 'id = :id', ['id' => $poId]);
        if (!$po) {
            throw new StockError('That purchase order no longer exists.');
        }
        if ($po['status'] !== 'draft') {
            throw new StockError('Only a draft order can be submitted; this one is ' . $po['status'] . '.');
        }
        if ((int) qv('SELECT COUNT(*) FROM purchase_order_lines WHERE po_id = ?', [$poId], 0) === 0) {
            throw new StockError('Add at least one line before submitting the order.');
        }
        po_recalculate($poId);
        db_update('purchase_orders', [
            'status'       => 'submitted',
            'submitted_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $poId]);

        audit_log('po.submit', 'purchase_order', $poId,
            'Purchase order ' . $po['po_no'] . ' submitted for approval', null, null, 'notice');
        notify_role('manager', 'info', 'Purchase order awaiting approval',
            'Order ' . $po['po_no'] . ' is waiting for approval.',
            base_url('inventory/purchase-order', ['id' => $poId]));
    });
}

/**
 * Approve a purchase order.
 *
 * The buyer who raised it may not approve it. That is the oldest control in
 * purchasing and the reason the two permissions are separate in the first place:
 * one person who can both raise and approve an order can buy from themselves.
 */
function po_approve(int $poId, int $approverId): void
{
    db_tx(function () use ($poId, $approverId): void {
        $po = db_lock_row('purchase_orders', 'id = :id', ['id' => $poId]);
        if (!$po) {
            throw new StockError('That purchase order no longer exists.');
        }
        if ($po['status'] !== 'submitted') {
            throw new StockError('Only a submitted order can be approved; this one is ' . $po['status'] . '.');
        }
        sod_assert_different($approverId, (int) $po['created_by'], 'approve this purchase order');

        db_update('purchase_orders', [
            'status'      => 'approved',
            'approved_by' => $approverId,
            'approved_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $poId]);

        audit_log('po.approve', 'purchase_order', $poId,
            'Purchase order ' . $po['po_no'] . ' approved, ' . money((float) $po['total']),
            ['status' => $po['status']], ['status' => 'approved'], 'notice');
    });
}

function po_cancel(int $poId, int $userId, string $reason): void
{
    db_tx(function () use ($poId, $userId, $reason): void {
        $po = db_lock_row('purchase_orders', 'id = :id', ['id' => $poId]);
        if (!$po) {
            throw new StockError('That purchase order no longer exists.');
        }
        if (in_array($po['status'], ['received', 'cancelled'], true)) {
            throw new StockError('A ' . $po['status'] . ' order cannot be cancelled.');
        }
        $received = (float) qv('SELECT COALESCE(SUM(qty_received),0) FROM purchase_order_lines WHERE po_id = ?', [$poId], 0.0);
        if ($received > 0) {
            throw new StockError('Goods have already been received against this order. Close it off instead of cancelling.');
        }
        db_update('purchase_orders', [
            'status'       => 'cancelled',
            'cancelled_by' => $userId,
            'cancelled_at' => date('Y-m-d H:i:s'),
            'note'         => substr(trim(($po['note'] ?? '') . ' | Cancelled: ' . $reason), 0, 255),
        ], 'id = :id', ['id' => $poId]);

        audit_log('po.cancel', 'purchase_order', $poId,
            'Purchase order ' . $po['po_no'] . ' cancelled: ' . $reason, null, null, 'warning');
    });
}

/* ================================================================== */
/* GOODS RECEIVED                                                     */
/* ================================================================== */

function grn_recalculate(int $grnId): void
{
    q('UPDATE grn_lines SET line_total = ROUND(qty * unit_cost, 2) WHERE grn_id = ?', [$grnId]);
    $row = q1('SELECT COALESCE(SUM(line_total),0) AS sub,
                      COALESCE(SUM(line_total * vat_rate),0) AS vat
                 FROM grn_lines WHERE grn_id = ?', [$grnId]);
    $sub = (float) ($row['sub'] ?? 0);
    $vat = (float) ($row['vat'] ?? 0);
    db_update('grns', [
        'subtotal'  => money_round($sub),
        'vat_total' => money_round($vat),
        'total'     => money_round($sub + $vat),
    ], 'id = :id', ['id' => $grnId]);
}

/**
 * Post a goods receipt into stock.
 *
 * This is where stock and cost enter the system, so it is also where the two
 * commonest goods-in frauds are caught:
 *
 *   - receiving more than was ordered (over-receipt against the PO), and
 *   - receiving at a cost well above the agreed one.
 *
 * Neither is blocked outright, because a genuine substitution or a price rise
 * happens, and a receiving clerk who cannot book a delivery will book it
 * somewhere worse. Both raise an audit exception instead, so the decision is
 * visible to someone who can question it.
 */
function grn_post(int $grnId, int $userId): void
{
    db_tx(function () use ($grnId, $userId): void {
        $grn = db_lock_row('grns', 'id = :id', ['id' => $grnId]);
        if (!$grn) {
            throw new StockError('That goods receipt no longer exists.');
        }
        if ($grn['status'] !== 'draft') {
            throw new StockError('This receipt has already been ' . $grn['status'] . '.');
        }
        $lines = qa('SELECT * FROM grn_lines WHERE grn_id = ?', [$grnId]);
        if (!$lines) {
            throw new StockError('Add at least one line before posting the receipt.');
        }

        $storeId = (int) $grn['store_id'];
        grn_recalculate($grnId);

        foreach ($lines as $l) {
            $productId = (int) $l['product_id'];
            $qty       = (float) $l['qty'];
            $cost      = (float) $l['unit_cost'];
            if ($qty <= 0) {
                continue;
            }

            stock_receive([
                'product_id'  => $productId,
                'store_id'    => $storeId,
                'qty'         => $qty,
                'unit_cost'   => $cost,
                'batch_no'    => $l['batch_no'],
                'expiry_date' => $l['expiry_date'],
                'grn_id'      => $grnId,
                'ref_type'    => 'grn',
                'ref_id'      => $grnId,
                'ref_no'      => (string) $grn['grn_no'],
                'note'        => 'Goods received from supplier',
            ]);

            // Keep the PO's received quantities in step, and flag over-receipt.
            if ($l['po_line_id'] !== null) {
                $pol = db_lock_row('purchase_order_lines', 'id = :id', ['id' => (int) $l['po_line_id']]);
                if ($pol) {
                    $newReceived = (float) $pol['qty_received'] + $qty;
                    q('UPDATE purchase_order_lines SET qty_received = ? WHERE id = ?',
                      [qty_round($newReceived), (int) $pol['id']]);

                    $ordered = (float) $pol['qty_ordered'];
                    if ($newReceived > $ordered + 0.0005) {
                        $over    = $newReceived - $ordered;
                        $overPct = $ordered > 0 ? ($over / $ordered) * 100 : 100;
                        audit_raise_exception('GRN_OVER_RECEIPT', [
                            'store_id'     => $storeId,
                            'subject_user' => $userId,
                            'entity'       => 'grn',
                            'entity_id'    => $grnId,
                            'metric'       => round($overPct, 2),
                            'summary'      => sprintf(
                                'Receipt %s booked %s of %s against an order for %s (%s over)',
                                $grn['grn_no'], qty_fmt($newReceived),
                                (string) qv('SELECT name FROM products WHERE id = ?', [$productId], ''),
                                qty_fmt($ordered), qty_fmt($over)
                            ),
                            'detail'       => ['product_id' => $productId, 'ordered' => $ordered, 'received' => $newReceived],
                        ]);
                    }

                    // Cost creep against the agreed order price.
                    $agreed = (float) $pol['unit_cost'];
                    if ($agreed > 0 && $cost > $agreed * 1.05) {
                        audit_raise_exception('GRN_COST_VARIANCE', [
                            'store_id'     => $storeId,
                            'subject_user' => $userId,
                            'entity'       => 'grn',
                            'entity_id'    => $grnId,
                            'metric'       => round((($cost - $agreed) / $agreed) * 100, 2),
                            'summary'      => sprintf(
                                'Receipt %s booked %s at %s against an agreed %s',
                                $grn['grn_no'],
                                (string) qv('SELECT name FROM products WHERE id = ?', [$productId], ''),
                                money($cost), money($agreed)
                            ),
                            'detail'       => ['product_id' => $productId, 'agreed' => $agreed, 'booked' => $cost],
                        ]);
                    }
                }
            }
        }

        db_update('grns', [
            'status'    => 'posted',
            'posted_by' => $userId,
            'posted_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $grnId]);

        // Roll the parent order's status forward.
        if ($grn['po_id'] !== null) {
            po_refresh_receipt_status((int) $grn['po_id']);
        }

        audit_log('grn.post', 'grn', $grnId,
            sprintf('Goods receipt %s posted: %d line(s), %s',
                $grn['grn_no'], count($lines), money((float) $grn['total'])),
            ['status' => 'draft'], ['status' => 'posted'], 'notice', $storeId);
    });
}

function po_refresh_receipt_status(int $poId): void
{
    $row = q1('SELECT COALESCE(SUM(qty_ordered),0) AS ordered,
                      COALESCE(SUM(qty_received),0) AS received
                 FROM purchase_order_lines WHERE po_id = ?', [$poId]);
    $ordered  = (float) ($row['ordered'] ?? 0);
    $received = (float) ($row['received'] ?? 0);

    $status = match (true) {
        $received <= 0.0005            => 'approved',
        $received + 0.0005 >= $ordered => 'received',
        default                        => 'partly_received',
    };
    // Never drag a cancelled order back into life.
    $current = (string) qv('SELECT status FROM purchase_orders WHERE id = ?', [$poId], '');
    if ($current === 'cancelled') {
        return;
    }
    db_update('purchase_orders', ['status' => $status], 'id = :id', ['id' => $poId]);
}

/* ================================================================== */
/* TRANSFERS                                                          */
/* ================================================================== */

/**
 * Dispatch a transfer: stock leaves the sending store now and arrives at the
 * receiving store only when someone there confirms it. Between the two it is in
 * transit and belongs to neither sellable position, which is deliberate - that
 * gap is where stock goes missing between branches, and making it visible is
 * the point.
 */
function transfer_dispatch(int $transferId, int $userId): void
{
    db_tx(function () use ($transferId, $userId): void {
        $t = db_lock_row('stock_transfers', 'id = :id', ['id' => $transferId]);
        if (!$t) {
            throw new StockError('That transfer no longer exists.');
        }
        if ($t['status'] !== 'draft') {
            throw new StockError('Only a draft transfer can be dispatched; this one is ' . $t['status'] . '.');
        }
        $lines = qa('SELECT * FROM stock_transfer_lines WHERE transfer_id = ?', [$transferId]);
        if (!$lines) {
            throw new StockError('Add at least one line before dispatching.');
        }

        $from = (int) $t['from_store_id'];
        foreach ($lines as $l) {
            $issued = stock_issue([
                'product_id'    => (int) $l['product_id'],
                'store_id'      => $from,
                'qty'           => (float) $l['qty_sent'],
                'movement_type' => 'transfer_out',
                'ref_type'      => 'transfer',
                'ref_id'        => $transferId,
                'ref_no'        => (string) $t['transfer_no'],
                'note'          => 'Dispatched to store #' . $t['to_store_id'],
            ]);
            // Carry the dispatching store's cost with the goods, so the receiving
            // store's weighted average is not disturbed by an arbitrary figure.
            q('UPDATE stock_transfer_lines SET unit_cost = ? WHERE id = ?',
              [round((float) $issued['unit_cost'], 4), (int) $l['id']]);
        }

        db_update('stock_transfers', [
            'status'        => 'dispatched',
            'dispatched_by' => $userId,
            'dispatched_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $transferId]);

        audit_log('transfer.dispatch', 'transfer', $transferId,
            sprintf('Transfer %s dispatched: %d line(s)', $t['transfer_no'], count($lines)),
            null, null, 'notice', $from);
        notify_role('stock', 'info', 'Transfer on its way',
            'Transfer ' . $t['transfer_no'] . ' has been dispatched and is awaiting receipt.',
            base_url('inventory/transfer', ['id' => $transferId]));
    });
}

/**
 * Receive a transfer. Short receipts are expected - that is what the control is
 * for - so a shortfall is recorded on the line and raised as an exception rather
 * than refused.
 */
function transfer_receive(int $transferId, int $userId, array $receivedQtys): void
{
    db_tx(function () use ($transferId, $userId, $receivedQtys): void {
        $t = db_lock_row('stock_transfers', 'id = :id', ['id' => $transferId]);
        if (!$t) {
            throw new StockError('That transfer no longer exists.');
        }
        if ($t['status'] !== 'dispatched') {
            throw new StockError('Only a dispatched transfer can be received; this one is ' . $t['status'] . '.');
        }
        sod_assert_different($userId, $t['dispatched_by'] !== null ? (int) $t['dispatched_by'] : null,
            'receive this transfer');

        $to      = (int) $t['to_store_id'];
        $lines   = qa('SELECT * FROM stock_transfer_lines WHERE transfer_id = ?', [$transferId]);
        $shorts  = [];

        foreach ($lines as $l) {
            $sent = (float) $l['qty_sent'];
            $got  = array_key_exists((int) $l['id'], $receivedQtys)
                ? qty_round(max(0.0, (float) $receivedQtys[(int) $l['id']]))
                : $sent;
            if ($got > $sent) {
                throw new StockError('You cannot receive more than was dispatched on line #' . $l['id'] . '.');
            }

            if ($got > 0) {
                stock_receive([
                    'product_id'    => (int) $l['product_id'],
                    'store_id'      => $to,
                    'qty'           => $got,
                    'unit_cost'     => (float) $l['unit_cost'],
                    'movement_type' => 'transfer_in',
                    'ref_type'      => 'transfer',
                    'ref_id'        => $transferId,
                    'ref_no'        => (string) $t['transfer_no'],
                    'note'          => 'Received from store #' . $t['from_store_id'],
                ]);
            }
            q('UPDATE stock_transfer_lines SET qty_received = ? WHERE id = ?', [$got, (int) $l['id']]);

            if ($sent - $got > 0.0005) {
                $shorts[] = [
                    'product_id' => (int) $l['product_id'],
                    'product'    => (string) qv('SELECT name FROM products WHERE id = ?', [(int) $l['product_id']], ''),
                    'sent'       => $sent,
                    'received'   => $got,
                    'short'      => qty_round($sent - $got),
                    'value'      => money_round(($sent - $got) * (float) $l['unit_cost']),
                ];
            }
        }

        db_update('stock_transfers', [
            'status'      => 'received',
            'received_by' => $userId,
            'received_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $transferId]);

        if ($shorts) {
            $value = array_sum(array_column($shorts, 'value'));
            audit_raise_exception('TRANSFER_SHORTAGE', [
                'store_id'     => $to,
                'subject_user' => $userId,
                'entity'       => 'transfer',
                'entity_id'    => $transferId,
                'metric'       => $value,
                'summary'      => sprintf('Transfer %s arrived short on %d line(s), %s at cost',
                    $t['transfer_no'], count($shorts), money($value)),
                'detail'       => $shorts,
            ]);
        }

        audit_log('transfer.receive', 'transfer', $transferId,
            sprintf('Transfer %s received%s', $t['transfer_no'],
                $shorts ? ' with ' . count($shorts) . ' shortage(s)' : ' in full'),
            null, ['shortages' => $shorts], $shorts ? 'warning' : 'notice', $to);
    });
}

/* ================================================================== */
/* STOCK COUNTS                                                       */
/* ================================================================== */

/**
 * Freeze the system quantities onto the count sheet.
 *
 * The figures are captured when the sheet is generated, not when it is posted,
 * because the shop keeps trading while someone walks the aisles. Comparing a
 * count taken at 09:00 against the position at 14:00 would report every sale in
 * between as shrinkage.
 */
function count_generate_sheet(int $countId): int
{
    return db_tx(function () use ($countId): int {
        $c = db_lock_row('stock_counts', 'id = :id', ['id' => $countId]);
        if (!$c) {
            throw new StockError('That stock count no longer exists.');
        }
        if ($c['status'] !== 'open') {
            throw new StockError('The sheet can only be generated while the count is open.');
        }

        $params = [(int) $c['store_id']];
        $catSql = '';
        if ($c['category_id'] !== null) {
            $catSql   = ' AND p.category_id = ?';
            $params[] = (int) $c['category_id'];
        }
        $rows = qa(
            "SELECT p.id, COALESCE(sl.qty_on_hand, 0) AS qty, COALESCE(sl.avg_cost, p.cost_price) AS cost
               FROM products p
               LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
              WHERE p.is_active = 1 $catSql
              ORDER BY p.name",
            $params
        );

        q('DELETE FROM stock_count_lines WHERE count_id = ?', [$countId]);
        $n = 0;
        foreach ($rows as $r) {
            db_insert('stock_count_lines', [
                'count_id'   => $countId,
                'product_id' => (int) $r['id'],
                'system_qty' => (float) $r['qty'],
                'unit_cost'  => (float) $r['cost'],
            ]);
            $n++;
        }
        audit_log('count.sheet', 'stock_count', $countId,
            "Count sheet generated with $n line(s); system quantities frozen", null, null, 'info');
        return $n;
    });
}

/** Mark the count as counted and total up the variance for approval. */
function count_submit(int $countId, int $userId): array
{
    return db_tx(function () use ($countId, $userId): array {
        $c = db_lock_row('stock_counts', 'id = :id', ['id' => $countId]);
        if (!$c) {
            throw new StockError('That stock count no longer exists.');
        }
        if ($c['status'] !== 'open') {
            throw new StockError('This count has already been ' . $c['status'] . '.');
        }
        $uncounted = (int) qv('SELECT COUNT(*) FROM stock_count_lines WHERE count_id = ? AND counted_qty IS NULL',
            [$countId], 0);
        if ($uncounted > 0 && $c['count_type'] === 'full') {
            throw new StockError("A full count must have every line counted; $uncounted still blank.");
        }

        // Variance is only meaningful on lines that were actually counted; a
        // blank line on a cycle count means "not in scope", not "none found".
        q('UPDATE stock_count_lines
              SET variance_qty   = ROUND(COALESCE(counted_qty, system_qty) - system_qty, 3),
                  variance_value = ROUND((COALESCE(counted_qty, system_qty) - system_qty) * unit_cost, 2)
            WHERE count_id = ?', [$countId]);

        $tot = q1('SELECT COALESCE(SUM(variance_qty),0) AS q, COALESCE(SUM(variance_value),0) AS v
                     FROM stock_count_lines WHERE count_id = ?', [$countId]);

        db_update('stock_counts', [
            'status'         => 'counted',
            'counted_by'     => $userId,
            'counted_at'     => date('Y-m-d H:i:s'),
            'variance_qty'   => (float) $tot['q'],
            'variance_value' => (float) $tot['v'],
        ], 'id = :id', ['id' => $countId]);

        audit_log('count.submit', 'stock_count', $countId,
            sprintf('Count %s submitted; variance %s units, %s at cost',
                $c['count_no'], qty_fmt((float) $tot['q']), money((float) $tot['v'])),
            null, null, 'notice', (int) $c['store_id']);

        notify_role('manager', 'warning', 'Stock count awaiting approval',
            sprintf('Count %s has a variance of %s.', $c['count_no'], money((float) $tot['v'])),
            base_url('inventory/count', ['id' => $countId]));

        return ['variance_qty' => (float) $tot['q'], 'variance_value' => (float) $tot['v']];
    });
}

/**
 * Approve and post a count: write the variances into the ledger so the system
 * position matches what was found on the shelf.
 *
 * The person who counted cannot approve it. Without that rule a stock controller
 * could write off whatever they removed by counting it as absent and signing
 * their own sheet, and the count - the control that is meant to detect theft -
 * becomes the means of concealing it.
 */
function count_post(int $countId, int $approverId): array
{
    return db_tx(function () use ($countId, $approverId): array {
        $c = db_lock_row('stock_counts', 'id = :id', ['id' => $countId]);
        if (!$c) {
            throw new StockError('That stock count no longer exists.');
        }
        if ($c['status'] !== 'counted') {
            throw new StockError('Only a submitted count can be approved; this one is ' . $c['status'] . '.');
        }
        sod_assert_different($approverId, $c['counted_by'] !== null ? (int) $c['counted_by'] : null,
            'approve this count');

        $storeId = (int) $c['store_id'];
        $lines   = qa('SELECT * FROM stock_count_lines
                        WHERE count_id = ? AND counted_qty IS NOT NULL
                          AND ABS(variance_qty) > 0.0005', [$countId]);

        $posted = 0;
        foreach ($lines as $l) {
            stock_post([
                'product_id'     => (int) $l['product_id'],
                'store_id'       => $storeId,
                'movement_type'  => 'count_variance',
                'qty_delta'      => (float) $l['variance_qty'],
                'unit_cost'      => (float) $l['unit_cost'],
                'ref_type'       => 'count',
                'ref_id'         => $countId,
                'ref_no'         => (string) $c['count_no'],
                'note'           => 'Count variance: counted ' . qty_fmt((float) $l['counted_qty'])
                                    . ' against ' . qty_fmt((float) $l['system_qty']) . ' expected',
                // A count IS the authority on what is there. Refusing to write a
                // negative here would leave the system knowingly wrong.
                'allow_negative' => true,
            ]);
            $posted++;
        }

        db_update('stock_counts', [
            'status'      => 'posted',
            'approved_by' => $approverId,
            'approved_at' => date('Y-m-d H:i:s'),
            'posted_at'   => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $countId]);

        $value = (float) $c['variance_value'];
        audit_log('count.post', 'stock_count', $countId,
            sprintf('Count %s approved and posted: %d adjustment(s), %s at cost',
                $c['count_no'], $posted, money($value)),
            ['status' => 'counted'], ['status' => 'posted'],
            abs($value) > 0 ? 'warning' : 'notice', $storeId);

        // A large shortfall found at count is the headline shrinkage number.
        $threshold = setting_num('shrinkage_exception_value', 20000.0);
        if ($value < -$threshold) {
            audit_raise_exception('COUNT_SHRINKAGE', [
                'store_id'     => $storeId,
                'subject_user' => $c['counted_by'] !== null ? (int) $c['counted_by'] : null,
                'entity'       => 'stock_count',
                'entity_id'    => $countId,
                'metric'       => abs($value),
                'threshold'    => $threshold,
                'summary'      => sprintf('Count %s found a shortfall of %s at cost',
                    $c['count_no'], money(abs($value))),
            ]);
        }
        return ['lines_posted' => $posted, 'variance_value' => $value];
    });
}

/* ================================================================== */
/* WASTAGE                                                            */
/* ================================================================== */

/**
 * Log wastage. Below the approval limit it posts immediately, because a cashier
 * binning one broken jar should not need a manager; above it, it waits.
 *
 * Wastage is the easiest route for stock to leave a supermarket without anyone
 * noticing, so the write-off is attributable to a named person and a reason
 * code, and both feed the per-user wastage rule in the audit scan.
 */
function wastage_log(array $args): array
{
    return db_tx(function () use ($args): array {
        $productId = (int) $args['product_id'];
        $storeId   = (int) $args['store_id'];
        $qty       = qty_round((float) $args['qty']);
        $userId    = (int) $args['user_id'];
        $reason    = (string) $args['reason_code'];

        if ($qty <= 0) {
            throw new StockError('Enter a quantity greater than zero.');
        }
        $onHand = stock_on_hand($productId, $storeId);
        if ($qty > $onHand + 0.0005 && !setting_bool('negative_stock_allowed', NEGATIVE_STOCK_ALLOWED)) {
            throw new StockError(sprintf('Only %s on hand; you cannot write off %s.',
                qty_fmt($onHand), qty_fmt($qty)));
        }

        $cost  = stock_avg_cost($productId, $storeId);
        $value = money_round($qty * $cost);
        $limit = setting_num('wastage_approval_limit', (float) WASTAGE_APPROVAL_LIMIT);
        $needsApproval = $value > $limit;

        $wasteNo = next_number('wastage', $storeId);
        $wasteId = db_insert('wastage', [
            'waste_no'    => $wasteNo,
            'store_id'    => $storeId,
            'product_id'  => $productId,
            'batch_id'    => $args['batch_id'] ?? null,
            'qty'         => $qty,
            'unit_cost'   => round($cost, 4),
            'total_value' => $value,
            'reason_code' => $reason,
            'note'        => isset($args['note']) ? substr((string) $args['note'], 0, 255) : null,
            'status'      => $needsApproval ? 'pending' : 'approved',
            'logged_by'   => $userId,
        ]);

        if ($needsApproval) {
            audit_log('wastage.raise', 'wastage', $wasteId,
                sprintf('Wastage %s raised for approval: %s of %s, %s', $wasteNo, qty_fmt($qty),
                    (string) qv('SELECT name FROM products WHERE id = ?', [$productId], ''), money($value)),
                null, null, 'notice', $storeId);
            notify_role('manager', 'warning', 'Wastage awaiting approval',
                sprintf('%s: %s to write off (%s).', $wasteNo, money($value), $reason),
                base_url('inventory/wastage'));
            return ['id' => $wasteId, 'waste_no' => $wasteNo, 'posted' => false, 'value' => $value];
        }

        wastage_post_internal($wasteId, $userId);
        return ['id' => $wasteId, 'waste_no' => $wasteNo, 'posted' => true, 'value' => $value];
    });
}

/** Approve a pending write-off. Not the person who raised it. */
function wastage_approve(int $wasteId, int $approverId): void
{
    db_tx(function () use ($wasteId, $approverId): void {
        $w = db_lock_row('wastage', 'id = :id', ['id' => $wasteId]);
        if (!$w) {
            throw new StockError('That wastage record no longer exists.');
        }
        if ($w['status'] !== 'pending') {
            throw new StockError('This write-off is already ' . $w['status'] . '.');
        }
        sod_assert_different($approverId, (int) $w['logged_by'], 'approve this write-off');

        db_update('wastage', [
            'status'      => 'approved',
            'approved_by' => $approverId,
            'approved_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $wasteId]);

        wastage_post_internal($wasteId, $approverId);
    });
}

function wastage_reject(int $wasteId, int $approverId, string $note): void
{
    db_tx(function () use ($wasteId, $approverId, $note): void {
        $w = db_lock_row('wastage', 'id = :id', ['id' => $wasteId]);
        if (!$w || $w['status'] !== 'pending') {
            throw new StockError('Only a pending write-off can be rejected.');
        }
        sod_assert_different($approverId, (int) $w['logged_by'], 'reject this write-off');
        db_update('wastage', [
            'status'      => 'rejected',
            'approved_by' => $approverId,
            'approved_at' => date('Y-m-d H:i:s'),
            'note'        => substr(trim(($w['note'] ?? '') . ' | Rejected: ' . $note), 0, 255),
        ], 'id = :id', ['id' => $wasteId]);

        audit_log('wastage.reject', 'wastage', $wasteId,
            'Write-off ' . $w['waste_no'] . ' rejected: ' . $note, null, null, 'warning', (int) $w['store_id']);
    });
}

/** Take the stock out. Called only from inside an existing transaction. */
function wastage_post_internal(int $wasteId, int $userId): void
{
    $w = db_lock_row('wastage', 'id = :id', ['id' => $wasteId]);
    if (!$w || $w['status'] !== 'approved') {
        throw new StockError('Only an approved write-off can be posted.');
    }

    // Take it from the named batch where one was chosen, so the expiry report and
    // the batch balance stay honest; otherwise FEFO picks.
    if ($w['batch_id'] !== null) {
        $batch = db_lock_row('stock_batches', 'id = :id', ['id' => (int) $w['batch_id']]);
        if ($batch && (float) $batch['qty_on_hand'] + 0.0005 >= (float) $w['qty']) {
            q('UPDATE stock_batches SET qty_on_hand = qty_on_hand - ? WHERE id = ?',
              [(float) $w['qty'], (int) $w['batch_id']]);
            stock_post([
                'product_id'    => (int) $w['product_id'],
                'store_id'      => (int) $w['store_id'],
                'movement_type' => 'wastage',
                'qty_delta'     => -(float) $w['qty'],
                'unit_cost'     => (float) $batch['unit_cost'],
                'batch_id'      => (int) $w['batch_id'],
                'ref_type'      => 'wastage',
                'ref_id'        => $wasteId,
                'ref_no'        => (string) $w['waste_no'],
                'reason_code'   => (string) $w['reason_code'],
                'note'          => $w['note'],
            ]);
        } else {
            throw new StockError('That batch no longer holds enough stock for this write-off.');
        }
    } else {
        stock_issue([
            'product_id'    => (int) $w['product_id'],
            'store_id'      => (int) $w['store_id'],
            'qty'           => (float) $w['qty'],
            'movement_type' => 'wastage',
            'ref_type'      => 'wastage',
            'ref_id'        => $wasteId,
            'ref_no'        => (string) $w['waste_no'],
            'reason_code'   => (string) $w['reason_code'],
            'note'          => $w['note'],
        ]);
    }

    db_update('wastage', ['status' => 'posted', 'posted_at' => date('Y-m-d H:i:s')],
        'id = :id', ['id' => $wasteId]);

    audit_log('wastage.post', 'wastage', $wasteId,
        sprintf('Write-off %s posted: %s of %s, %s at cost (%s)',
            $w['waste_no'], qty_fmt((float) $w['qty']),
            (string) qv('SELECT name FROM products WHERE id = ?', [(int) $w['product_id']], ''),
            money((float) $w['total_value']), $w['reason_code']),
        null, null, 'warning', (int) $w['store_id']);
}

/* ================================================================== */
/* ADJUSTMENTS                                                        */
/* ================================================================== */

/**
 * Post an approved adjustment.
 *
 * An adjustment is the one way to change stock with no corresponding physical
 * event, so it is the most dangerous document in the system and carries the
 * strictest controls: a mandatory reason, an approver who is not the author,
 * and an exception on anything large.
 */
function adjustment_post(int $adjId, int $approverId): array
{
    return db_tx(function () use ($adjId, $approverId): array {
        $a = db_lock_row('stock_adjustments', 'id = :id', ['id' => $adjId]);
        if (!$a) {
            throw new StockError('That adjustment no longer exists.');
        }
        if ($a['status'] !== 'pending') {
            throw new StockError('Only a submitted adjustment can be approved; this one is ' . $a['status'] . '.');
        }
        sod_assert_different($approverId, (int) $a['created_by'], 'approve this adjustment');

        $storeId = (int) $a['store_id'];
        $lines   = qa('SELECT * FROM stock_adjustment_lines WHERE adj_id = ?', [$adjId]);
        if (!$lines) {
            throw new StockError('This adjustment has no lines.');
        }

        $value = 0.0;
        foreach ($lines as $l) {
            $delta = (float) $l['qty_delta'];
            if (abs($delta) < 0.0005) {
                continue;
            }
            $cost = (float) $l['unit_cost'] ?: stock_avg_cost((int) $l['product_id'], $storeId);
            stock_post([
                'product_id'     => (int) $l['product_id'],
                'store_id'       => $storeId,
                'movement_type'  => 'adjustment',
                'qty_delta'      => $delta,
                'unit_cost'      => $cost,
                'ref_type'       => 'adjustment',
                'ref_id'         => $adjId,
                'ref_no'         => (string) $a['adj_no'],
                'reason_code'    => (string) $a['reason_code'],
                'note'           => $l['note'],
                'allow_negative' => true,
            ]);
            $value += $delta * $cost;
        }

        db_update('stock_adjustments', [
            'status'      => 'posted',
            'approved_by' => $approverId,
            'approved_at' => date('Y-m-d H:i:s'),
            'posted_at'   => date('Y-m-d H:i:s'),
            'total_value' => money_round($value),
        ], 'id = :id', ['id' => $adjId]);

        audit_log('adjustment.post', 'adjustment', $adjId,
            sprintf('Adjustment %s posted: %d line(s), %s at cost (%s)',
                $a['adj_no'], count($lines), money($value), $a['reason_code']),
            ['status' => 'pending'], ['status' => 'posted'], 'warning', $storeId);

        $threshold = setting_num('adjustment_exception_value', 15000.0);
        if (abs($value) > $threshold) {
            audit_raise_exception('LARGE_ADJUSTMENT', [
                'store_id'     => $storeId,
                'subject_user' => (int) $a['created_by'],
                'entity'       => 'adjustment',
                'entity_id'    => $adjId,
                'metric'       => abs($value),
                'threshold'    => $threshold,
                'summary'      => sprintf('Adjustment %s moved %s of stock value (%s)',
                    $a['adj_no'], money($value), $a['reason_code']),
            ]);
        }
        return ['lines' => count($lines), 'value' => money_round($value)];
    });
}

/* ================================================================== */
/* PRICE CHANGES                                                      */
/* ================================================================== */

/**
 * Change a product's cost or shelf price, keeping the history.
 *
 * Selling below cost and quiet margin erosion are both audit findings, and
 * neither is visible from the current price alone - you need the before value
 * and who changed it, which is what price_history holds.
 */
function product_change_price(int $productId, ?float $newCost, ?float $newPrice, string $reason, int $userId): void
{
    db_tx(function () use ($productId, $newCost, $newPrice, $reason, $userId): void {
        $p = db_lock_row('products', 'id = :id', ['id' => $productId]);
        if (!$p) {
            throw new StockError('That product no longer exists.');
        }
        $oldCost  = (float) $p['cost_price'];
        $oldPrice = (float) $p['sell_price'];
        $cost     = $newCost  ?? $oldCost;
        $price    = $newPrice ?? $oldPrice;

        if (abs($cost - $oldCost) < 0.00005 && abs($price - $oldPrice) < 0.005) {
            return;   // nothing actually changed; do not write a history row
        }

        db_update('products', [
            'cost_price' => round($cost, 4),
            'sell_price' => money_round($price),
        ], 'id = :id', ['id' => $productId]);

        db_insert('price_history', [
            'product_id' => $productId,
            'old_cost'   => $oldCost,
            'new_cost'   => round($cost, 4),
            'old_price'  => $oldPrice,
            'new_price'  => money_round($price),
            'reason'     => substr($reason, 0, 160),
            'changed_by' => $userId,
        ]);

        audit_log('product.price', 'product', $productId,
            sprintf('%s: cost %s to %s, price %s to %s (%s)', $p['name'],
                money($oldCost), money($cost), money($oldPrice), money($price), $reason),
            ['cost' => $oldCost, 'price' => $oldPrice],
            ['cost' => $cost, 'price' => $price], 'notice');

        // A shelf price at or under cost is either a mistake or a favour.
        if ($price > 0 && $cost > 0) {
            $marginPct = (($price / (1 + (float) $p['vat_rate'])) - $cost) / ($price / (1 + (float) $p['vat_rate'])) * 100;
            $floor     = setting_num('low_margin_warn_pct', (float) LOW_MARGIN_WARN_PCT);
            if ($marginPct < $floor) {
                audit_raise_exception('BELOW_COST_PRICING', [
                    'subject_user' => $userId,
                    'entity'       => 'product',
                    'entity_id'    => $productId,
                    'metric'       => round($marginPct, 2),
                    'threshold'    => $floor,
                    'summary'      => sprintf('%s priced at %s against a cost of %s - margin %s',
                        $p['name'], money($price), money($cost), pct_fmt($marginPct)),
                ]);
            }
        }
    });
}
