<?php
/**
 * The exception-detection engine.
 *
 * An audit trail records what happened. It does not tell anyone which of those
 * things is worth looking at, and nobody reads a hundred thousand rows. These
 * rules do that reading: each one states a pattern that is legitimate in
 * isolation and suspicious in quantity, and raises an exception when a real
 * figure crosses a stated threshold.
 *
 * Two kinds of rule, deliberately separated:
 *
 *   INLINE  - detected at the moment of the event, by the posting routine
 *             itself (a till variance at close, an over-receipt at GRN post).
 *             Registered here so the catalogue is complete and the threshold is
 *             editable, but raised elsewhere.
 *   SCAN    - detected by comparing a day's worth of behaviour against a norm
 *             (one cashier's voids against the shop's). Only a scan can see
 *             these, because no single transaction looks wrong.
 *
 * Nothing here accuses anyone. Every rule produces a question for a human with
 * the context attached, which is the only honest thing automated detection can
 * do: a cashier with twenty voids may be working the busiest till on a bad day.
 */
declare(strict_types=1);

/**
 * The rule catalogue. install.php syncs it into audit_rules, where thresholds
 * become editable; the code remains the source of the rule's meaning.
 */
function audit_rule_catalogue(): array
{
    return [
        // ---- Till and cash -------------------------------------------
        'TILL_VARIANCE' => [
            'name' => 'Till closed outside cash tolerance', 'module' => 'cash', 'kind' => 'inline',
            'severity' => 'medium', 'threshold' => 200, 'threshold_label' => 'Variance over (currency)',
            'description' => 'A drawer that counts short or over by more than the tolerance. '
                . 'Small variances are normal; a pattern on one cashier is not.',
        ],
        'ROUND_SUM_SHORTAGE' => [
            'name' => 'Till short by a round note amount', 'module' => 'cash', 'kind' => 'scan',
            'severity' => 'high', 'threshold' => 500, 'threshold_label' => 'Shortage at least (currency)',
            'description' => 'A shortage that is an exact multiple of a note denomination. '
                . 'Miscounting produces odd amounts; removing notes produces round ones.',
        ],
        'EXCESSIVE_NO_SALE' => [
            'name' => 'Drawer opened without a sale too often', 'module' => 'cash', 'kind' => 'scan',
            'severity' => 'medium', 'threshold' => 10, 'threshold_label' => 'No-sale opens per shift',
            'description' => 'The drawer opening repeatedly with no transaction behind it. '
                . 'Each one is a chance to take cash or to settle a short from an earlier one.',
        ],
        'SESSION_UNRECONCILED' => [
            'name' => 'Till variance left unreconciled', 'module' => 'cash', 'kind' => 'scan',
            'severity' => 'medium', 'threshold' => 3, 'threshold_label' => 'Days outstanding',
            'description' => 'A closed session with a variance nobody has signed off. '
                . 'An unexamined variance is indistinguishable from an accepted loss.',
        ],

        // ---- Till behaviour ------------------------------------------
        'EXCESSIVE_VOIDS' => [
            'name' => 'High void rate for one cashier', 'module' => 'pos', 'kind' => 'scan',
            'severity' => 'high', 'threshold' => 15, 'threshold_label' => 'Voids as % of transactions',
            'description' => 'Voiding a started basket is routine. Voiding a large share of them is '
                . 'how a cashier rings up a sale, pockets the cash and cancels the record.',
        ],
        'EXCESSIVE_DISCOUNTS' => [
            'name' => 'High discount rate for one cashier', 'module' => 'pos', 'kind' => 'scan',
            'severity' => 'medium', 'threshold' => 8, 'threshold_label' => 'Discount as % of sales value',
            'description' => 'Discount given well above the shop average, which can be a '
                . 'staff-and-friends discount applied without authority.',
        ],
        'EXCESSIVE_PRICE_OVERRIDES' => [
            'name' => 'Frequent price overrides', 'module' => 'pos', 'kind' => 'scan',
            'severity' => 'high', 'threshold' => 10, 'threshold_label' => 'Overrides per day',
            'description' => 'Repeated manual pricing at one till. Either the shelf prices are wrong '
                . 'or someone is choosing their own.',
        ],
        'EXCESSIVE_REFUNDS' => [
            'name' => 'High refund value for one cashier', 'module' => 'pos', 'kind' => 'scan',
            'severity' => 'high', 'threshold' => 20000, 'threshold_label' => 'Refund value per day (currency)',
            'description' => 'Refunds concentrated on one person. A refund is cash out of the drawer '
                . 'with only a reason code behind it.',
        ],
        'REFUND_OWN_SALE' => [
            'name' => 'Cashier refunded their own sale', 'module' => 'pos', 'kind' => 'scan',
            'severity' => 'high', 'threshold' => 1, 'threshold_label' => 'Occurrences',
            'description' => 'The same person took the money and gave it back. Sometimes unavoidable on '
                . 'a one-till shift, which is why it is reviewed rather than blocked.',
        ],
        'AFTER_HOURS_SALES' => [
            'name' => 'Sales outside trading hours', 'module' => 'pos', 'kind' => 'scan',
            'severity' => 'medium', 'threshold' => 1, 'threshold_label' => 'Transactions outside hours',
            'description' => 'Transactions rung up when the shop is shut - worth a question whatever '
                . 'the explanation.',
        ],
        'SALE_BELOW_COST' => [
            'name' => 'Sale below cost', 'module' => 'pos', 'kind' => 'inline',
            'severity' => 'medium', 'threshold' => null, 'threshold_label' => null,
            'description' => 'Goods sold for less than they cost, after discount and override.',
        ],
        'RECEIPT_GAPS' => [
            'name' => 'Gaps in the receipt sequence', 'module' => 'pos', 'kind' => 'scan',
            'severity' => 'medium', 'threshold' => 5, 'threshold_label' => 'Missing numbers',
            'description' => 'Receipt numbers issued but never used. A few mean abandoned baskets; '
                . 'many mean transactions that are no longer in the record.',
        ],

        // ---- Stock ---------------------------------------------------
        'COUNT_SHRINKAGE' => [
            'name' => 'Significant shortfall at stock count', 'module' => 'stock', 'kind' => 'inline',
            'severity' => 'high', 'threshold' => 20000, 'threshold_label' => 'Shortfall over (currency)',
            'description' => 'A count found materially less on the shelf than the system expected. '
                . 'This is the headline shrinkage figure.',
        ],
        'LARGE_ADJUSTMENT' => [
            'name' => 'Large stock adjustment', 'module' => 'stock', 'kind' => 'inline',
            'severity' => 'high', 'threshold' => 15000, 'threshold_label' => 'Value over (currency)',
            'description' => 'An adjustment changes stock with no physical event behind it, so a large '
                . 'one is the most direct way to make a shortage disappear.',
        ],
        'EXCESSIVE_WASTAGE' => [
            'name' => 'High wastage for one person', 'module' => 'stock', 'kind' => 'scan',
            'severity' => 'high', 'threshold' => 15000, 'threshold_label' => 'Wastage value per day (currency)',
            'description' => 'Write-offs concentrated on one person. Wastage is the easiest route for '
                . 'goods to leave a shop with paperwork that looks correct.',
        ],
        'NEGATIVE_STOCK' => [
            'name' => 'Negative stock position', 'module' => 'stock', 'kind' => 'scan',
            'severity' => 'medium', 'threshold' => 1, 'threshold_label' => 'Product lines below zero',
            'description' => 'The system believes it holds less than nothing, which means goods were '
                . 'sold before a receipt was posted - or never received at all.',
        ],
        'EXPIRED_ON_SHELF' => [
            'name' => 'Expired stock still on hand', 'module' => 'stock', 'kind' => 'scan',
            'severity' => 'critical', 'threshold' => 1, 'threshold_label' => 'Batches past expiry',
            'description' => 'Batches past their expiry date with stock remaining. A food-safety '
                . 'exposure before it is a financial one.',
        ],
        'GRN_OVER_RECEIPT' => [
            'name' => 'Goods received over the order', 'module' => 'stock', 'kind' => 'inline',
            'severity' => 'medium', 'threshold' => 10, 'threshold_label' => 'Over-receipt %',
            'description' => 'More booked in than was ordered - an unagreed delivery, or quantities '
                . 'entered to cover a shortage elsewhere.',
        ],
        'GRN_COST_VARIANCE' => [
            'name' => 'Receipt cost above the agreed price', 'module' => 'stock', 'kind' => 'inline',
            'severity' => 'medium', 'threshold' => 5, 'threshold_label' => 'Cost over agreed by %',
            'description' => 'Goods booked in above the purchase-order price, which quietly moves '
                . 'margin to the supplier.',
        ],
        'TRANSFER_SHORTAGE' => [
            'name' => 'Transfer arrived short', 'module' => 'stock', 'kind' => 'inline',
            'severity' => 'high', 'threshold' => null, 'threshold_label' => null,
            'description' => 'Less arrived at the receiving store than left the sending one. '
                . 'Stock lost between branches is stock nobody is accountable for.',
        ],
        'LEDGER_MISMATCH' => [
            'name' => 'Stock position disagrees with its ledger', 'module' => 'stock', 'kind' => 'scan',
            'severity' => 'critical', 'threshold' => 1, 'threshold_label' => 'Product lines affected',
            'description' => 'A position that does not equal the sum of its own movements. Either a '
                . 'defect or direct database tampering; both invalidate every stock figure.',
        ],
        'STALE_COUNT' => [
            'name' => 'High-value stock not counted recently', 'module' => 'stock', 'kind' => 'scan',
            'severity' => 'low', 'threshold' => 90, 'threshold_label' => 'Days since last count',
            'description' => 'Valuable lines that have gone a long time without verification. '
                . 'Shrinkage found late is shrinkage nobody can explain.',
        ],
        'BELOW_COST_PRICING' => [
            'name' => 'Shelf price at or below cost', 'module' => 'stock', 'kind' => 'inline',
            'severity' => 'medium', 'threshold' => 5, 'threshold_label' => 'Margin under %',
            'description' => 'A shelf price that loses money on every unit sold.',
        ],

        // ---- Control and access --------------------------------------
        'SOD_SELF_APPROVAL' => [
            'name' => 'Self-approval of own document', 'module' => 'control', 'kind' => 'inline',
            'severity' => 'critical', 'threshold' => null, 'threshold_label' => null,
            'description' => 'Someone approved a document they raised. Blocked by default; recorded '
                . 'here when an override permitted it.',
        ],
        'PIN_BRUTE_FORCE' => [
            'name' => 'Supervisor PIN attacked at a till', 'module' => 'control', 'kind' => 'inline',
            'severity' => 'high', 'threshold' => null, 'threshold_label' => null,
            'description' => 'Repeated wrong PINs against a supervisor account, which is someone at a '
                . 'terminal trying to authorise their own override.',
        ],
        'AUDIT_CHAIN_BROKEN' => [
            'name' => 'Audit trail integrity failure', 'module' => 'control', 'kind' => 'scan',
            'severity' => 'critical', 'threshold' => null, 'threshold_label' => null,
            'description' => 'The hash chain does not verify: a trail entry was altered or removed '
                . 'outside the application. Nothing else in this list can be trusted until it is explained.',
        ],
        'AFTER_HOURS_ACCESS' => [
            'name' => 'Back-office activity outside hours', 'module' => 'control', 'kind' => 'scan',
            'severity' => 'low', 'threshold' => 20, 'threshold_label' => 'Actions outside hours',
            'description' => 'Pricing, stock or user changes made when the shop is closed.',
        ],
        'DRAFT_GRN_STALE' => [
            'name' => 'Goods receipt left unposted', 'module' => 'control', 'kind' => 'scan',
            'severity' => 'medium', 'threshold' => 3, 'threshold_label' => 'Days unposted',
            'description' => 'A delivery recorded but never posted to stock, so the shop is selling '
                . 'goods the system does not know it has.',
        ],

        // ---- Supplier invoices / three-way match ---------------------
        'INVOICE_PRICE_VARIANCE' => [
            'name' => 'Invoice billed at a different price to the agreement', 'module' => 'purchasing', 'kind' => 'inline',
            'severity' => 'high', 'threshold' => 200, 'threshold_label' => 'Variance value over (currency)',
            'description' => 'The supplier billed at a price that differs from the agreed purchase-order '
                . 'price by more than the tolerance. Being charged more costs margin; being charged less '
                . 'means a credit note that is not being claimed back.',
        ],
        'INVOICE_QTY_VARIANCE' => [
            'name' => 'Invoice billed for a different quantity than received', 'module' => 'purchasing', 'kind' => 'inline',
            'severity' => 'high', 'threshold' => 200, 'threshold_label' => 'Variance value over (currency)',
            'description' => 'The supplier billed for more or fewer units than the goods-received note '
                . 'records as having arrived. Paying for goods that were not received is money with '
                . 'nothing behind it.',
        ],
        'INVOICE_NO_RECEIPT' => [
            'name' => 'Invoice line has no delivery behind it', 'module' => 'purchasing', 'kind' => 'inline',
            'severity' => 'critical', 'threshold' => null, 'threshold_label' => null,
            'description' => 'A line on a supplier invoice has no matching goods-received line. There is '
                . 'no recorded delivery to compare the bill against, so this is the most unverifiable '
                . 'of the three match findings.',
        ],
    ];
}

