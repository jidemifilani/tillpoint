<?php
/**
 * Supplier invoices and the three-way match.
 *
 * The match compares three independent records of the same delivery:
 *
 *   the ORDER    - what we agreed to buy, and at what price
 *   the RECEIPT  - what actually arrived, counted at the door
 *   the INVOICE  - what the supplier says we owe
 *
 * Any two of those agreeing proves nothing. A buyer and a supplier can agree a
 * price nobody received goods at; a receiving clerk and a supplier can agree a
 * quantity nobody ordered. It is the third leg that makes the control work, and
 * it is the leg this application was missing: grn_post() already flagged a
 * receipt booked above the order price, but nothing checked whether the eventual
 * bill matched either of them.
 *
 * Nothing here is blocked outright. A genuine price rise, a substitution and a
 * delivery charge all produce variances, and a clerk who cannot enter an invoice
 * will put it in a drawer. Variances are measured, surfaced, and made somebody's
 * decision to accept - with their name against it.
 */
declare(strict_types=1);

class InvoiceError extends RuntimeException
{
}

/* ------------------------------------------------------------------ */
/* Totals                                                            */
/* ------------------------------------------------------------------ */

function invoice_recalculate(int $invoiceId): void
{
    q('UPDATE supplier_invoice_lines SET line_total = ROUND(qty * unit_cost, 2)
        WHERE invoice_id = ?', [$invoiceId]);

    $row = q1('SELECT COALESCE(SUM(line_total), 0) AS sub,
                      COALESCE(SUM(line_total * vat_rate), 0) AS vat
                 FROM supplier_invoice_lines WHERE invoice_id = ?', [$invoiceId]);
    $sub = (float) ($row['sub'] ?? 0);
    $vat = (float) ($row['vat'] ?? 0);

    db_update('supplier_invoices', [
        'subtotal'  => money_round($sub),
        'vat_total' => money_round($vat),
        'total'     => money_round($sub + $vat),
    ], 'id = :id', ['id' => $invoiceId]);
}

/* ------------------------------------------------------------------ */
/* The match                                                          */
/* ------------------------------------------------------------------ */

/**
 * Compare an invoice against the deliveries and orders behind it.
 *
 * Read-only: it computes and returns the comparison without changing anything,
 * so a page can show it and invoice_match_apply() can store it. Keeping those
 * apart means the screen can be opened as often as anyone likes without
 * rewriting the record.
 *
 * Returns per-line detail plus the headline variances.
 */
function invoice_match(int $invoiceId): array
{
    $invoice = q1('SELECT * FROM supplier_invoices WHERE id = ?', [$invoiceId]);
    if (!$invoice) {
        throw new InvoiceError('That invoice no longer exists.');
    }

    $lines = qa(
        'SELECT sil.*,
                gl.qty        AS received_qty,
                gl.unit_cost  AS received_cost,
                gl.product_id AS received_product_id,
                g.grn_no, g.id AS grn_id, g.received_date,
                pol.qty_ordered, pol.unit_cost AS agreed_cost,
                po.po_no, po.id AS po_id,
                p.name AS product_name, p.sku, p.unit_code
           FROM supplier_invoice_lines sil
           LEFT JOIN grn_lines gl ON gl.id = sil.grn_line_id
           LEFT JOIN grns g ON g.id = gl.grn_id
           LEFT JOIN purchase_order_lines pol ON pol.id = gl.po_line_id
           LEFT JOIN purchase_orders po ON po.id = pol.po_id
           LEFT JOIN products p ON p.id = sil.product_id
          WHERE sil.invoice_id = ?
          ORDER BY sil.id',
        [$invoiceId]
    );

    $priceTolerancePct = setting_num('invoice_price_tolerance_pct', 2.0);
    $valueTolerance    = setting_num('invoice_value_tolerance', 200.0);

    $detail        = [];
    $priceVariance = 0.0;   // billed above what was agreed
    $qtyVariance   = 0.0;   // billed for more than arrived, at the billed cost
    $unmatched     = 0;

    foreach ($lines as $l) {
        $billedQty  = (float) $l['qty'];
        $billedCost = (float) $l['unit_cost'];
        $billedLine = (float) $l['line_total'];

        $recvQty   = $l['received_qty'] !== null ? (float) $l['received_qty'] : null;
        $recvCost  = $l['received_cost'] !== null ? (float) $l['received_cost'] : null;
        $agreed    = $l['agreed_cost'] !== null ? (float) $l['agreed_cost'] : null;

        $issues = [];

        // Billed for something we have no record of receiving. The most serious
        // of the three, because there is nothing to compare against at all.
        if ($l['grn_line_id'] === null) {
            $unmatched++;
            $issues[] = ['unmatched', $l['product_id'] === null
                ? 'A charge with no delivery behind it - check it is a legitimate extra'
                : 'Billed for goods with no matching delivery line'];
        }

        // Quantity: billed against counted. Valued at the billed cost, because
        // that is what is actually being asked for.
        $qtyDiff = 0.0;
        if ($recvQty !== null) {
            $qtyDiff = $billedQty - $recvQty;
            if (abs($qtyDiff) > 0.0005) {
                $qtyVariance += $qtyDiff * $billedCost;
                $issues[] = ['quantity', sprintf('Billed %s, received %s (%s%s)',
                    qty_fmt($billedQty), qty_fmt($recvQty),
                    $qtyDiff > 0 ? 'over by ' : 'under by ', qty_fmt(abs($qtyDiff)))];
            }
        }

        // Price: billed against the agreed order price where there is one,
        // otherwise against the cost the goods were booked in at. Comparing
        // against the receipt cost alone would miss a receiving clerk and a
        // supplier agreeing a figure the buyer never saw.
        $compareTo = $agreed ?? $recvCost;
        $priceDiff = 0.0;
        if ($compareTo !== null && $compareTo > 0) {
            $priceDiff = $billedCost - $compareTo;
            $pct = ($priceDiff / $compareTo) * 100;
            if (abs($pct) > $priceTolerancePct) {
                $priceVariance += $priceDiff * min($billedQty, $recvQty ?? $billedQty);
                $issues[] = ['price', sprintf('Billed %s against %s %s (%s%s)',
                    money($billedCost), $agreed !== null ? 'an agreed' : 'a booked',
                    money($compareTo), $pct > 0 ? '+' : '', pct_fmt($pct))];
            }
        }

        $detail[] = [
            'line'          => $l,
            'billed_qty'    => $billedQty,
            'billed_cost'   => $billedCost,
            'billed_line'   => $billedLine,
            'received_qty'  => $recvQty,
            'received_cost' => $recvCost,
            'agreed_cost'   => $agreed,
            'qty_diff'      => $qtyDiff,
            'price_diff'    => $priceDiff,
            'issues'        => $issues,
            'clean'         => $issues === [],
        ];
    }

    $totalVariance = $priceVariance + $qtyVariance;

    // "Clean" has to mean clean: a tolerance on the headline figure, and no
    // unmatched line at all. An unmatched line of any size is a different
    // problem from a small price drift and should not be averaged away by one.
    $clean = $unmatched === 0 && abs($totalVariance) <= $valueTolerance;

    return [
        'invoice'         => $invoice,
        'detail'          => $detail,
        'price_variance'  => money_round($priceVariance),
        'qty_variance'    => money_round($qtyVariance),
        'total_variance'  => money_round($totalVariance),
        'unmatched_lines' => $unmatched,
        'clean'           => $clean,
        'tolerance_pct'   => $priceTolerancePct,
        'tolerance_value' => $valueTolerance,
    ];
}

/**
 * Store the result of a match and raise exceptions for anything material.
 */
function invoice_match_apply(int $invoiceId): array
{
    return db_tx(function () use ($invoiceId): array {
        $m       = invoice_match($invoiceId);
        $invoice = $m['invoice'];

        db_update('supplier_invoices', [
            'match_status'   => $m['clean'] ? 'clean' : 'variance',
            'price_variance' => $m['price_variance'],
            'qty_variance'   => $m['qty_variance'],
            'status'         => $invoice['status'] === 'draft' ? 'matched' : $invoice['status'],
        ], 'id = :id', ['id' => $invoiceId]);

        $supplierName = (string) qv('SELECT name FROM suppliers WHERE id = ?',
            [(int) $invoice['supplier_id']], '');

        audit_log('invoice.match', 'supplier_invoice', $invoiceId,
            sprintf('Invoice %s from %s matched: %s (price %s, quantity %s, %d unmatched line(s))',
                (string) $invoice['invoice_no'], $supplierName,
                $m['clean'] ? 'clean' : 'variance found',
                money($m['price_variance']), money($m['qty_variance']), $m['unmatched_lines']),
            null, [
                'price_variance' => $m['price_variance'],
                'qty_variance'   => $m['qty_variance'],
                'unmatched'      => $m['unmatched_lines'],
            ],
            $m['clean'] ? 'info' : 'warning', (int) $invoice['store_id']);

        if (!$m['clean']) {
            // Overbilling and underbilling are not the same finding. Being
            // charged too much is money leaving; being charged too little is a
            // credit note waiting to be claimed back, and worth knowing before
            // the supplier notices.
            if (abs($m['price_variance']) > $m['tolerance_value']) {
                audit_raise_exception('INVOICE_PRICE_VARIANCE', [
                    'store_id'     => (int) $invoice['store_id'],
                    'subject_user' => (int) $invoice['entered_by'],
                    'entity'       => 'supplier_invoice',
                    'entity_id'    => $invoiceId,
                    'metric'       => abs($m['price_variance']),
                    'threshold'    => $m['tolerance_value'],
                    'summary'      => sprintf('Invoice %s from %s is billed %s %s than the agreed price',
                        (string) $invoice['invoice_no'], $supplierName,
                        money(abs($m['price_variance'])),
                        $m['price_variance'] > 0 ? 'higher' : 'lower'),
                    'detail'       => ['price_variance' => $m['price_variance']],
                ]);
            }
            if (abs($m['qty_variance']) > $m['tolerance_value']) {
                audit_raise_exception('INVOICE_QTY_VARIANCE', [
                    'store_id'     => (int) $invoice['store_id'],
                    'subject_user' => (int) $invoice['entered_by'],
                    'entity'       => 'supplier_invoice',
                    'entity_id'    => $invoiceId,
                    'metric'       => abs($m['qty_variance']),
                    'threshold'    => $m['tolerance_value'],
                    'summary'      => sprintf('Invoice %s from %s bills for %s %s than was received',
                        (string) $invoice['invoice_no'], $supplierName,
                        money(abs($m['qty_variance'])),
                        $m['qty_variance'] > 0 ? 'more' : 'less'),
                ]);
            }
            if ($m['unmatched_lines'] > 0) {
                audit_raise_exception('INVOICE_NO_RECEIPT', [
                    'store_id'     => (int) $invoice['store_id'],
                    'subject_user' => (int) $invoice['entered_by'],
                    'entity'       => 'supplier_invoice',
                    'entity_id'    => $invoiceId,
                    'metric'       => $m['unmatched_lines'],
                    'summary'      => sprintf('Invoice %s from %s has %d line(s) with no delivery behind them',
                        (string) $invoice['invoice_no'], $supplierName, $m['unmatched_lines']),
                ]);
            }
        }

        return $m;
    });
}

/* ------------------------------------------------------------------ */
/* Approval                                                           */
/* ------------------------------------------------------------------ */

/**
 * Approve an invoice for payment.
 *
 * Three gates, in this order:
 *
 *   1. the person who entered it cannot approve it - an invoice one person can
 *      both key in and release for payment is an invoice they can invent;
 *   2. it must have been matched, so nobody approves a figure nobody compared;
 *   3. a variance must be explicitly accepted with a reason, rather than
 *      waved through by the same click that approves the invoice.
 */
function invoice_approve(int $invoiceId, int $approverId, bool $acceptVariance, string $note): void
{
    db_tx(function () use ($invoiceId, $approverId, $acceptVariance, $note): void {
        $inv = db_lock_row('supplier_invoices', 'id = :id', ['id' => $invoiceId]);
        if (!$inv) {
            throw new InvoiceError('That invoice no longer exists.');
        }
        if (!in_array($inv['status'], ['matched', 'disputed'], true)) {
            throw new InvoiceError('Only a matched invoice can be approved; this one is '
                . $inv['status'] . '.');
        }

        sod_assert_different($approverId, (int) $inv['entered_by'], 'approve this invoice');

        if ($inv['match_status'] === 'unmatched') {
            throw new InvoiceError('This invoice has not been matched against the deliveries yet. '
                . 'Approving an unmatched invoice is approving a figure nobody has compared.');
        }

        if ($inv['match_status'] === 'variance') {
            if (!$acceptVariance) {
                throw new InvoiceError('This invoice does not match the deliveries behind it. '
                    . 'Either dispute it with the supplier, or accept the variance explicitly '
                    . 'and say why.');
            }
            if (trim($note) === '') {
                throw new InvoiceError('Accepting a variance needs a reason - that is the whole '
                    . 'point of recording it.');
            }
        }

        db_update('supplier_invoices', [
            'status'      => 'approved',
            'approved_by' => $approverId,
            'approved_at' => date('Y-m-d H:i:s'),
            'note'        => trim($note) !== ''
                ? substr(trim((string) ($inv['note'] ?? '') . ' | Approved: ' . $note), 0, 500)
                : $inv['note'],
        ], 'id = :id', ['id' => $invoiceId]);

        $variance = (float) $inv['price_variance'] + (float) $inv['qty_variance'];

        audit_log('invoice.approve', 'supplier_invoice', $invoiceId,
            sprintf('Invoice %s approved for payment, %s%s',
                (string) $inv['invoice_no'], money((float) $inv['total']),
                $inv['match_status'] === 'variance'
                    ? sprintf(' with an accepted variance of %s: %s', money($variance), $note)
                    : ' (clean match)'),
            ['status' => $inv['status']], ['status' => 'approved'],
            // Accepting a variance is the decision worth finding later.
            $inv['match_status'] === 'variance' ? 'warning' : 'notice',
            (int) $inv['store_id']);
    });
}

function invoice_dispute(int $invoiceId, int $userId, string $reason): void
{
    db_tx(function () use ($invoiceId, $userId, $reason): void {
        $inv = db_lock_row('supplier_invoices', 'id = :id', ['id' => $invoiceId]);
        if (!$inv) {
            throw new InvoiceError('That invoice no longer exists.');
        }
        if (in_array($inv['status'], ['paid', 'cancelled'], true)) {
            throw new InvoiceError('A ' . $inv['status'] . ' invoice cannot be disputed.');
        }
        if (trim($reason) === '') {
            throw new InvoiceError('Say what is being disputed - the supplier will ask.');
        }

        db_update('supplier_invoices', [
            'status'       => 'disputed',
            'dispute_note' => substr($reason, 0, 500),
        ], 'id = :id', ['id' => $invoiceId]);

        audit_log('invoice.dispute', 'supplier_invoice', $invoiceId,
            sprintf('Invoice %s disputed: %s', (string) $inv['invoice_no'], $reason),
            ['status' => $inv['status']], ['status' => 'disputed'], 'warning',
            (int) $inv['store_id']);
    });
}

function invoice_mark_paid(int $invoiceId, int $userId): void
{
    db_tx(function () use ($invoiceId, $userId): void {
        $inv = db_lock_row('supplier_invoices', 'id = :id', ['id' => $invoiceId]);
        if (!$inv) {
            throw new InvoiceError('That invoice no longer exists.');
        }
        if ($inv['status'] !== 'approved') {
            throw new InvoiceError('Only an approved invoice can be marked paid; this one is '
                . $inv['status'] . '. Paying an unapproved invoice is the thing this whole '
                . 'process exists to prevent.');
        }
        db_update('supplier_invoices', [
            'status'  => 'paid',
            'paid_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $invoiceId]);

        audit_log('invoice.paid', 'supplier_invoice', $invoiceId,
            sprintf('Invoice %s marked paid, %s', (string) $inv['invoice_no'],
                money((float) $inv['total'])),
            ['status' => 'approved'], ['status' => 'paid'], 'notice',
            (int) $inv['store_id']);
    });
}

/* ------------------------------------------------------------------ */
/* Helpers for the pages                                              */
/* ------------------------------------------------------------------ */

/**
 * Deliveries from a supplier that nothing has billed for yet.
 *
 * The other half of the control, and the half people forget: an invoice with no
 * delivery is obvious, but a delivery with no invoice is a liability nobody has
 * recorded, and it is how an accrual gets missed at year end.
 */
function invoice_unbilled_grns(int $supplierId, int $storeId): array
{
    return qa(
        "SELECT g.id, g.grn_no, g.received_date, g.total, g.supplier_invoice_no,
                po.po_no,
                (SELECT COUNT(*) FROM grn_lines gl WHERE gl.grn_id = g.id) AS line_count
           FROM grns g
           LEFT JOIN purchase_orders po ON po.id = g.po_id
          WHERE g.supplier_id = ? AND g.store_id = ? AND g.status = 'posted'
            AND NOT EXISTS (SELECT 1 FROM supplier_invoice_grns sig
                             JOIN supplier_invoices si ON si.id = sig.invoice_id
                            WHERE sig.grn_id = g.id AND si.status <> 'cancelled')
          ORDER BY g.received_date DESC, g.id DESC",
        [$supplierId, $storeId]
    );
}

/** Deliveries older than the supplier's terms with still no invoice against them. */
function invoice_overdue_unbilled(int $storeId, int $days = 45): array
{
    return qa(
        "SELECT g.id, g.grn_no, g.received_date, g.total, s.name AS supplier_name,
                s.id AS supplier_id, DATEDIFF(CURDATE(), g.received_date) AS age
           FROM grns g
           JOIN suppliers s ON s.id = g.supplier_id
          WHERE g.store_id = ? AND g.status = 'posted'
            AND DATEDIFF(CURDATE(), g.received_date) > ?
            AND NOT EXISTS (SELECT 1 FROM supplier_invoice_grns sig
                             JOIN supplier_invoices si ON si.id = sig.invoice_id
                            WHERE sig.grn_id = g.id AND si.status <> 'cancelled')
          ORDER BY g.received_date",
        [$storeId, $days]
    );
}

/** Pull the delivery's lines onto the invoice as a starting point. */
function invoice_pull_from_grn(int $invoiceId, int $grnId): int
{
    return db_tx(function () use ($invoiceId, $grnId): int {
        $inv = q1('SELECT * FROM supplier_invoices WHERE id = ?', [$invoiceId]);
        $grn = q1('SELECT * FROM grns WHERE id = ?', [$grnId]);
        if (!$inv || !$grn) {
            throw new InvoiceError('That invoice or delivery no longer exists.');
        }
        if ($inv['status'] !== 'draft') {
            throw new InvoiceError('Lines can only be pulled in while the invoice is a draft.');
        }
        if ((int) $grn['supplier_id'] !== (int) $inv['supplier_id']) {
            throw new InvoiceError('That delivery is from a different supplier.');
        }
        if ($grn['status'] !== 'posted') {
            throw new InvoiceError('That delivery has not been posted to stock yet, so there is '
                . 'nothing confirmed to bill against.');
        }

        q('INSERT IGNORE INTO supplier_invoice_grns (invoice_id, grn_id) VALUES (?, ?)',
          [$invoiceId, $grnId]);

        $added = 0;
        foreach (qa('SELECT gl.*, p.name FROM grn_lines gl
                      JOIN products p ON p.id = gl.product_id
                     WHERE gl.grn_id = ?', [$grnId]) as $gl) {
            // Skip a line already pulled in, so pulling the same delivery twice
            // does not double the invoice.
            if (qv('SELECT id FROM supplier_invoice_lines
                     WHERE invoice_id = ? AND grn_line_id = ?',
                   [$invoiceId, (int) $gl['id']])) {
                continue;
            }
            // Seeded from what was received, not from what the supplier claims.
            // The clerk then corrects it to the paper invoice, and the match
            // measures the difference - which is the point.
            db_insert('supplier_invoice_lines', [
                'invoice_id'  => $invoiceId,
                'grn_line_id' => (int) $gl['id'],
                'product_id'  => (int) $gl['product_id'],
                'description' => substr((string) $gl['name'], 0, 160),
                'qty'         => (float) $gl['qty'],
                'unit_cost'   => (float) $gl['unit_cost'],
                'vat_rate'    => (float) $gl['vat_rate'],
            ]);
            $added++;
        }
        invoice_recalculate($invoiceId);
        return $added;
    });
}
