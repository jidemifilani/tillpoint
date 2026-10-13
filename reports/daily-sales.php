<?php
/**
 * Daily sales - the Z report.
 *
 * One trading day end to end. Once the day is closed under Administration the
 * figures are frozen into day_end_closures, and this page shows the frozen
 * snapshot rather than recomputing, so a report printed and signed in October
 * still says in December what it said then.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('reports.view');

$storeId = current_store_id();
$date    = date_or_null(get('date')) ?? date('Y-m-d');

$closure = day_end_status($storeId, $date);
$isFrozen = $closure !== null && $closure['status'] === 'closed';

// A closed day reports its stored snapshot; an open one is recomputed live.
$live = report_day_summary($storeId, $date);

$summary = $isFrozen
    ? [
        'gross_sales'  => (float) $closure['gross_sales'],
        'discounts'    => (float) $closure['discount_total'],
        'refund_value' => (float) $closure['refund_total'],
        'vat_total'    => (float) $closure['vat_total'],
        'cost_total'   => (float) $closure['cost_total'],
        'gross_profit' => (float) $closure['gross_profit'],
        'txn_count'    => (int) $closure['transaction_count'],
        'void_count'   => (int) $closure['void_count'],
        'net_sales'    => (float) $closure['net_sales'],
        'item_count'   => $live['item_count'],
        'refund_count' => $live['refund_count'],
        'avg_basket'   => (int) $closure['transaction_count'] > 0
            ? money_round((float) $closure['gross_sales'] / (int) $closure['transaction_count']) : 0.0,
        'margin_pct'   => (float) $closure['net_sales'] > 0
            ? ((float) $closure['gross_profit'] / ((float) $closure['net_sales'] - (float) $closure['vat_total'])) * 100
            : 0.0,
        'by_method'    => $live['by_method'],
      ]
    : $live;

$sessions = qa(
    'SELECT ts.*, u.full_name AS cashier, t.code AS terminal_code,
            rb.full_name AS reconciled_by_name,
            (SELECT COUNT(*) FROM sales s WHERE s.session_id = ts.id
               AND s.status IN (\'completed\',\'refunded\',\'partly_refunded\')) AS txn,
            (SELECT COALESCE(SUM(s.total),0) FROM sales s WHERE s.session_id = ts.id
               AND s.status IN (\'completed\',\'refunded\',\'partly_refunded\')) AS takings,
            (SELECT COUNT(*) FROM sales s WHERE s.session_id = ts.id AND s.status = \'voided\') AS voids
       FROM till_sessions ts
       JOIN users u ON u.id = ts.user_id
       JOIN terminals t ON t.id = ts.terminal_id
       LEFT JOIN users rb ON rb.id = ts.reconciled_by
      WHERE ts.store_id = ? AND DATE(ts.opened_at) = ?
      ORDER BY ts.opened_at',
    [$storeId, $date]
);

$byCategory = qa(
    'SELECT c.name, SUM(sl.qty) AS units,
            COALESCE(SUM(sl.line_total),0) AS gross,
            COALESCE(SUM(sl.line_total - sl.vat_amount),0) AS net,
            COALESCE(SUM(sl.qty * sl.unit_cost),0) AS cost
       FROM sale_lines sl
       JOIN sales s ON s.id = sl.sale_id
       JOIN products p ON p.id = sl.product_id
       JOIN categories c ON c.id = p.category_id
      WHERE s.store_id = ? AND s.business_date = ?
        AND s.status IN (\'completed\',\'refunded\',\'partly_refunded\')
      GROUP BY c.id, c.name ORDER BY gross DESC',
    [$storeId, $date]
);

$hourly  = report_hourly_sales($storeId, $date);
$top     = report_top_products($storeId, $date, $date, 15);
$voids   = qa(
    'SELECT s.*, u.full_name AS cashier, vb.full_name AS voided_by_name,
            COALESCE(rc.label, s.void_reason) AS reason_label
       FROM sales s JOIN users u ON u.id = s.cashier_id
       LEFT JOIN users vb ON vb.id = s.voided_by
       LEFT JOIN reason_codes rc ON rc.code = s.void_reason
      WHERE s.store_id = ? AND s.business_date = ? AND s.status = \'voided\'
      ORDER BY s.total DESC LIMIT 50',
    [$storeId, $date]
);
$refunds = qa(
    'SELECT r.*, s.receipt_no, rq.full_name AS requested_by_name,
            ap.full_name AS approved_by_name, COALESCE(rc.label, r.reason_code) AS reason_label
       FROM refunds r JOIN sales s ON s.id = r.sale_id
       JOIN users rq ON rq.id = r.requested_by
       LEFT JOIN users ap ON ap.id = r.approved_by
       LEFT JOIN reason_codes rc ON rc.code = r.reason_code
      WHERE r.store_id = ? AND DATE(r.created_at) = ? AND r.status = \'posted\'
      ORDER BY r.total DESC',
    [$storeId, $date]
);

$cashTotals = q1(
    'SELECT COALESCE(SUM(expected_cash),0) AS expected,
            COALESCE(SUM(declared_cash),0) AS declared,
            COALESCE(SUM(variance),0) AS variance
       FROM till_sessions WHERE store_id = ? AND DATE(opened_at) = ? AND variance IS NOT NULL',
    [$storeId, $date]
);

layout_head([
    'title'    => 'Daily sales',
    'subtitle' => date_fmt($date) . ' - ' . (string) (current_store()['name'] ?? '')
                . ($isFrozen ? ' (closed)' : ' (still open)'),
    'actions'  => '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">Trading day</span>
    <input type="date" name="date" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>">
  </label>
  <button class="btn" type="submit">Show</button>
  <a class="btn btn--ghost" href="<?= e(base_url('reports/daily-sales', ['date' => date('Y-m-d', strtotime($date . ' -1 day'))])) ?>">
    Previous day</a>
  <?php if ($date < date('Y-m-d')): ?>
    <a class="btn btn--ghost" href="<?= e(base_url('reports/daily-sales', ['date' => date('Y-m-d', strtotime($date . ' +1 day'))])) ?>">
      Next day</a>
  <?php endif; ?>
</form>

<?php if ($isFrozen): ?>
  <div class="alert alert--success">
    This trading day was closed on <?= e(date_fmt((string) $closure['closed_at'], true)) ?>.
    The figures below are the frozen snapshot taken at close, not a recomputation &mdash; so this
    report says the same thing today as it did then. A later correction has to be a dated
    adjustment rather than a quiet edit.
  </div>
<?php else: ?>
  <div class="alert alert--info">
    This day has not been closed, so the figures are live and will keep moving while tills are
    open. Close it under <a href="<?= e(base_url('admin/day-end')) ?>">Administration &rarr; Day end</a>
    to freeze them.
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Gross takings', money($summary['gross_sales']),
        number_format($summary['txn_count']) . ' transactions') ?>
  <?= tile('Net of VAT', money($summary['gross_sales'] - $summary['vat_total']),
        'VAT ' . money($summary['vat_total'])) ?>
  <?= tile('Cost of goods', money($summary['cost_total']), 'at the time of sale', 'mute') ?>
  <?= tile('Gross profit', money($summary['gross_profit']),
        pct_fmt($summary['margin_pct']) . ' margin',
        $summary['margin_pct'] >= 15 ? 'ok' : 'warn') ?>
  <?= tile('Average basket', money($summary['avg_basket']),
        qty_fmt($summary['item_count']) . ' items sold', 'info') ?>
  <?= tile('Cash variance', money((float) $cashTotals['variance']),
        count($sessions) . ' shift(s)',
        abs((float) $cashTotals['variance']) > setting_num('till_variance_tolerance', (float) TILL_VARIANCE_TOLERANCE)
          ? 'bad' : 'ok') ?>
</div>

<div class="grid-2">
  <?= card_open('Payment mix') ?>
    <?php if (!$summary['by_method']): ?>
      <?= empty_state('No payments taken on this day.') ?>
    <?php else:
        $paid = array_sum(array_map('floatval', $summary['by_method'])) ?: 1.0; ?>
      <table class="data data--tight">
        <thead><tr><th>Method</th><th class="num">Amount</th><th>Share</th></tr></thead>
        <tbody>
        <?php foreach ($summary['by_method'] as $m => $amt):
            $pct = ((float) $amt / $paid) * 100; ?>
          <tr>
            <td><?= e(ucfirst((string) $m)) ?></td>
            <?= num_cell($amt) ?>
            <td>
              <div class="bar" style="max-width:160px"><span style="width:<?= (int) round($pct) ?>%"></span></div>
              <span class="tiny muted"><?= e(pct_fmt($pct)) ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Total</td><td class="num"><?= e(money($paid)) ?></td><td></td></tr></tfoot>
      </table>
    <?php endif; ?>
  <?= card_close() ?>

  <?= card_open('Cash reconciliation') ?>
    <table class="data data--tight">
      <tbody>
        <tr><td>Expected across all tills</td><?= num_cell($cashTotals['expected']) ?></tr>
        <tr><td>Declared by cashiers</td><?= num_cell($cashTotals['declared']) ?></tr>
      </tbody>
      <tfoot>
        <tr><td><strong>Variance</strong></td><?= num_cell($cashTotals['variance'], true, true) ?></tr>
      </tfoot>
    </table>
    <dl class="kv mt1">
      <dt>Transactions</dt><dd><?= number_format($summary['txn_count']) ?></dd>
      <dt>Voided baskets</dt><dd><?= number_format($summary['void_count']) ?></dd>
      <dt>Refunds posted</dt><dd><?= number_format($summary['refund_count']) ?>
        (<?= e(money($summary['refund_value'])) ?>)</dd>
      <dt>Discount given</dt><dd><?= e(money($summary['discounts'])) ?></dd>
    </dl>
  <?= card_close() ?>
</div>

<?= card_open('Shifts on this day') ?>
  <?php if (!$sessions): ?>
    <?= empty_state('No till sessions on this day.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Session</th><th>Cashier</th><th>Till</th><th>Opened</th><th>Closed</th>
        <th class="num">Txn</th><th class="num">Takings</th><th class="num">Voids</th>
        <th class="num">Expected</th><th class="num">Declared</th><th class="num">Variance</th>
        <th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($sessions as $s):
          $var = $s['variance'] !== null ? (float) $s['variance'] : null; ?>
        <tr class="<?= $s['status'] === 'closed' ? 'row-warn' : '' ?>">
          <td class="mono tiny">
            <a href="<?= e(base_url('pos/session', ['id' => (int) $s['id']])) ?>">
              <?= e($s['session_no']) ?></a>
          </td>
          <td><?= e($s['cashier']) ?></td>
          <td class="mono tiny"><?= e($s['terminal_code']) ?></td>
          <td class="nowrap tiny"><?= e(date('H:i', strtotime((string) $s['opened_at']))) ?></td>
          <td class="nowrap tiny">
            <?= e($s['closed_at'] !== null ? date('H:i', strtotime((string) $s['closed_at'])) : 'open') ?>
          </td>
          <td class="num"><?= number_format((int) $s['txn']) ?></td>
          <?= num_cell($s['takings']) ?>
          <td class="num"><?= (int) $s['voids'] ?></td>
          <?= num_cell($s['expected_cash'] ?? 0) ?>
          <?= num_cell($s['declared_cash'] ?? 0) ?>
          <?php if ($var === null): ?>
            <td class="num muted">-</td>
          <?php else: ?>
            <?= num_cell($var, true, true) ?>
          <?php endif; ?>
          <td><?= status_badge((string) $s['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<div class="grid-2">
  <?= card_open('By category') ?>
    <?php if (!$byCategory): ?>
      <?= empty_state('Nothing sold on this day.') ?>
    <?php else: ?>
      <div class="table-wrap">
      <table class="data data--tight">
        <thead><tr>
          <th>Category</th><th class="num">Units</th><th class="num">Gross</th>
          <th class="num">Net</th><th class="num">Profit</th><th class="num">Margin</th>
        </tr></thead>
        <tbody>
        <?php foreach ($byCategory as $c):
            $net    = (float) $c['net'];
            $profit = $net - (float) $c['cost'];
            $margin = $net > 0 ? ($profit / $net) * 100 : 0.0; ?>
          <tr>
            <td><?= e($c['name']) ?></td>
            <td class="num"><?= e(qty_fmt((float) $c['units'])) ?></td>
            <?= num_cell($c['gross']) ?>
            <?= num_cell($net) ?>
            <?= num_cell($profit) ?>
            <td class="num <?= $margin < 0 ? 'neg' : '' ?>"><?= e(pct_fmt($margin)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  <?= card_close() ?>

  <?= card_open('By hour') ?>
    <?php if (!$hourly): ?>
      <?= empty_state('No timed sales on this day.') ?>
    <?php else:
        $maxHour = max(array_map(fn ($h) => (float) $h['gross'], $hourly)) ?: 1.0; ?>
      <table class="data data--tight">
        <thead><tr><th>Hour</th><th class="num">Txn</th><th class="num">Takings</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($hourly as $h): ?>
          <tr>
            <td class="mono"><?= sprintf('%02d:00', (int) $h['hr']) ?></td>
            <td class="num"><?= number_format((int) $h['txn']) ?></td>
            <?= num_cell($h['gross']) ?>
            <td><div class="bar" style="max-width:140px">
              <span style="width:<?= (int) round(((float) $h['gross'] / $maxHour) * 100) ?>%"></span>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?= card_close() ?>
</div>

<?= card_open('Best sellers') ?>
  <?php if (!$top): ?>
    <?= empty_state('Nothing sold on this day.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>SKU</th><th>Product</th><th>Category</th>
        <th class="num">Units</th><th class="num">Revenue</th>
        <th class="num">Cost</th><th class="num">Profit</th>
      </tr></thead>
      <tbody>
      <?php foreach ($top as $p): ?>
        <tr>
          <td class="mono tiny"><?= e($p['sku']) ?></td>
          <td><?= e($p['name']) ?></td>
          <td class="tiny"><?= e($p['category']) ?></td>
          <td class="num"><?= e(qty_fmt((float) $p['units'])) ?></td>
          <?= num_cell($p['revenue']) ?>
          <?= num_cell($p['cost']) ?>
          <?= num_cell($p['profit']) ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?php if ($voids): ?>
<?= card_open('Voided baskets (' . count($voids) . ')') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Receipt</th><th>Cashier</th><th>Voided by</th><th class="num">Value</th>
      <th class="num">Items</th><th>Reason</th><th>Note</th>
    </tr></thead>
    <tbody>
    <?php foreach ($voids as $v): ?>
      <tr>
        <td class="mono tiny">
          <a href="<?= e(base_url('sales/view', ['id' => (int) $v['id']])) ?>"><?= e($v['receipt_no']) ?></a>
        </td>
        <td class="tiny"><?= e($v['cashier']) ?></td>
        <td class="tiny"><?= e($v['voided_by_name'] ?? '-') ?></td>
        <?= num_cell($v['total']) ?>
        <td class="num"><?= (int) $v['line_count'] ?></td>
        <td class="tiny"><?= e($v['reason_label'] ?? '') ?></td>
        <td class="tiny muted"><?= e($v['void_note'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?php if ($refunds): ?>
<?= card_open('Refunds posted (' . count($refunds) . ')') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Refund</th><th>Against</th><th class="num">Amount</th><th>Method</th>
      <th>Reason</th><th>Raised by</th><th>Approved by</th><th>Restocked</th>
    </tr></thead>
    <tbody>
    <?php foreach ($refunds as $r):
        // A till-authorised refund is not a breach - see audit/sod.php.
        $self = $r['approved_by'] !== null
                && (int) $r['approved_by'] === (int) $r['requested_by']
                && (int) ($r['self_approved'] ?? 0) === 0; ?>
      <tr class="<?= $self ? 'row-bad' : '' ?>">
        <td class="mono tiny"><?= e($r['refund_no']) ?></td>
        <td class="mono tiny">
          <a href="<?= e(base_url('sales/view', ['id' => (int) $r['sale_id']])) ?>"><?= e($r['receipt_no']) ?></a>
        </td>
        <?= num_cell($r['total']) ?>
        <td class="tiny"><?= e(ucfirst((string) $r['method'])) ?></td>
        <td class="tiny"><?= e($r['reason_label']) ?></td>
        <td class="tiny"><?= e($r['requested_by_name']) ?></td>
        <td class="tiny">
          <?= e($r['approved_by_name'] ?? '-') ?>
          <?php if ($self): ?><br><?= badge('Self-approved', 'bad') ?><?php endif; ?>
        </td>
        <td><?= (int) $r['restock'] === 1 ? badge('Yes', 'ok') : badge('No', 'warn') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<div class="sign-off">
  <div>Prepared by: <?= e((string) (current_user()['full_name'] ?? '')) ?>
    on <?= e(date('d M Y, H:i')) ?></div>
  <div>Approved by: ________________________</div>
</div>

<?php layout_foot(); ?>
