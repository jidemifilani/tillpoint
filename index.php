<?php
/**
 * Dashboard.
 *
 * Answers the four questions a store manager actually opens the system to ask,
 * in that order: what did we take today, is the cash right, what needs doing,
 * and is anything wrong. Deliberately not a wall of charts - the things that
 * need a decision are at the top.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$storeId = current_store_id();
$store   = current_store();
$today   = date('Y-m-d');

if ($storeId === null) {
    layout_head(['title' => 'Dashboard']);
    echo '<div class="alert alert--warning">No store has been set up yet. '
       . (can('admin.stores')
            ? 'Add one under <a href="' . e(base_url('admin/stores')) . '">Stores &amp; tills</a>.'
            : 'Ask an administrator to add one.')
       . '</div>';
    layout_foot();
    exit;
}

$day       = report_day_summary($storeId, $today);
$yesterday = report_day_summary($storeId, date('Y-m-d', strtotime('-1 day')));
$trend     = report_sales_trend($storeId, 14);
$valuation = stock_valuation($storeId);
$closure   = day_end_status($storeId, $today);

// Open tills right now, with what each should be holding.
$openSessions = qa(
    'SELECT ts.*, u.full_name AS cashier, t.code AS terminal_code
       FROM till_sessions ts
       JOIN users u ON u.id = ts.user_id
       JOIN terminals t ON t.id = ts.terminal_id
      WHERE ts.store_id = ? AND ts.status = \'open\'
      ORDER BY ts.opened_at',
    [$storeId]
);

// Things waiting for a decision. Each is a queue someone has to clear, so the
// dashboard counts them rather than making the manager visit six screens.
$queues = [
    'Refunds to approve' => [
        'count' => (int) qv('SELECT COUNT(*) FROM refunds WHERE store_id = ? AND status = \'pending\'', [$storeId], 0),
        'url'   => base_url('sales/refunds'), 'cap' => 'refund.approve',
    ],
    'Wastage to approve' => [
        'count' => (int) qv('SELECT COUNT(*) FROM wastage WHERE store_id = ? AND status = \'pending\'', [$storeId], 0),
        'url'   => base_url('inventory/wastage'), 'cap' => 'wastage.approve',
    ],
    'Counts to sign off' => [
        'count' => (int) qv('SELECT COUNT(*) FROM stock_counts WHERE store_id = ? AND status = \'counted\'', [$storeId], 0),
        'url'   => base_url('inventory/counts'), 'cap' => 'count.approve',
    ],
    'Orders to approve' => [
        'count' => (int) qv('SELECT COUNT(*) FROM purchase_orders WHERE store_id = ? AND status = \'submitted\'', [$storeId], 0),
        'url'   => base_url('inventory/purchase-orders'), 'cap' => 'po.approve',
    ],
    'Deliveries unposted' => [
        'count' => (int) qv('SELECT COUNT(*) FROM grns WHERE store_id = ? AND status = \'draft\'', [$storeId], 0),
        'url'   => base_url('inventory/grns'), 'cap' => 'grn.post',
    ],
    'Tills unreconciled' => [
        'count' => (int) qv('SELECT COUNT(*) FROM till_sessions WHERE store_id = ? AND status = \'closed\'', [$storeId], 0),
        'url'   => base_url('pos/sessions'), 'cap' => 'pos.session.reconcile',
    ],
    'Transfers to receive' => [
        'count' => (int) qv('SELECT COUNT(*) FROM stock_transfers WHERE to_store_id = ? AND status = \'dispatched\'', [$storeId], 0),
        'url'   => base_url('inventory/transfers'), 'cap' => 'transfer.receive',
    ],
    'Adjustments to approve' => [
        'count' => (int) qv('SELECT COUNT(*) FROM stock_adjustments WHERE store_id = ? AND status = \'pending\'', [$storeId], 0),
        'url'   => base_url('inventory/adjustments'), 'cap' => 'stock.adjust.approve',
    ],
];

$openExceptions = (int) qv(
    'SELECT COUNT(*) FROM audit_exceptions
      WHERE (store_id = ? OR store_id IS NULL) AND status IN (\'open\',\'reviewing\')',
    [$storeId], 0
);
$criticalExceptions = (int) qv(
    'SELECT COUNT(*) FROM audit_exceptions
      WHERE (store_id = ? OR store_id IS NULL) AND status IN (\'open\',\'reviewing\')
        AND severity IN (\'high\',\'critical\')',
    [$storeId], 0
);

$lowStock = qa(
    'SELECT p.id, p.sku, p.name, p.unit_code, p.reorder_level, sl.qty_on_hand
       FROM stock_levels sl JOIN products p ON p.id = sl.product_id
      WHERE sl.store_id = ? AND p.is_active = 1 AND p.reorder_level > 0
        AND sl.qty_on_hand <= p.reorder_level
      ORDER BY (sl.qty_on_hand / NULLIF(p.reorder_level,0)) ASC LIMIT 8',
    [$storeId]
);
$expiring = expiring_batches($storeId, (int) setting_num('expiry_warn_days', (float) EXPIRY_WARN_DAYS));
$topToday = report_top_products($storeId, $today, $today, 8);

$deltaPct = (float) $yesterday['gross_sales'] > 0
    ? (($day['gross_sales'] - $yesterday['gross_sales']) / $yesterday['gross_sales']) * 100
    : null;

$actions = can('pos.operate')
    ? '<a class="btn" href="' . e(base_url('pos/')) . '">Open the till</a>'
    : '';

layout_head([
    'title'    => 'Dashboard',
    'subtitle' => ($store['name'] ?? '') . ' - ' . date('l, d F Y'),
    'actions'  => $actions,
]);
?>

<?php if ($criticalExceptions > 0 && can('audit.view')): ?>
  <div class="alert alert--error">
    <strong><?= $criticalExceptions ?></strong> high or critical audit exception<?= $criticalExceptions === 1 ? '' : 's' ?>
    <?= $criticalExceptions === 1 ? 'is' : 'are' ?> waiting for review.
    <a href="<?= e(base_url('audit/exceptions', ['severity' => 'high'])) ?>">Review them now</a>.
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Takings today', money_short($day['gross_sales']),
        $day['txn_count'] . ' transactions'
        . ($deltaPct !== null ? sprintf(', %s%s on yesterday', $deltaPct >= 0 ? '+' : '', pct_fmt($deltaPct)) : '')) ?>
  <?= tile('Average basket', money($day['avg_basket']),
        qty_fmt($day['item_count']) . ' items sold', 'info') ?>
  <?= tile('Gross profit', money_short($day['gross_profit']),
        pct_fmt($day['margin_pct']) . ' margin', $day['margin_pct'] >= 15 ? 'ok' : 'warn') ?>
  <?= tile('Stock on hand', money_short($valuation['cost_value']),
        number_format($valuation['lines']) . ' lines at cost', 'mute') ?>
  <?= tile('Refunds today', money($day['refund_value']),
        $day['refund_count'] . ' refunded, ' . $day['void_count'] . ' voided',
        $day['refund_value'] > 0 ? 'warn' : 'mute') ?>
  <?php if (can('audit.view')): ?>
    <?= tile('Open exceptions', (string) $openExceptions,
          $criticalExceptions . ' high or critical',
          $criticalExceptions > 0 ? 'bad' : ($openExceptions > 0 ? 'warn' : 'ok')) ?>
  <?php endif; ?>
</div>

<div class="grid-2">
  <!-- ---- Tills open right now ---------------------------------- -->
  <?= card_open('Tills open now', $closure && $closure['status'] === 'closed'
        ? badge('Day closed', 'ok')
        : (can('admin.dayend') ? '<a class="btn btn--sm btn--ghost" href="' . e(base_url('admin/day-end')) . '">Day end</a>' : '')) ?>
    <?php if (!$openSessions): ?>
      <?= empty_state('No till is open.',
            $day['txn_count'] > 0 ? 'All shifts for today have been closed.' : 'Trading has not started yet.') ?>
    <?php else: ?>
      <div class="table-wrap">
      <table class="data data--tight">
        <thead><tr>
          <th>Cashier</th><th>Till</th><th>Opened</th>
          <th class="num">Float</th><th class="num">Expected cash</th><th class="num">Sales</th>
        </tr></thead>
        <tbody>
        <?php foreach ($openSessions as $s):
            $cash = session_expected_cash((int) $s['id']);
            $sold = (float) qv('SELECT COALESCE(SUM(total),0) FROM sales
                                 WHERE session_id = ? AND status IN (\'completed\',\'refunded\',\'partly_refunded\')',
                               [(int) $s['id']], 0.0); ?>
          <tr>
            <td><a href="<?= e(base_url('pos/session', ['id' => (int) $s['id']])) ?>"><?= e($s['cashier']) ?></a></td>
            <td class="mono"><?= e($s['terminal_code']) ?></td>
            <td class="nowrap"><?= e(date('H:i', strtotime((string) $s['opened_at']))) ?></td>
            <?= num_cell($s['opening_float']) ?>
            <?= num_cell($cash['expected']) ?>
            <?= num_cell($sold) ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  <?= card_close() ?>

  <!-- ---- Waiting on someone ------------------------------------ -->
  <?= card_open('Waiting for a decision') ?>
    <?php
    $visible = array_filter($queues, fn ($q) => can($q['cap']) && $q['count'] > 0);
    if (!$visible): ?>
      <?= empty_state('Nothing is waiting.', 'Every approval queue you can see is clear.') ?>
    <?php else: ?>
      <div class="table-wrap">
      <table class="data data--tight">
        <tbody>
        <?php foreach ($visible as $label => $q): ?>
          <tr>
            <td><a href="<?= e($q['url']) ?>"><?= e($label) ?></a></td>
            <td class="num"><strong><?= $q['count'] ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  <?= card_close() ?>
</div>

<!-- ---- Fortnight trend ----------------------------------------- -->
<?= card_open('Takings over the last fortnight') ?>
  <?php if (!$trend): ?>
    <?= empty_state('No sales recorded yet.') ?>
  <?php else:
      $max = max(array_map(fn ($r) => (float) $r['gross'], $trend)) ?: 1.0; ?>
    <div class="spark" role="img" aria-label="Daily takings for the last fortnight">
      <?php foreach ($trend as $t): ?>
        <span style="height: <?= max(3, (int) round(((float) $t['gross'] / $max) * 100)) ?>%"
              title="<?= e(date_fmt((string) $t['business_date']) . ': ' . money((float) $t['gross'])
                          . ' across ' . (int) $t['txn'] . ' transactions') ?>"></span>
      <?php endforeach; ?>
    </div>
    <div class="table-wrap mt1">
    <table class="data data--tight">
      <thead><tr>
        <th>Date</th><th class="num">Transactions</th><th class="num">Takings</th>
        <th class="num">Gross profit</th><th class="num">Margin</th>
      </tr></thead>
      <tbody>
      <?php foreach (array_reverse($trend) as $t):
          $g = (float) $t['gross'];
          $p = (float) $t['profit']; ?>
        <tr>
          <td class="nowrap"><?= e(date_fmt((string) $t['business_date'])) ?></td>
          <td class="num"><?= number_format((int) $t['txn']) ?></td>
          <?= num_cell($g) ?>
          <?= num_cell($p) ?>
          <td class="num"><?= e($g > 0 ? pct_fmt(($p / $g) * 100) : '-') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<div class="grid-2">
  <!-- ---- Best sellers today ----------------------------------- -->
  <?= card_open('Best sellers today') ?>
    <?php if (!$topToday): ?>
      <?= empty_state('Nothing sold yet today.') ?>
    <?php else: ?>
      <div class="table-wrap">
      <table class="data data--tight">
        <thead><tr><th>Product</th><th class="num">Units</th><th class="num">Revenue</th></tr></thead>
        <tbody>
        <?php foreach ($topToday as $p): ?>
          <tr>
            <td><?= e($p['name']) ?><br><span class="tiny muted"><?= e($p['category']) ?></span></td>
            <td class="num"><?= e(qty_fmt((float) $p['units'])) ?></td>
            <?= num_cell($p['revenue']) ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  <?= card_close() ?>

  <!-- ---- Needs attention -------------------------------------- -->
  <?= card_open('Stock needing attention',
        '<a class="btn btn--sm btn--ghost" href="' . e(base_url('inventory/reorder')) . '">Replenishment</a>') ?>
    <?php if (!$lowStock && !$expiring): ?>
      <?= empty_state('Nothing low and nothing near its date.') ?>
    <?php else: ?>
      <?php if ($lowStock): ?>
        <h3 class="mb1">At or below reorder level</h3>
        <div class="table-wrap">
        <table class="data data--tight">
          <thead><tr><th>Product</th><th class="num">On hand</th><th class="num">Reorder at</th></tr></thead>
          <tbody>
          <?php foreach ($lowStock as $p): ?>
            <tr class="<?= (float) $p['qty_on_hand'] <= 0 ? 'row-bad' : '' ?>">
              <td><a href="<?= e(base_url('inventory/product', ['id' => (int) $p['id']])) ?>"><?= e($p['name']) ?></a></td>
              <td class="num <?= (float) $p['qty_on_hand'] <= 0 ? 'neg' : '' ?>">
                <?= e(qty_fmt((float) $p['qty_on_hand'])) ?> <span class="tiny muted"><?= e($p['unit_code']) ?></span>
              </td>
              <td class="num"><?= e(qty_fmt((float) $p['reorder_level'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>

      <?php if ($expiring):
          $expired = array_filter($expiring, fn ($b) => (int) $b['days_left'] < 0); ?>
        <h3 class="mb1 mt1">
          Expiring within <?= (int) setting_num('expiry_warn_days', (float) EXPIRY_WARN_DAYS) ?> days
          <?php if ($expired): ?><?= badge(count($expired) . ' already expired', 'bad') ?><?php endif; ?>
        </h3>
        <div class="table-wrap">
        <table class="data data--tight">
          <thead><tr><th>Product</th><th>Batch</th><th>Expires</th><th class="num">On hand</th><th class="num">At cost</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($expiring, 0, 8) as $b):
              $left = (int) $b['days_left']; ?>
            <tr class="<?= $left < 0 ? 'row-bad' : ($left <= 7 ? 'row-warn' : '') ?>">
              <td><?= e($b['name']) ?></td>
              <td class="mono tiny"><?= e($b['batch_no']) ?></td>
              <td class="nowrap"><?= e(date_fmt((string) $b['expiry_date'])) ?>
                <span class="tiny muted"><?= $left < 0 ? abs($left) . 'd ago' : $left . 'd' ?></span></td>
              <td class="num"><?= e(qty_fmt((float) $b['qty_on_hand'])) ?></td>
              <?= num_cell($b['value_at_risk']) ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <p class="small mt1"><a href="<?= e(base_url('reports/expiring')) ?>">See the full expiry report</a></p>
      <?php endif; ?>
    <?php endif; ?>
  <?= card_close() ?>
</div>

<!-- ---- Payment mix --------------------------------------------- -->
<?php if ($day['by_method']): ?>
<?= card_open('How today was paid for') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr><th>Method</th><th class="num">Amount</th><th>Share</th></tr></thead>
    <tbody>
    <?php $totalPaid = array_sum(array_map('floatval', $day['by_method'])) ?: 1.0;
    foreach ($day['by_method'] as $method => $amount):
        $pct = ((float) $amount / $totalPaid) * 100; ?>
      <tr>
        <td><?= e(ucfirst((string) $method)) ?></td>
        <?= num_cell($amount) ?>
        <td>
          <div class="bar" style="max-width:220px"><span style="width: <?= (int) round($pct) ?>%"></span></div>
          <span class="tiny muted"><?= e(pct_fmt($pct)) ?></span>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>Total</td><td class="num"><?= e(money($totalPaid)) ?></td><td></td></tr></tfoot>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?php layout_foot(); ?>
