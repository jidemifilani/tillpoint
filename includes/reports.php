<?php
/**
 * Reporting queries.
 *
 * Kept out of the page files so the same figure is computed in exactly one
 * place. A dashboard tile and a report that disagree about today's takings
 * destroy confidence in both, and that happens the moment the same SUM is
 * written twice.
 *
 * One rule throughout: a sale counts as revenue when its status is completed,
 * refunded or partly_refunded - never when parked (not yet paid for) or voided
 * (abandoned). Refunds are then subtracted separately, so gross and net are
 * both visible rather than netted off invisibly.
 */
declare(strict_types=1);

/** The status set that counts as a real sale. Used verbatim everywhere. */
const SALE_REAL_STATUSES = "('completed','refunded','partly_refunded')";

/* ------------------------------------------------------------------ */
/* Dashboard                                                          */
/* ------------------------------------------------------------------ */

function report_day_summary(int $storeId, string $date): array
{
    $sales = q1(
        'SELECT COUNT(*) AS txn,
                COALESCE(SUM(total),0) AS gross,
                COALESCE(SUM(subtotal),0) AS net,
                COALESCE(SUM(vat_total),0) AS vat,
                COALESCE(SUM(discount_total),0) AS discounts,
                COALESCE(SUM(cost_total),0) AS cost,
                COALESCE(SUM(line_count),0) AS items
           FROM sales
          WHERE store_id = ? AND business_date = ? AND status IN ' . SALE_REAL_STATUSES,
        [$storeId, $date]
    );
    $refunds = q1(
        'SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS value
           FROM refunds WHERE store_id = ? AND DATE(created_at) = ? AND status = \'posted\'',
        [$storeId, $date]
    );
    $voids = q1(
        'SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS value
           FROM sales WHERE store_id = ? AND business_date = ? AND status = \'voided\'',
        [$storeId, $date]
    );
    $byMethod = qpairs(
        'SELECT sp.method, COALESCE(SUM(sp.amount),0) FROM sale_payments sp
           JOIN sales sa ON sa.id = sp.sale_id
          WHERE sa.store_id = ? AND sa.business_date = ? AND sa.status IN ' . SALE_REAL_STATUSES . '
          GROUP BY sp.method',
        [$storeId, $date]
    );

    $gross = (float) $sales['gross'];
    $net   = (float) $sales['net'];
    $cost  = (float) $sales['cost'];
    $txn   = (int) $sales['txn'];

    return [
        'date'         => $date,
        'txn_count'    => $txn,
        'item_count'   => (float) $sales['items'],
        'gross_sales'  => $gross,
        'net_sales'    => $net,
        'vat_total'    => (float) $sales['vat'],
        'discounts'    => (float) $sales['discounts'],
        'cost_total'   => $cost,
        'gross_profit' => money_round($net - $cost),
        'margin_pct'   => $net > 0 ? (($net - $cost) / $net) * 100 : 0.0,
        'avg_basket'   => $txn > 0 ? money_round($gross / $txn) : 0.0,
        'refund_count' => (int) $refunds['n'],
        'refund_value' => (float) $refunds['value'],
        'void_count'   => (int) $voids['n'],
        'void_value'   => (float) $voids['value'],
        'by_method'    => $byMethod,
    ];
}

/** Daily takings over a window, for the dashboard trend. */
function report_sales_trend(int $storeId, int $days = 14): array
{
    return qa(
        'SELECT business_date,
                COUNT(*) AS txn,
                COALESCE(SUM(total),0) AS gross,
                COALESCE(SUM(subtotal) - SUM(cost_total),0) AS profit
           FROM sales
          WHERE store_id = ? AND status IN ' . SALE_REAL_STATUSES . '
            AND business_date >= (CURDATE() - INTERVAL ? DAY)
          GROUP BY business_date
          ORDER BY business_date ASC',
        [$storeId, $days]
    );
}

/** Takings by hour, to staff the tills against the queue. */
function report_hourly_sales(int $storeId, string $date): array
{
    return qa(
        'SELECT HOUR(sold_at) AS hr, COUNT(*) AS txn, COALESCE(SUM(total),0) AS gross
           FROM sales
          WHERE store_id = ? AND business_date = ? AND sold_at IS NOT NULL
            AND status IN ' . SALE_REAL_STATUSES . '
          GROUP BY HOUR(sold_at) ORDER BY hr',
        [$storeId, $date]
    );
}