function audit_rule(string $code): ?array
{
    return q1('SELECT * FROM audit_rules WHERE code = ?', [$code]);
}

function audit_rule_threshold(string $code, float $default): float
{
    $v = qv('SELECT threshold FROM audit_rules WHERE code = ? AND is_active = 1', [$code]);
    return $v !== null ? (float) $v : $default;
}

function audit_rule_enabled(string $code): bool
{
    return (int) qv('SELECT is_active FROM audit_rules WHERE code = ?', [$code], 0) === 1;
}

/* ================================================================== */
/* THE SCAN                                                           */
/* ================================================================== */

/**
 * Run every scan rule for one trading day.
 *
 * Idempotent: audit_raise_exception deduplicates on rule+date+store+subject, so
 * running the scan five times for the same day leaves one exception per finding,
 * and re-running after a correction updates the figure rather than piling up
 * duplicates. That matters because this runs from cron and from a button.
 *
 * Returns a per-rule tally of what it found.
 */
function audit_scan(string $date, ?int $storeId = null, string $source = 'manual', ?int $runBy = null): array
{
    $runId = db_insert('audit_scan_runs', [
        'period_date'    => $date,
        'store_id'       => $storeId,
        'run_by'         => $runBy,
        'trigger_source' => substr($source, 0, 20),
    ]);

    $found = [];
    $rules = [
        'EXCESSIVE_VOIDS'           => 'audit_rule_excessive_voids',
        'EXCESSIVE_DISCOUNTS'       => 'audit_rule_excessive_discounts',
        'EXCESSIVE_PRICE_OVERRIDES' => 'audit_rule_excessive_overrides',
        'EXCESSIVE_REFUNDS'         => 'audit_rule_excessive_refunds',
        'REFUND_OWN_SALE'           => 'audit_rule_refund_own_sale',
        'AFTER_HOURS_SALES'         => 'audit_rule_after_hours_sales',
        'EXCESSIVE_NO_SALE'         => 'audit_rule_excessive_no_sale',
        'ROUND_SUM_SHORTAGE'        => 'audit_rule_round_sum_shortage',
        'SESSION_UNRECONCILED'      => 'audit_rule_unreconciled_sessions',
        'RECEIPT_GAPS'              => 'audit_rule_receipt_gaps',
        'EXCESSIVE_WASTAGE'         => 'audit_rule_excessive_wastage',
        'NEGATIVE_STOCK'            => 'audit_rule_negative_stock',
        'EXPIRED_ON_SHELF'          => 'audit_rule_expired_on_shelf',
        'LEDGER_MISMATCH'           => 'audit_rule_ledger_mismatch',
        'STALE_COUNT'               => 'audit_rule_stale_count',
        'DRAFT_GRN_STALE'           => 'audit_rule_draft_grn_stale',
        'AUDIT_CHAIN_BROKEN'        => 'audit_rule_chain_broken',
        'AFTER_HOURS_ACCESS'        => 'audit_rule_after_hours_access',
    ];

    $ran = 0;
    foreach ($rules as $code => $fn) {
        if (!audit_rule_enabled($code)) {
            $found[$code] = 'skipped (rule off)';
            continue;
        }
        try {
            // A rule that throws must not stop the ones after it; a scan that
            // stops halfway is worse than one that reports a single failure.
            $found[$code] = $fn($date, $storeId);
            $ran++;
        } catch (Throwable $e) {
            $found[$code] = 'error: ' . $e->getMessage();
            error_log('[TillPoint audit scan] ' . $code . ': ' . $e->getMessage());
        }
    }

    db_update('audit_scan_runs', [
        'rules_run'   => $ran,
        'finished_at' => date('Y-m-d H:i:s'),
        'exceptions_new' => array_sum(array_map(
            fn ($v) => is_int($v) ? $v : 0, $found
        )),
    ], 'id = :id', ['id' => $runId]);

    return $found;
}

