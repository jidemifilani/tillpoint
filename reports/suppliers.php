<?php
/**
 * Supplier scorecard.
 *
 * Every figure is earned from documents - orders raised, goods received, dates
 * promised against dates met - rather than from an opinion. That matters because
 * a subjective rating follows whoever maintains it, and this has to survive a
 * change of buyer and be defensible in a negotiation.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('reports.view');

$storeId = current_store_id();
$from    = date_or_null(get('from')) ?? date('Y-m-d', strtotime('-180 days'));
$to      = date_or_null(get('to')) ?? date('Y-m-d');

$rows = report_supplier_performance($storeId, $from, $to);

// Cost variance and short deliveries come from the receipts rather than the
// orders, so they are gathered separately and merged by supplier id.
$costVariance = qpairs(
    "SELECT po.supplier_id,
            COALESCE(SUM((gl.unit_cost - pol.unit_cost) * gl.qty), 0) AS over_agreed
       FROM grn_lines gl
       JOIN grns g ON g.id = gl.grn_id
       JOIN purchase_order_lines pol ON pol.id = gl.po_line_id
       JOIN purchase_orders po ON po.id = pol.po_id
      WHERE g.store_id = ? AND g.status = 'posted'
        AND g.received_date BETWEEN ? AND ?
      GROUP BY po.supplier_id",
    [$storeId, $from, $to]
);
$exceptions = qpairs(
    "SELECT po.supplier_id, COUNT(DISTINCT ae.id)
       FROM audit_exceptions ae
       JOIN grns g ON g.id = ae.entity_id AND ae.entity = 'grn'
       JOIN purchase_orders po ON po.id = g.po_id
      WHERE ae.rule_code IN ('GRN_OVER_RECEIPT','GRN_COST_VARIANCE')
        AND ae.period_date BETWEEN ? AND ?
      GROUP BY po.supplier_id",
    [$from, $to]
);
$spend = qpairs(
    "SELECT supplier_id, COALESCE(SUM(total), 0) FROM grns
      WHERE store_id = ? AND status = 'posted' AND received_date BETWEEN ? AND ?
      GROUP BY supplier_id",
    [$storeId, $from, $to]
);

$totalSpend = array_sum(array_map('floatval', $spend));

if ((string) get('export') === 'csv') {
    audit_log('reports.supplier_export', 'supplier', null,
        sprintf('Exported the supplier scorecard for %s to %s', $from, $to), null, null, 'notice');
    send_csv('supplier-scorecard-' . $from . '-to-' . $to . '.csv', [
        'Code', 'Supplier', 'Orders', 'Completed', 'Qty ordered', 'Qty received',
        'Fill rate %', 'Order value', 'Spend received', 'Agreed lead days',
        'Avg days late', 'Over agreed cost', 'Receiving exceptions',
    ], array_map(function ($r) use ($costVariance, $exceptions, $spend) {
        $sid     = (int) $r['id'];
        $ordered = (float) $r['ordered'];
        return [
            $r['code'], $r['name'], $r['orders'], $r['completed'],
            $ordered, $r['received'],
            $ordered > 0 ? round(((float) $r['received'] / $ordered) * 100, 2) : '',
            $r['order_value'], (float) ($spend[$sid] ?? 0),
            $r['lead_time_days'], round((float) $r['avg_days_late'], 1),
            (float) ($costVariance[$sid] ?? 0), (int) ($exceptions[$sid] ?? 0),
        ];
    }, $rows));
}

layout_head([
    'title'    => 'Supplier scorecard',
    'subtitle' => date_fmt($from) . ' to ' . date_fmt($to),
    'actions'  => '<a class="btn btn--ghost" href="' . e(url_with(['export' => 'csv'])) . '">Export CSV</a>'
                . '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">From</span>
    <input type="date" name="from" value="<?= e($from) ?>">
  </label>
  <label class="field">
    <span class="field__label">To</span>
    <input type="date" name="to" value="<?= e($to) ?>">
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('reports/suppliers')) ?>">Last 6 months</a>
</form>

<?php if (!$rows): ?>
  <?= card_open('Supplier scorecard') ?>
    <?= empty_state('No purchase orders in that period.',
          'Suppliers appear here once they have been ordered from.') ?>
  <?= card_close() ?>
<?php else: ?>

<?php
$totalOrdered  = array_sum(array_map(fn ($r) => (float) $r['ordered'], $rows));
$totalReceived = array_sum(array_map(fn ($r) => (float) $r['received'], $rows));
$shopFill      = $totalOrdered > 0 ? ($totalReceived / $totalOrdered) * 100 : 0.0;
$lateSuppliers = count(array_filter($rows, fn ($r) => (float) $r['avg_days_late'] > 1));
$overCost      = array_sum(array_map('floatval', $costVariance));
?>

<div class="tiles">
  <?= tile('Suppliers used', number_format(count($rows)), 'ordered from in the period') ?>
  <?= tile('Spend received', money_short($totalSpend), 'posted goods receipts') ?>
  <?= tile('Fill rate', pct_fmt($shopFill),
        'received against ordered',
        $shopFill >= 95 ? 'ok' : ($shopFill >= 85 ? 'warn' : 'bad')) ?>
  <?= tile('Running late', number_format($lateSuppliers),
        'averaging more than a day late',
        $lateSuppliers > 0 ? 'warn' : 'ok') ?>
  <?= tile('Billed over agreed', money($overCost),
        'cost above the order price',
        $overCost > 0 ? 'bad' : 'ok') ?>
  <?= tile('Receiving exceptions',
        number_format(array_sum(array_map('intval', $exceptions))),
        'over-receipts and cost variances',
        array_sum(array_map('intval', $exceptions)) > 0 ? 'warn' : 'ok') ?>
</div>

<?= card_open('By supplier') ?>
  <div class="table-wrap">
  <table class="data">
    <thead><tr>
      <th>Supplier</th><th class="num">Orders</th><th class="num">Completed</th>
      <th class="num">Ordered</th><th class="num">Received</th><th class="num">Fill rate</th>
      <th class="num">Lead agreed</th><th class="num">Days late</th>
      <th class="num">Spend</th><th class="num">Share</th>
      <th class="num">Over agreed</th><th class="num">Flags</th><th>Verdict</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $sid      = (int) $r['id'];
        $ordered  = (float) $r['ordered'];
        $received = (float) $r['received'];
        $fill     = $ordered > 0 ? ($received / $ordered) * 100 : null;
        $late     = (float) $r['avg_days_late'];
        $over     = (float) ($costVariance[$sid] ?? 0);
        $flags    = (int) ($exceptions[$sid] ?? 0);
        $supSpend = (float) ($spend[$sid] ?? 0);
        $share    = $totalSpend > 0 ? ($supSpend / $totalSpend) * 100 : 0.0;

        // A verdict worth acting on has to combine the three things that cost
        // money: short deliveries, late deliveries, and creeping prices.
        $problems = 0;
        if ($fill !== null && $fill < 90)  { $problems++; }
        if ($late > 2)                     { $problems++; }
        if ($over > 0)                     { $problems++; }

        $verdict = match (true) {
            $problems >= 2 => ['bad',  'Renegotiate or replace'],
            $problems === 1 => ['warn', 'Raise it with them'],
            default         => ['ok',   'Performing'],
        }; ?>
      <tr class="<?= $verdict[0] === 'bad' ? 'row-bad' : ($verdict[0] === 'warn' ? 'row-warn' : '') ?>">
        <td>
          <a href="<?= e(base_url('inventory/suppliers', ['edit' => $sid])) ?>">
            <?= e($r['name']) ?></a>
          <br><span class="tiny muted mono"><?= e($r['code']) ?></span>
        </td>
        <td class="num"><?= number_format((int) $r['orders']) ?></td>
        <td class="num"><?= number_format((int) $r['completed']) ?></td>
        <td class="num"><?= e(qty_fmt($ordered)) ?></td>
        <td class="num"><?= e(qty_fmt($received)) ?></td>
        <td class="num <?= $fill !== null && $fill < 90 ? 'neg' : '' ?>">
          <?= e($fill !== null ? pct_fmt($fill) : '-') ?>
        </td>
        <td class="num"><?= (int) $r['lead_time_days'] ?>d</td>
        <td class="num <?= $late > 2 ? 'neg' : '' ?>">
          <?= e($late > 0.05 ? '+' . number_format($late, 1) : number_format($late, 1)) ?>
        </td>
        <?= num_cell($supSpend) ?>
        <td class="num"><?= e(pct_fmt($share)) ?></td>
        <?= num_cell($over) ?>
        <td class="num <?= $flags > 0 ? 'neg' : '' ?>"><?= $flags ?></td>
        <td class="tiny"><?= badge($verdict[1], $verdict[0]) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3"><strong>Total</strong></td>
        <td class="num"><?= e(qty_fmt($totalOrdered)) ?></td>
        <td class="num"><?= e(qty_fmt($totalReceived)) ?></td>
        <td class="num"><?= e(pct_fmt($shopFill)) ?></td>
        <td colspan="2"></td>
        <td class="num"><?= e(money($totalSpend)) ?></td>
        <td class="num">100.0%</td>
        <td class="num"><?= e(money($overCost)) ?></td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
  </table>
  </div>
<?= card_close() ?>

<?= card_open('What each column is measuring') ?>
  <div class="grid-2">
    <div>
      <dl class="kv">
        <dt>Fill rate</dt>
        <dd>Quantity received against quantity ordered, on orders that have been delivered
            against. An order still in transit is excluded, so a supplier is not penalised for a
            delivery that has not fallen due.</dd>
        <dt>Days late</dt>
        <dd>Receipt date against the date the order said to expect it. Negative means early.
            Measured on posted receipts only &mdash; a delivery sitting unposted at the back door
            would otherwise make a supplier look worse than they are.</dd>
        <dt>Over agreed</dt>
        <dd>Where goods were booked in above the cost on the purchase order, multiplied by the
            quantity. This is margin quietly moving to the supplier, and it is the figure most
            worth taking into a negotiation.</dd>
        <dt>Flags</dt>
        <dd>Over-receipts and cost variances the audit rules raised at the point of receiving.</dd>
      </dl>
    </div>
    <div>
      <h3>Where the numbers can mislead</h3>
      <p class="small">
        A supplier with two orders and one short delivery shows a 50% fill rate, which says more
        about the sample than the supplier. Read the order count first; anything under about five
        orders is an anecdote.
      </p>
      <p class="small">
        "Days late" depends on the expected date actually being set when the order was raised. If
        buyers leave it blank, the column is blank too &mdash; an empty figure here means nobody
        recorded a promise, not that the supplier was on time.
      </p>
      <p class="small muted">
        Share of spend is the context for all of it. A supplier taking 40% of the spend and
        running two days late is a different conversation from one taking 2%.
      </p>
    </div>
  </div>
<?= card_close() ?>

<div class="sign-off">
  <div>Prepared by: <?= e((string) (current_user()['full_name'] ?? '')) ?>
    on <?= e(date('d M Y, H:i')) ?></div>
  <div>Reviewed by: ________________________</div>
</div>
<?php endif; ?>

<?php layout_foot(); ?>