function report_top_products(int $storeId, string $from, string $to, int $limit = 15): array
{
    return qa(
        'SELECT p.id, p.sku, p.name, c.name AS category,
                SUM(sl.qty) AS units,
                COALESCE(SUM(sl.line_total),0) AS revenue,
                COALESCE(SUM(sl.qty * sl.unit_cost),0) AS cost,
                COALESCE(SUM(sl.line_total - sl.vat_amount - (sl.qty * sl.unit_cost)),0) AS profit
           FROM sale_lines sl
           JOIN sales s ON s.id = sl.sale_id
           JOIN products p ON p.id = sl.product_id
           JOIN categories c ON c.id = p.category_id
          WHERE s.store_id = ? AND s.business_date BETWEEN ? AND ?
            AND s.status IN ' . SALE_REAL_STATUSES . '
          GROUP BY p.id, p.sku, p.name, c.name
          ORDER BY revenue DESC
          LIMIT ' . (int) $limit,
        [$storeId, $from, $to]
    );
}

/**
 * Gross profit by category.
 *
 * Profit is computed from the NET revenue (VAT removed) less cost, because VAT
 * is the state's money passing through the till - counting it as margin would
 * overstate profit by the VAT rate on every line.
 */
function report_margin_by_category(int $storeId, string $from, string $to): array
{
    return qa(
        'SELECT c.id, c.name, c.target_margin_pct,
                COALESCE(SUM(sl.line_total),0) AS gross_revenue,
                COALESCE(SUM(sl.line_total - sl.vat_amount),0) AS net_revenue,
                COALESCE(SUM(sl.qty * sl.unit_cost),0) AS cost,
                COALESCE(SUM(sl.line_discount),0) AS discounts,
                SUM(sl.qty) AS units
           FROM sale_lines sl
           JOIN sales s ON s.id = sl.sale_id
           JOIN products p ON p.id = sl.product_id
           JOIN categories c ON c.id = p.category_id
          WHERE s.store_id = ? AND s.business_date BETWEEN ? AND ?
            AND s.status IN ' . SALE_REAL_STATUSES . '
          GROUP BY c.id, c.name, c.target_margin_pct
          ORDER BY net_revenue DESC',
        [$storeId, $from, $to]
    );
}

/* ------------------------------------------------------------------ */
/* Cashier scorecard - the central audit report                       */
/* ------------------------------------------------------------------ */

/**
 * One row per cashier with the ratios the exception rules are built on.
 *
 * The value of this report is comparative. A 6% void rate means nothing alone;
 * it means a great deal when everyone else is at 1%. So the figures are returned
 * raw alongside the shop average, and the page shows both - a cashier on the
 * busiest till at the busiest hour will always look worse on absolutes.
 */