/** The stores a scan should cover. */
function audit_scan_stores(?int $storeId): array
{
    return $storeId !== null
        ? [$storeId]
        : array_map('intval', qcol('SELECT id FROM stores WHERE is_active = 1'));
}

/* ---- Individual rules ------------------------------------------- */

function audit_rule_excessive_voids(string $date, ?int $storeId): int
{
    $threshold = audit_rule_threshold('EXCESSIVE_VOIDS', 15.0);
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = qa(
            'SELECT cashier_id,
                    SUM(status = \'voided\') AS voids,
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = \'voided\' THEN total ELSE 0 END) AS void_value
               FROM sales
              WHERE store_id = ? AND business_date = ?
              GROUP BY cashier_id
             HAVING total >= 10 AND voids > 0',
            [$sid, $date]
        );
        foreach ($rows as $r) {
            $pct = ((int) $r['voids'] / max(1, (int) $r['total'])) * 100;
            if ($pct <= $threshold) {
                continue;
            }
            audit_raise_exception('EXCESSIVE_VOIDS', [
                'store_id'     => $sid,
                'subject_user' => (int) $r['cashier_id'],
                'period_date'  => $date,
                'entity'       => 'user',
                'entity_id'    => (int) $r['cashier_id'],
                'metric'       => round($pct, 2),
                'threshold'    => $threshold,
                'summary'      => sprintf('%s voided %d of %d baskets (%s), %s in value',
                    user_name((int) $r['cashier_id']), (int) $r['voids'], (int) $r['total'],
                    pct_fmt($pct), money((float) $r['void_value'])),
                'detail'       => ['voids' => (int) $r['voids'], 'transactions' => (int) $r['total'],
                                   'void_value' => (float) $r['void_value']],
            ]);
            $n++;
        }
    }
    return $n;
}

function audit_rule_excessive_discounts(string $date, ?int $storeId): int
{
    $threshold = audit_rule_threshold('EXCESSIVE_DISCOUNTS', 8.0);
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = qa(
            'SELECT cashier_id, COALESCE(SUM(discount_total),0) AS disc,
                    COALESCE(SUM(total),0) AS sales, COUNT(*) AS txn
               FROM sales
              WHERE store_id = ? AND business_date = ?
                AND status IN (\'completed\',\'refunded\',\'partly_refunded\')
              GROUP BY cashier_id
             HAVING sales > 0 AND disc > 0',
            [$sid, $date]
        );
        foreach ($rows as $r) {
            // Measured against gross before discount, which is what the customer
            // would have paid - the denominator people expect.
            $gross = (float) $r['sales'] + (float) $r['disc'];
            $pct   = $gross > 0 ? ((float) $r['disc'] / $gross) * 100 : 0;
            if ($pct <= $threshold) {
                continue;
            }
            audit_raise_exception('EXCESSIVE_DISCOUNTS', [
                'store_id'     => $sid,
                'subject_user' => (int) $r['cashier_id'],
                'period_date'  => $date,
                'entity'       => 'user',
                'entity_id'    => (int) $r['cashier_id'],
                'metric'       => round($pct, 2),
                'threshold'    => $threshold,
                'summary'      => sprintf('%s gave %s of discount on %s of sales (%s) across %d transactions',
                    user_name((int) $r['cashier_id']), money((float) $r['disc']),
                    money($gross), pct_fmt($pct), (int) $r['txn']),
                'detail'       => ['discount' => (float) $r['disc'], 'gross' => $gross],
            ]);
            $n++;
        }
    }
    return $n;
}