function report_cashier_scorecard(int $storeId, string $from, string $to): array
{
    $rows = qa(
        'SELECT u.id, u.full_name, u.staff_no,
                COUNT(DISTINCT s.id) AS txn,
                COALESCE(SUM(CASE WHEN s.status IN ' . SALE_REAL_STATUSES . ' THEN s.total ELSE 0 END),0) AS sales,
                SUM(s.status = \'voided\') AS voids,
                COALESCE(SUM(CASE WHEN s.status = \'voided\' THEN s.total ELSE 0 END),0) AS void_value,
                COALESCE(SUM(s.discount_total),0) AS discounts,
                COALESCE(SUM(s.line_count),0) AS items
           FROM users u
           JOIN sales s ON s.cashier_id = u.id
          WHERE s.store_id = ? AND s.business_date BETWEEN ? AND ?
          GROUP BY u.id, u.full_name, u.staff_no
          ORDER BY sales DESC',
        [$storeId, $from, $to]
    );

    // Refunds, overrides and variances are per-cashier too, but each lives in a
    // different table; joining them all into the query above would multiply the
    // row counts, so they are gathered separately and merged by id.
    $refunds = qpairs(
        'SELECT requested_by, COALESCE(SUM(total),0) FROM refunds
          WHERE store_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = \'posted\'
          GROUP BY requested_by',
        [$storeId, $from, $to]
    );
    $refundCounts = qpairs(
        'SELECT requested_by, COUNT(*) FROM refunds
          WHERE store_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = \'posted\'
          GROUP BY requested_by',
        [$storeId, $from, $to]
    );
    $overrides = qpairs(
        'SELECT s.cashier_id, COUNT(*) FROM sale_lines sl
           JOIN sales s ON s.id = sl.sale_id
          WHERE s.store_id = ? AND s.business_date BETWEEN ? AND ? AND sl.price_overridden = 1
          GROUP BY s.cashier_id',
        [$storeId, $from, $to]
    );
    $variances = qa(
        'SELECT user_id, COUNT(*) AS shifts,
                COALESCE(SUM(ABS(variance)),0) AS abs_variance,
                COALESCE(SUM(variance),0) AS net_variance
           FROM till_sessions
          WHERE store_id = ? AND DATE(opened_at) BETWEEN ? AND ? AND variance IS NOT NULL
          GROUP BY user_id',
        [$storeId, $from, $to]
    );
    $varByUser = [];
    foreach ($variances as $v) {
        $varByUser[(int) $v['user_id']] = $v;
    }
    $noSales = qpairs(
        'SELECT user_id, COUNT(*) FROM cash_movements
          WHERE store_id = ? AND DATE(created_at) BETWEEN ? AND ? AND type = \'no_sale\'
          GROUP BY user_id',
        [$storeId, $from, $to]
    );
    $exceptions = qpairs(
        'SELECT subject_user_id, COUNT(*) FROM audit_exceptions
          WHERE store_id = ? AND period_date BETWEEN ? AND ? AND subject_user_id IS NOT NULL
            AND status <> \'cleared\'
          GROUP BY subject_user_id',
        [$storeId, $from, $to]
    );

    $out = [];
    foreach ($rows as $r) {
        $id    = (int) $r['id'];
        $txn   = (int) $r['txn'];
        $sales = (float) $r['sales'];
        $gross = $sales + (float) $r['discounts'];
        $var   = $varByUser[$id] ?? null;

        $out[] = [
            'user_id'        => $id,
            'name'           => (string) $r['full_name'],
            'staff_no'       => (string) $r['staff_no'],
            'txn'            => $txn,
            'sales'          => $sales,
            'items'          => (float) $r['items'],
            'avg_basket'     => $txn > 0 ? money_round($sales / $txn) : 0.0,
            'voids'          => (int) $r['voids'],
            'void_value'     => (float) $r['void_value'],
            'void_pct'       => $txn > 0 ? ((int) $r['voids'] / $txn) * 100 : 0.0,
            'discounts'      => (float) $r['discounts'],
            'discount_pct'   => $gross > 0 ? ((float) $r['discounts'] / $gross) * 100 : 0.0,
            'refund_value'   => (float) ($refunds[$id] ?? 0),
            'refund_count'   => (int) ($refundCounts[$id] ?? 0),
            'refund_pct'     => $sales > 0 ? ((float) ($refunds[$id] ?? 0) / $sales) * 100 : 0.0,
            'overrides'      => (int) ($overrides[$id] ?? 0),
            'no_sales'       => (int) ($noSales[$id] ?? 0),
            'shifts'         => $var ? (int) $var['shifts'] : 0,
            'abs_variance'   => $var ? (float) $var['abs_variance'] : 0.0,
            'net_variance'   => $var ? (float) $var['net_variance'] : 0.0,
            'open_exceptions'=> (int) ($exceptions[$id] ?? 0),
        ];
    }
    return $out;
}

/** Shop-wide averages for the comparison column on the scorecard. */
function report_scorecard_averages(array $scorecard): array
{
    $n = count($scorecard);
    if ($n === 0) {
        return ['void_pct' => 0.0, 'discount_pct' => 0.0, 'refund_pct' => 0.0, 'avg_basket' => 0.0];
    }
    $txn   = array_sum(array_column($scorecard, 'txn'));
    $sales = array_sum(array_column($scorecard, 'sales'));
    $disc  = array_sum(array_column($scorecard, 'discounts'));
    $voids = array_sum(array_column($scorecard, 'voids'));
    $refs  = array_sum(array_column($scorecard, 'refund_value'));
    $gross = $sales + $disc;

    return [
        'void_pct'     => $txn > 0 ? ($voids / $txn) * 100 : 0.0,
        'discount_pct' => $gross > 0 ? ($disc / $gross) * 100 : 0.0,
        'refund_pct'   => $sales > 0 ? ($refs / $sales) * 100 : 0.0,
        'avg_basket'   => $txn > 0 ? money_round($sales / $txn) : 0.0,
    ];
}

/* ------------------------------------------------------------------ */
/* Shrinkage                                                          */
/* ------------------------------------------------------------------ */

/**
 * Where stock went, by cause, over a period.
 *
 * Read straight off the movement ledger rather than from the documents, because
 * the ledger is the thing that balances. Sales are expected loss; wastage is
 * accounted loss; count variance and adjustment are unexplained loss, and that
 * last pair is what "shrinkage" actually means.
 */
function report_shrinkage(int $storeId, string $from, string $to): array
{
    $rows = qa(
        'SELECT movement_type,
                COALESCE(SUM(qty_delta),0) AS units,
                COALESCE(SUM(value_delta),0) AS value,
                COUNT(*) AS movements
           FROM stock_movements
          WHERE store_id = ? AND DATE(created_at) BETWEEN ? AND ?
          GROUP BY movement_type',
        [$storeId, $from, $to]
    );
    $byType = [];
    foreach ($rows as $r) {
        $byType[(string) $r['movement_type']] = $r;
    }

    $val = static fn (string $t): float => isset($byType[$t]) ? abs((float) $byType[$t]['value']) : 0.0;

    $sold       = $val('sale');
    $wastage    = $val('wastage');
    $countVar   = isset($byType['count_variance']) ? (float) $byType['count_variance']['value'] : 0.0;
    $adjustment = isset($byType['adjustment']) ? (float) $byType['adjustment']['value'] : 0.0;

    // Only the losing side of count variance and adjustment is shrinkage; a
    // positive variance is a found item or an earlier error, not a loss.
    $unexplained = abs(min(0.0, $countVar)) + abs(min(0.0, $adjustment));

    return [
        'cost_of_sales'     => $sold,
        'wastage'           => $wastage,
        'count_variance'    => $countVar,
        'adjustments'       => $adjustment,
        'unexplained_loss'  => money_round($unexplained),
        'accounted_loss'    => money_round($wastage),
        'total_loss'        => money_round($unexplained + $wastage),
        'shrinkage_pct'     => $sold > 0 ? (($unexplained + $wastage) / $sold) * 100 : 0.0,
        'by_type'           => $byType,
    ];
}

/** Wastage grouped by reason, so the biggest cause is obvious. */
function report_wastage_by_reason(int $storeId, string $from, string $to): array
{
    return qa(
        'SELECT w.reason_code, COALESCE(rc.label, w.reason_code) AS label,
                COUNT(*) AS records, COALESCE(SUM(w.qty),0) AS units,
                COALESCE(SUM(w.total_value),0) AS value
           FROM wastage w
           LEFT JOIN reason_codes rc ON rc.code = w.reason_code
          WHERE w.store_id = ? AND DATE(w.created_at) BETWEEN ? AND ? AND w.status = \'posted\'
          GROUP BY w.reason_code, label
          ORDER BY value DESC',
        [$storeId, $from, $to]
    );
}

/* ------------------------------------------------------------------ */
/* VAT                                                                */
/* ------------------------------------------------------------------ */

/**
 * Output VAT on sales less input VAT on purchases, which is the figure that gets
 * remitted. Refunds reduce output VAT, so they are netted off rather than shown
 * separately - the tax authority wants the net.
 */
function report_vat_summary(int $storeId, string $from, string $to): array
{
    $output = q1(
        'SELECT COALESCE(SUM(vat_total),0) AS vat, COALESCE(SUM(subtotal),0) AS net
           FROM sales
          WHERE store_id = ? AND business_date BETWEEN ? AND ? AND status IN ' . SALE_REAL_STATUSES,
        [$storeId, $from, $to]
    );
    $refundVat = (float) qv(
        'SELECT COALESCE(SUM(rl.vat_amount),0) FROM refund_lines rl
           JOIN refunds r ON r.id = rl.refund_id
          WHERE r.store_id = ? AND DATE(r.created_at) BETWEEN ? AND ? AND r.status = \'posted\'',
        [$storeId, $from, $to], 0.0
    );
    $input = q1(
        'SELECT COALESCE(SUM(vat_total),0) AS vat, COALESCE(SUM(subtotal),0) AS net
           FROM grns
          WHERE store_id = ? AND received_date BETWEEN ? AND ? AND status = \'posted\'',
        [$storeId, $from, $to]
    );

    $outVat = (float) $output['vat'] - $refundVat;
    $inVat  = (float) $input['vat'];

    return [
        'sales_net'     => (float) $output['net'],
        'output_vat'    => money_round($outVat),
        'refund_vat'    => money_round($refundVat),
        'purchases_net' => (float) $input['net'],
        'input_vat'     => money_round($inVat),
        'payable'       => money_round($outVat - $inVat),
    ];
}

/* ------------------------------------------------------------------ */
/* Suppliers                                                          */
/* ------------------------------------------------------------------ */

/**
 * Supplier performance.
 *
 * Fill rate is received against ordered, and lateness is the receipt date
 * against the expected date. Both read off real documents rather than a
 * subjective rating, so the scorecard survives a change of buyer.
 */
function report_supplier_performance(int $storeId, string $from, string $to): array
{
    return qa(
        'SELECT sup.id, sup.code, sup.name, sup.lead_time_days,
                COUNT(DISTINCT po.id) AS orders,
                COALESCE(SUM(pol.qty_ordered),0) AS ordered,
                COALESCE(SUM(pol.qty_received),0) AS received,
                COALESCE(SUM(pol.qty_ordered * pol.unit_cost),0) AS order_value,
                SUM(CASE WHEN po.status = \'received\' THEN 1 ELSE 0 END) AS completed,
                COALESCE(AVG(CASE WHEN g.received_date IS NOT NULL AND po.expected_date IS NOT NULL
                              THEN DATEDIFF(g.received_date, po.expected_date) END), 0) AS avg_days_late
           FROM suppliers sup
           JOIN purchase_orders po ON po.supplier_id = sup.id
           JOIN purchase_order_lines pol ON pol.po_id = po.id
           LEFT JOIN grns g ON g.po_id = po.id AND g.status = \'posted\'
          WHERE po.store_id = ? AND po.order_date BETWEEN ? AND ?
            AND po.status <> \'cancelled\'
          GROUP BY sup.id, sup.code, sup.name, sup.lead_time_days
          ORDER BY order_value DESC',
        [$storeId, $from, $to]
    );
}

/* ------------------------------------------------------------------ */
/* Day end                                                            */
/* ------------------------------------------------------------------ */

/**
 * Close a trading day: freeze the figures into day_end_closures.
 *
 * Once closed, the day's numbers are fixed. A late correction has to be a dated
 * adjustment rather than a quiet edit, which is the whole reason the snapshot is
 * stored rather than recomputed - a report that silently changes months later is
 * not evidence of anything.
 */
function day_end_close(int $storeId, string $date, int $userId): array
{
    return db_tx(function () use ($storeId, $date, $userId): array {
        $existing = q1('SELECT * FROM day_end_closures WHERE store_id = ? AND business_date = ? FOR UPDATE',
            [$storeId, $date]);
        if ($existing && $existing['status'] === 'closed') {
            throw new RuntimeException('That trading day was already closed on '
                . date_fmt((string) $existing['closed_at'], true) . '.');
        }

        $openSessions = (int) qv(
            'SELECT COUNT(*) FROM till_sessions WHERE store_id = ? AND status = \'open\'
               AND DATE(opened_at) <= ?',
            [$storeId, $date], 0
        );
        if ($openSessions > 0) {
            throw new RuntimeException("There are still $openSessions open till session(s). "
                . 'Every till must be closed before the day can be.');
        }

        $s    = report_day_summary($storeId, $date);
        $cash = q1(
            'SELECT COALESCE(SUM(expected_cash),0) AS expected, COALESCE(SUM(declared_cash),0) AS declared,
                    COALESCE(SUM(variance),0) AS variance
               FROM till_sessions WHERE store_id = ? AND DATE(opened_at) = ?',
            [$storeId, $date]
        );

        $data = [
            'store_id'          => $storeId,
            'business_date'     => $date,
            'status'            => 'closed',
            'gross_sales'       => $s['gross_sales'],
            'discount_total'    => $s['discounts'],
            'refund_total'      => $s['refund_value'],
            'net_sales'         => money_round($s['gross_sales'] - $s['refund_value']),
            'vat_total'         => $s['vat_total'],
            'cost_total'        => $s['cost_total'],
            'gross_profit'      => $s['gross_profit'],
            'cash_expected'     => (float) $cash['expected'],
            'cash_declared'     => (float) $cash['declared'],
            'cash_variance'     => (float) $cash['variance'],
            'transaction_count' => $s['txn_count'],
            'void_count'        => $s['void_count'],
            'closed_by'         => $userId,
            'closed_at'         => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            unset($data['store_id'], $data['business_date']);
            db_update('day_end_closures', $data, 'id = :id', ['id' => (int) $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $id = db_insert('day_end_closures', $data);
        }

        audit_log('dayend.close', 'day_end', $id,
            sprintf('Trading day %s closed: %s gross across %d transactions, cash variance %s',
                date_fmt($date), money($s['gross_sales']), $s['txn_count'], money((float) $cash['variance'])),
            null, $data, 'notice', $storeId);

        // Scan the day we have just closed - the figures are final, so this is
        // the right moment to look for patterns in them.
        audit_scan($date, $storeId, 'day_end', $userId);

        return $data;
    });
}

function day_end_status(int $storeId, string $date): ?array
{
    // The closer's name comes back with the row: every caller that shows a
    // closure also shows who signed it off, and resolving it in each page would
    // mean four places doing the same join.
    return q1(
        'SELECT dc.*, u.full_name AS closed_by_name
           FROM day_end_closures dc
           LEFT JOIN users u ON u.id = dc.closed_by
          WHERE dc.store_id = ? AND dc.business_date = ?',
        [$storeId, $date]
    );
}