function audit_rule_excessive_overrides(string $date, ?int $storeId): int
{
    $threshold = audit_rule_threshold('EXCESSIVE_PRICE_OVERRIDES', 10.0);
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = qa(
            'SELECT s.cashier_id, COUNT(*) AS overrides,
                    COALESCE(SUM((sl.list_price - sl.unit_price) * sl.qty), 0) AS given_away
               FROM sale_lines sl
               JOIN sales s ON s.id = sl.sale_id
              WHERE s.store_id = ? AND s.business_date = ? AND sl.price_overridden = 1
              GROUP BY s.cashier_id',
            [$sid, $date]
        );
        foreach ($rows as $r) {
            if ((int) $r['overrides'] <= $threshold) {
                continue;
            }
            audit_raise_exception('EXCESSIVE_PRICE_OVERRIDES', [
                'store_id'     => $sid,
                'subject_user' => (int) $r['cashier_id'],
                'period_date'  => $date,
                'entity'       => 'user',
                'entity_id'    => (int) $r['cashier_id'],
                'metric'       => (int) $r['overrides'],
                'threshold'    => $threshold,
                'summary'      => sprintf('%s overrode the shelf price %d times, %s below list in total',
                    user_name((int) $r['cashier_id']), (int) $r['overrides'],
                    money((float) $r['given_away'])),
            ]);
            $n++;
        }
    }
    return $n;
}

function audit_rule_excessive_refunds(string $date, ?int $storeId): int
{
    $threshold = audit_rule_threshold('EXCESSIVE_REFUNDS', 20000.0);
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = qa(
            'SELECT requested_by, COUNT(*) AS cnt, COALESCE(SUM(total),0) AS value
               FROM refunds
              WHERE store_id = ? AND DATE(created_at) = ? AND status = \'posted\'
              GROUP BY requested_by',
            [$sid, $date]
        );
        foreach ($rows as $r) {
            if ((float) $r['value'] <= $threshold) {
                continue;
            }
            audit_raise_exception('EXCESSIVE_REFUNDS', [
                'store_id'     => $sid,
                'subject_user' => (int) $r['requested_by'],
                'period_date'  => $date,
                'entity'       => 'user',
                'entity_id'    => (int) $r['requested_by'],
                'metric'       => (float) $r['value'],
                'threshold'    => $threshold,
                'summary'      => sprintf('%s processed %d refund(s) totalling %s',
                    user_name((int) $r['requested_by']), (int) $r['cnt'], money((float) $r['value'])),
            ]);
            $n++;
        }
    }
    return $n;
}

/**
 * A refund given by the same person who made the sale. On a single-till shift
 * this is unavoidable, so it is reported with the context rather than blocked.
 */
function audit_rule_refund_own_sale(string $date, ?int $storeId): int
{
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = qa(
            'SELECT r.requested_by, COUNT(*) AS cnt, COALESCE(SUM(r.total),0) AS value
               FROM refunds r
               JOIN sales s ON s.id = r.sale_id
              WHERE r.store_id = ? AND DATE(r.created_at) = ? AND r.status = \'posted\'
                AND s.cashier_id = r.requested_by
              GROUP BY r.requested_by',
            [$sid, $date]
        );
        foreach ($rows as $r) {
            audit_raise_exception('REFUND_OWN_SALE', [
                'store_id'     => $sid,
                'subject_user' => (int) $r['requested_by'],
                'period_date'  => $date,
                'entity'       => 'user',
                'entity_id'    => (int) $r['requested_by'],
                'metric'       => (int) $r['cnt'],
                'summary'      => sprintf('%s refunded %d of their own sale(s), %s in total',
                    user_name((int) $r['requested_by']), (int) $r['cnt'], money((float) $r['value'])),
            ]);
            $n++;
        }
    }
    return $n;
}

function audit_rule_after_hours_sales(string $date, ?int $storeId): int
{
    $open  = (int) setting_num('trading_hour_open', 7.0);
    $close = (int) setting_num('trading_hour_close', 22.0);
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = qa(
            'SELECT cashier_id, COUNT(*) AS cnt, COALESCE(SUM(total),0) AS value,
                    MIN(TIME(sold_at)) AS earliest, MAX(TIME(sold_at)) AS latest
               FROM sales
              WHERE store_id = ? AND business_date = ? AND status <> \'parked\'
                AND sold_at IS NOT NULL
                AND (HOUR(sold_at) < ? OR HOUR(sold_at) >= ?)
              GROUP BY cashier_id',
            [$sid, $date, $open, $close]
        );
        foreach ($rows as $r) {
            audit_raise_exception('AFTER_HOURS_SALES', [
                'store_id'     => $sid,
                'subject_user' => (int) $r['cashier_id'],
                'period_date'  => $date,
                'entity'       => 'user',
                'entity_id'    => (int) $r['cashier_id'],
                'metric'       => (int) $r['cnt'],
                'summary'      => sprintf('%s rang up %d transaction(s) worth %s outside %02d:00-%02d:00 (between %s and %s)',
                    user_name((int) $r['cashier_id']), (int) $r['cnt'], money((float) $r['value']),
                    $open, $close, substr((string) $r['earliest'], 0, 5), substr((string) $r['latest'], 0, 5)),
            ]);
            $n++;
        }
    }
    return $n;
}

function audit_rule_excessive_no_sale(string $date, ?int $storeId): int
{
    $threshold = audit_rule_threshold('EXCESSIVE_NO_SALE', 10.0);
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = qa(
            'SELECT cm.user_id, cm.session_id, COUNT(*) AS cnt, ts.session_no
               FROM cash_movements cm
               JOIN till_sessions ts ON ts.id = cm.session_id
              WHERE cm.store_id = ? AND DATE(cm.created_at) = ? AND cm.type = \'no_sale\'
              GROUP BY cm.user_id, cm.session_id, ts.session_no',
            [$sid, $date]
        );
        foreach ($rows as $r) {
            if ((int) $r['cnt'] <= $threshold) {
                continue;
            }
            audit_raise_exception('EXCESSIVE_NO_SALE', [
                'store_id'     => $sid,
                'subject_user' => (int) $r['user_id'],
                'period_date'  => $date,
                'entity'       => 'till_session',
                'entity_id'    => (int) $r['session_id'],
                'metric'       => (int) $r['cnt'],
                'threshold'    => $threshold,
                'summary'      => sprintf('%s opened the drawer %d times without a sale on %s',
                    user_name((int) $r['user_id']), (int) $r['cnt'], (string) $r['session_no']),
            ]);
            $n++;
        }
    }
    return $n;
}

/**
 * A shortage that lands on an exact note multiple.
 *
 * Miscounting change produces awkward amounts; removing notes produces round
 * ones. A shortage of exactly 5,000 is a different kind of event from one of
 * 4,873, and this is the only rule that can tell them apart.
 */
function audit_rule_round_sum_shortage(string $date, ?int $storeId): int
{
    $minimum = audit_rule_threshold('ROUND_SUM_SHORTAGE', 500.0);
    $notes   = [1000, 500, 200]; // Nigerian note denominations worth testing
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = qa(
            'SELECT id, session_no, user_id, variance
               FROM till_sessions
              WHERE store_id = ? AND DATE(opened_at) = ? AND variance IS NOT NULL AND variance < 0',
            [$sid, $date]
        );
        foreach ($rows as $r) {
            $short = abs((float) $r['variance']);
            if ($short < $minimum) {
                continue;
            }
            $round = null;
            foreach ($notes as $note) {
                if (abs(fmod($short, $note)) < 0.005) {
                    $round = $note;
                    break;
                }
            }
            if ($round === null) {
                continue;
            }
            audit_raise_exception('ROUND_SUM_SHORTAGE', [
                'store_id'     => $sid,
                'subject_user' => (int) $r['user_id'],
                'period_date'  => $date,
                'entity'       => 'till_session',
                'entity_id'    => (int) $r['id'],
                'metric'       => $short,
                'threshold'    => $minimum,
                'summary'      => sprintf('%s closed %s short on %s - exactly %d x %s',
                    user_name((int) $r['user_id']), money($short), (string) $r['session_no'],
                    (int) round($short / $round), money((float) $round)),
            ]);
            $n++;
        }
    }
    return $n;
}

function audit_rule_unreconciled_sessions(string $date, ?int $storeId): int
{
    $days = (int) audit_rule_threshold('SESSION_UNRECONCILED', 3.0);
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = qa(
            'SELECT id, session_no, user_id, variance, closed_at,
                    DATEDIFF(CURDATE(), DATE(closed_at)) AS days_open
               FROM till_sessions
              WHERE store_id = ? AND status = \'closed\'
                AND closed_at IS NOT NULL
                AND DATEDIFF(CURDATE(), DATE(closed_at)) >= ?',
            [$sid, $days]
        );
        foreach ($rows as $r) {
            audit_raise_exception('SESSION_UNRECONCILED', [
                'store_id'     => $sid,
                'subject_user' => (int) $r['user_id'],
                'period_date'  => $date,
                'entity'       => 'till_session',
                'entity_id'    => (int) $r['id'],
                'metric'       => (int) $r['days_open'],
                'threshold'    => $days,
                'summary'      => sprintf('%s has had a variance of %s unreconciled for %d day(s)',
                    (string) $r['session_no'], money((float) $r['variance']), (int) $r['days_open']),
            ]);
            $n++;
        }
    }
    return $n;
}

function audit_rule_receipt_gaps(string $date, ?int $storeId): int
{
    $threshold = audit_rule_threshold('RECEIPT_GAPS', 5.0);
    $period    = date('Ym', strtotime($date));
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $gaps = receipt_sequence_gaps($sid, $period);
        if ($gaps['missing'] <= $threshold) {
            continue;
        }
        audit_raise_exception('RECEIPT_GAPS', [
            'store_id'    => $sid,
            'period_date' => $date,
            'entity'      => 'store',
            'entity_id'   => $sid,
            'metric'      => $gaps['missing'],
            'threshold'   => $threshold,
            'summary'     => sprintf('%d receipt number(s) of %d issued this month have no sale behind them',
                $gaps['missing'], $gaps['issued']),
            'detail'      => $gaps,
        ]);
        $n++;
    }
    return $n;
}

function audit_rule_excessive_wastage(string $date, ?int $storeId): int
{
    $threshold = audit_rule_threshold('EXCESSIVE_WASTAGE', 15000.0);
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = qa(
            'SELECT logged_by, COUNT(*) AS cnt, COALESCE(SUM(total_value),0) AS value
               FROM wastage
              WHERE store_id = ? AND DATE(created_at) = ? AND status = \'posted\'
              GROUP BY logged_by',
            [$sid, $date]
        );
        foreach ($rows as $r) {
            if ((float) $r['value'] <= $threshold) {
                continue;
            }
            audit_raise_exception('EXCESSIVE_WASTAGE', [
                'store_id'     => $sid,
                'subject_user' => (int) $r['logged_by'],
                'period_date'  => $date,
                'entity'       => 'user',
                'entity_id'    => (int) $r['logged_by'],
                'metric'       => (float) $r['value'],
                'threshold'    => $threshold,
                'summary'      => sprintf('%s wrote off %s across %d record(s)',
                    user_name((int) $r['logged_by']), money((float) $r['value']), (int) $r['cnt']),
            ]);
            $n++;
        }
    }
    return $n;
}

function audit_rule_negative_stock(string $date, ?int $storeId): int
{
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = qa(
            'SELECT sl.product_id, p.sku, p.name, sl.qty_on_hand
               FROM stock_levels sl JOIN products p ON p.id = sl.product_id
              WHERE sl.store_id = ? AND sl.qty_on_hand < -0.0005
              ORDER BY sl.qty_on_hand ASC',
            [$sid]
        );
        if (!$rows) {
            continue;
        }
        audit_raise_exception('NEGATIVE_STOCK', [
            'store_id'    => $sid,
            'period_date' => $date,
            'entity'      => 'store',
            'entity_id'   => $sid,
            'metric'      => count($rows),
            'summary'     => sprintf('%d product line(s) show a negative position, worst: %s at %s',
                count($rows), $rows[0]['name'], qty_fmt((float) $rows[0]['qty_on_hand'])),
            'detail'      => array_slice($rows, 0, 25),
        ]);
        $n++;
    }
    return $n;
}

function audit_rule_expired_on_shelf(string $date, ?int $storeId): int
{
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = qa(
            'SELECT b.id, b.batch_no, b.expiry_date, b.qty_on_hand, b.unit_cost,
                    p.name, b.qty_on_hand * b.unit_cost AS value
               FROM stock_batches b JOIN products p ON p.id = b.product_id
              WHERE b.store_id = ? AND b.qty_on_hand > 0
                AND b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE()
              ORDER BY b.expiry_date ASC',
            [$sid]
        );
        if (!$rows) {
            continue;
        }
        $value = array_sum(array_map(fn ($r) => (float) $r['value'], $rows));
        audit_raise_exception('EXPIRED_ON_SHELF', [
            'store_id'    => $sid,
            'period_date' => $date,
            'entity'      => 'store',
            'entity_id'   => $sid,
            'metric'      => count($rows),
            'summary'     => sprintf('%d expired batch(es) still show stock on hand, %s at cost - oldest %s expired %s',
                count($rows), money($value), $rows[0]['name'], date_fmt((string) $rows[0]['expiry_date'])),
            'detail'      => array_slice($rows, 0, 25),
        ]);
        $n++;
    }
    return $n;
}

function audit_rule_ledger_mismatch(string $date, ?int $storeId): int
{
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = stock_ledger_discrepancies($sid);
        if (!$rows) {
            continue;
        }
        audit_raise_exception('LEDGER_MISMATCH', [
            'store_id'    => $sid,
            'period_date' => $date,
            'entity'      => 'store',
            'entity_id'   => $sid,
            'metric'      => count($rows),
            'summary'     => sprintf('%d product line(s) disagree with their own movement history, worst %s off on %s',
                count($rows), qty_fmt((float) $rows[0]['difference']), $rows[0]['name']),
            'detail'      => array_slice($rows, 0, 25),
        ]);
        $n++;
    }
    return $n;
}

function audit_rule_stale_count(string $date, ?int $storeId): int
{
    $days     = (int) audit_rule_threshold('STALE_COUNT', 90.0);
    $minValue = setting_num('stale_count_min_value', 50000.0);
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = qa(
            'SELECT sl.product_id, p.name, sl.qty_on_hand, sl.avg_cost,
                    sl.qty_on_hand * sl.avg_cost AS value, sl.last_counted_at
               FROM stock_levels sl JOIN products p ON p.id = sl.product_id
              WHERE sl.store_id = ? AND sl.qty_on_hand > 0
                AND (sl.last_counted_at IS NULL OR sl.last_counted_at < (CURDATE() - INTERVAL ? DAY))
              HAVING value >= ?
              ORDER BY value DESC',
            [$sid, $days, $minValue]
        );
        if (!$rows) {
            continue;
        }
        $value = array_sum(array_map(fn ($r) => (float) $r['value'], $rows));
        audit_raise_exception('STALE_COUNT', [
            'store_id'    => $sid,
            'period_date' => $date,
            'entity'      => 'store',
            'entity_id'   => $sid,
            'metric'      => count($rows),
            'threshold'   => $days,
            'summary'     => sprintf('%d high-value line(s) worth %s have not been counted in %d days',
                count($rows), money($value), $days),
            'detail'      => array_slice($rows, 0, 25),
        ]);
        $n++;
    }
    return $n;
}

function audit_rule_draft_grn_stale(string $date, ?int $storeId): int
{
    $days = (int) audit_rule_threshold('DRAFT_GRN_STALE', 3.0);
    $n = 0;
    foreach (audit_scan_stores($storeId) as $sid) {
        $rows = qa(
            'SELECT g.id, g.grn_no, g.total, g.received_by, g.received_date,
                    DATEDIFF(CURDATE(), g.received_date) AS age
               FROM grns g
              WHERE g.store_id = ? AND g.status = \'draft\'
                AND DATEDIFF(CURDATE(), g.received_date) >= ?',
            [$sid, $days]
        );
        foreach ($rows as $r) {
            audit_raise_exception('DRAFT_GRN_STALE', [
                'store_id'     => $sid,
                'subject_user' => (int) $r['received_by'],
                'period_date'  => $date,
                'entity'       => 'grn',
                'entity_id'    => (int) $r['id'],
                'metric'       => (int) $r['age'],
                'threshold'    => $days,
                'summary'      => sprintf('Goods receipt %s (%s) has been unposted for %d day(s)',
                    (string) $r['grn_no'], money((float) $r['total']), (int) $r['age']),
            ]);
            $n++;
        }
    }
    return $n;
}

/**
 * Verify the trail. The only rule whose failure undermines every other rule, so
 * it is raised without a store scope and at critical severity.
 */
function audit_rule_chain_broken(string $date, ?int $storeId): int
{
    $res = audit_record_integrity_check(null, 'Automatic check during the daily exception scan');
    if ($res['result'] !== 'broken') {
        return 0;
    }
    audit_raise_exception('AUDIT_CHAIN_BROKEN', [
        'store_id'    => null,
        'period_date' => $date,
        'entity'      => 'audit_log',
        'entity_id'   => $res['broken_at_id'],
        'metric'      => (float) $res['broken_at_id'],
        'summary'     => 'Audit trail integrity failure at entry #' . $res['broken_at_id'] . '. ' . $res['detail'],
        'detail'      => $res,
    ]);
    return 1;
}

function audit_rule_after_hours_access(string $date, ?int $storeId): int
{
    $threshold = audit_rule_threshold('AFTER_HOURS_ACCESS', 20.0);
    $open      = (int) setting_num('trading_hour_open', 7.0);
    $close     = (int) setting_num('trading_hour_close', 22.0);

    // Only the actions that change money, price or access are interesting; a
    // manager reading a report at midnight is not a finding.
    $rows = qa(
        'SELECT user_id, COUNT(*) AS cnt, MIN(created_at) AS first_at, MAX(created_at) AS last_at
           FROM audit_log
          WHERE DATE(created_at) = ?
            AND user_id IS NOT NULL
            AND (HOUR(created_at) < ? OR HOUR(created_at) >= ?)
            AND (action LIKE \'product.%\' OR action LIKE \'adjustment.%\'
                 OR action LIKE \'user.%\' OR action LIKE \'wastage.%\'
                 OR action LIKE \'refund.%\' OR action LIKE \'setting.%\')
          GROUP BY user_id',
        [$date, $open, $close]
    );
    $n = 0;
    foreach ($rows as $r) {
        if ((int) $r['cnt'] <= $threshold) {
            continue;
        }
        audit_raise_exception('AFTER_HOURS_ACCESS', [
            'store_id'     => $storeId,
            'subject_user' => (int) $r['user_id'],
            'period_date'  => $date,
            'entity'       => 'user',
            'entity_id'    => (int) $r['user_id'],
            'metric'       => (int) $r['cnt'],
            'threshold'    => $threshold,
            'summary'      => sprintf('%s made %d pricing, stock or access change(s) outside trading hours (%s to %s)',
                user_name((int) $r['user_id']), (int) $r['cnt'],
                date_fmt((string) $r['first_at'], true), date_fmt((string) $r['last_at'], true)),
        ]);
        $n++;
    }
    return $n;
}

/* ================================================================== */
/* Review workflow                                                    */
/* ================================================================== */

/**
 * Record a reviewer's decision.
 *
 * Every decision is appended to audit_reviews and kept even when the status
 * changes again later, so "who cleared this, and on what grounds" survives a
 * second reviewer disagreeing with the first.
 */
function audit_dispose_exception(int $exceptionId, int $reviewerId, string $action, string $note): void
{
    db_tx(function () use ($exceptionId, $reviewerId, $action, $note): void {
        $ex = db_lock_row('audit_exceptions', 'id = :id', ['id' => $exceptionId]);
        if (!$ex) {
            throw new RuntimeException('That exception no longer exists.');
        }
        $action = one_of($action, ['reviewing', 'cleared', 'confirmed', 'escalated'], 'reviewing');

        // Clearing or confirming a finding is a judgement someone must stand
        // behind, so it cannot be done silently.
        if (in_array($action, ['cleared', 'confirmed', 'escalated'], true) && trim($note) === '') {
            throw new RuntimeException('Record the grounds for this decision before saving it.');
        }

        db_update('audit_exceptions', [
            'status'           => $action,
            'reviewed_by'      => $reviewerId,
            'reviewed_at'      => date('Y-m-d H:i:s'),
            'disposition'      => $action,
            'disposition_note' => substr($note, 0, 500),
        ], 'id = :id', ['id' => $exceptionId]);

        db_insert('audit_reviews', [
            'exception_id' => $exceptionId,
            'reviewer_id'  => $reviewerId,
            'action'       => $action,
            'note'         => substr($note, 0, 500),
        ]);

        audit_log('audit.dispose', 'audit_exception', $exceptionId,
            sprintf('Exception %s [%s] marked %s: %s', $ex['rule_code'], $ex['summary'], $action, $note),
            ['status' => $ex['status']], ['status' => $action],
            $action === 'confirmed' ? 'critical' : 'notice',
            $ex['store_id'] !== null ? (int) $ex['store_id'] : null);

        if ($action === 'confirmed') {
            notify_role('admin', 'critical', 'Audit finding confirmed',
                $ex['summary'], base_url('audit/exception', ['id' => $exceptionId]));
        }
    });
}

/** Names, cached, so a rule summary can read naturally without N queries. */
function user_name(?int $userId): string
{
    static $cache = [];
    if ($userId === null) {
        return 'System';
    }
    if (!isset($cache[$userId])) {
        $cache[$userId] = (string) qv('SELECT full_name FROM users WHERE id = ?', [$userId], "User #$userId");
    }
    return $cache[$userId];
}
