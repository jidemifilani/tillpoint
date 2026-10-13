<?php
/**
 * Day end.
 *
 * Closing a trading day freezes its figures into day_end_closures and runs the
 * exception scan over them. Both matter: until a day is closed the Z-report
 * recomputes every time it is opened, so a report printed and signed on Monday
 * can say something different on Friday. After close it cannot, and a late
 * correction has to be a dated adjustment that is visible as one.
 *
 * It refuses while any till is still open, because a day closed around a
 * trading till would freeze a figure that is still moving.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('admin.dayend');

$storeId = current_store_id();
$date    = date_or_null(get('date')) ?? date('Y-m-d', strtotime('-1 day'));

if (is_post()) {
    csrf_require();
    try {
        $target = date_or_null(post('business_date'));
        if ($target === null) {
            throw new RuntimeException('Choose the trading day to close.');
        }
        if ($target > date('Y-m-d')) {
            throw new RuntimeException('That day has not happened yet.');
        }
        $result = day_end_close($storeId, $target, (int) $_SESSION['uid']);

        flash_ok(sprintf('%s closed: %s gross across %d transactions, cash variance %s. '
            . 'The figures are now frozen and the exception scan has run.',
            date_fmt($target), money((float) $result['gross_sales']),
            (int) $result['transaction_count'], money((float) $result['cash_variance'])));
        redirect('admin/day-end', ['date' => $target]);
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('admin/day-end', ['date' => $date]);
    }
}

$closure = day_end_status($storeId, $date);
$live    = report_day_summary($storeId, $date);

// What stands in the way of closing.
$openSessions = qa(
    "SELECT ts.id, ts.session_no, u.full_name AS cashier, t.code AS terminal_code, ts.opened_at
       FROM till_sessions ts
       JOIN users u ON u.id = ts.user_id
       JOIN terminals t ON t.id = ts.terminal_id
      WHERE ts.store_id = ? AND ts.status = 'open' AND DATE(ts.opened_at) <= ?
      ORDER BY ts.opened_at",
    [$storeId, $date]
);
$parkedBaskets = (int) qv(
    "SELECT COUNT(*) FROM sales WHERE store_id = ? AND business_date = ? AND status = 'parked'",
    [$storeId, $date], 0
);
$unreconciled = qa(
    "SELECT ts.id, ts.session_no, u.full_name AS cashier, ts.variance
       FROM till_sessions ts JOIN users u ON u.id = ts.user_id
      WHERE ts.store_id = ? AND DATE(ts.opened_at) = ? AND ts.status = 'closed'
      ORDER BY ABS(ts.variance) DESC",
    [$storeId, $date]
);
$draftGrns = (int) qv(
    "SELECT COUNT(*) FROM grns WHERE store_id = ? AND status = 'draft' AND received_date <= ?",
    [$storeId, $date], 0
);

$canClose = $closure === null || $closure['status'] !== 'closed';
$blocked  = count($openSessions) > 0;

// Recent closures, so the run of closed days is visible at a glance.
$recent = qa(
    'SELECT dc.*, u.full_name AS closed_by_name FROM day_end_closures dc
       LEFT JOIN users u ON u.id = dc.closed_by
      WHERE dc.store_id = ? ORDER BY dc.business_date DESC LIMIT 30',
    [$storeId]
);

// Days that traded and were never closed. A gap in the run is the thing worth
// seeing here: an unclosed day never had its exception scan run.
$unclosed = qa(
    "SELECT s.business_date, COUNT(*) AS txn, COALESCE(SUM(s.total), 0) AS gross
       FROM sales s
       LEFT JOIN day_end_closures dc
              ON dc.store_id = s.store_id AND dc.business_date = s.business_date
                 AND dc.status = 'closed'
      WHERE s.store_id = ? AND s.business_date < CURDATE()
        AND s.status IN ('completed','refunded','partly_refunded')
        AND dc.id IS NULL
      GROUP BY s.business_date
      ORDER BY s.business_date DESC LIMIT 30",
    [$storeId]
);

layout_head([
    'title'    => 'Day end',
    'subtitle' => (string) (current_store()['name'] ?? '') . ' - ' . date_fmt($date),
    'actions'  => '<a class="btn btn--ghost" href="'
                . e(base_url('reports/daily-sales', ['date' => $date])) . '">Z-report</a>',
]);
?>

<?php if ($unclosed): ?>
  <div class="alert alert--warning">
    <strong><?= count($unclosed) ?></strong> past trading day(s) traded and were never closed.
    An unclosed day keeps recomputing and never had its exception scan run, so nothing in it has
    been checked for the patterns the audit rules look for.
  </div>
<?php endif; ?>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">Trading day</span>
    <input type="date" name="date" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>">
  </label>
  <button class="btn" type="submit">Show</button>
  <a class="btn btn--ghost" href="<?= e(base_url('admin/day-end', ['date' => date('Y-m-d', strtotime('-1 day'))])) ?>">Yesterday</a>
  <a class="btn btn--ghost" href="<?= e(base_url('admin/day-end', ['date' => date('Y-m-d')])) ?>">Today</a>
</form>

<div class="tiles">
  <?= tile('Gross takings', money($live['gross_sales']),
        number_format($live['txn_count']) . ' transactions') ?>
  <?= tile('Gross profit', money($live['gross_profit']),
        pct_fmt($live['margin_pct']) . ' margin',
        $live['margin_pct'] >= 15 ? 'ok' : 'warn') ?>
  <?= tile('Refunds', money($live['refund_value']),
        $live['void_count'] . ' voids as well',
        $live['refund_value'] > 0 ? 'warn' : 'mute') ?>
  <?= tile('Tills still open', number_format(count($openSessions)),
        $blocked ? 'must be closed first' : 'none',
        $blocked ? 'bad' : 'ok') ?>
  <?= tile('Variances outstanding', number_format(count($unreconciled)),
        'not signed off',
        $unreconciled ? 'warn' : 'ok') ?>
  <?= tile('State', $closure !== null && $closure['status'] === 'closed' ? 'Closed' : 'Open',
        $closure !== null && $closure['status'] === 'closed'
          ? 'frozen ' . date_fmt((string) $closure['closed_at'], true)
          : 'figures still moving',
        $closure !== null && $closure['status'] === 'closed' ? 'ok' : 'warn') ?>
</div>

<?php if ($closure !== null && $closure['status'] === 'closed'): ?>
  <div class="verdict verdict--ok">
    <h2><?= e(date_fmt($date)) ?> is closed</h2>
    <p>
      Closed by <?= e($closure['closed_by_name'] ?? 'unknown') ?> on
      <?= e(date_fmt((string) $closure['closed_at'], true)) ?>. These are the frozen figures:
    </p>
    <div class="table-wrap" style="margin-top:.7rem">
    <table class="data data--tight">
      <tbody>
        <tr><td>Gross sales</td><?= num_cell($closure['gross_sales']) ?></tr>
        <tr><td>Discounts given</td><?= num_cell($closure['discount_total']) ?></tr>
        <tr><td>Refunds</td><?= num_cell(-(float) $closure['refund_total']) ?></tr>
        <tr><td>Net sales</td><?= num_cell($closure['net_sales']) ?></tr>
        <tr><td>VAT</td><?= num_cell($closure['vat_total']) ?></tr>
        <tr><td>Cost of goods</td><?= num_cell($closure['cost_total']) ?></tr>
        <tr><td>Gross profit</td><?= num_cell($closure['gross_profit']) ?></tr>
        <tr><td>Cash expected</td><?= num_cell($closure['cash_expected']) ?></tr>
        <tr><td>Cash declared</td><?= num_cell($closure['cash_declared']) ?></tr>
      </tbody>
      <tfoot>
        <tr><td><strong>Cash variance</strong></td><?= num_cell($closure['cash_variance'], true, true) ?></tr>
      </tfoot>
    </table>
    </div>
  </div>
<?php endif; ?>

<?php if ($canClose): ?>
  <?= card_open('Close ' . date_fmt($date)) ?>
    <?php if ($blocked): ?>
      <div class="alert alert--error mb0">
        <strong>Cannot close yet.</strong>
        <?= count($openSessions) ?> till session(s) are still open. A day closed around a trading
        till would freeze a figure that is still moving, so every till has to be counted and
        closed first.
      </div>
      <div class="table-wrap mt1">
      <table class="data data--tight">
        <thead><tr><th>Session</th><th>Cashier</th><th>Till</th><th>Opened</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($openSessions as $s): ?>
          <tr class="row-warn">
            <td class="mono tiny"><?= e($s['session_no']) ?></td>
            <td><?= e($s['cashier']) ?></td>
            <td class="mono tiny"><?= e($s['terminal_code']) ?></td>
            <td class="nowrap tiny"><?= e(date_fmt((string) $s['opened_at'], true)) ?></td>
            <td class="right">
              <a class="btn btn--sm btn--ghost"
                 href="<?= e(base_url('pos/session', ['id' => (int) $s['id']])) ?>">Open shift</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php else: ?>
      <?php if ($parkedBaskets > 0): ?>
        <div class="alert alert--warning">
          <?= $parkedBaskets ?> parked basket(s) remain on this day. They hold stock in reserve
          and will not count as sales. Closing the day does not clear them.
        </div>
      <?php endif; ?>
      <?php if ($unreconciled): ?>
        <div class="alert alert--warning">
          <?= count($unreconciled) ?> till variance(s) have not been signed off. The day can still
          be closed &mdash; the variances stay outstanding and keep appearing in the audit queue
          until someone explains them &mdash; but it is better to clear them first.
          <a href="<?= e(base_url('audit/till-variances')) ?>">Review them</a>
        </div>
      <?php endif; ?>
      <?php if ($draftGrns > 0): ?>
        <div class="alert alert--warning">
          <?= $draftGrns ?> delivery(ies) up to this date are recorded but not posted to stock.
          Closing the day will not post them, and the cost of sales below is understated until
          they are. <a href="<?= e(base_url('inventory/grns')) ?>">Post them</a>
        </div>
      <?php endif; ?>

      <p class="small muted">
        Closing freezes the figures above into the permanent record and runs the exception scan
        over them. It cannot be undone from the interface: a correction afterwards has to be a
        dated adjustment, which is the point &mdash; a day that can be quietly reopened and
        recomputed is not a closed day.
      </p>
      <form method="post"
            data-confirm="Close <?= e(date_fmt($date)) ?>? The figures will be frozen permanently.">
        <?= csrf_field() ?>
        <input type="hidden" name="business_date" value="<?= e($date) ?>">
        <button class="btn btn--lg" type="submit">Close <?= e(date_fmt($date)) ?></button>
      </form>
    <?php endif; ?>
  <?= card_close() ?>
<?php endif; ?>

<?php if ($unclosed): ?>
<?= card_open('Traded but never closed (' . count($unclosed) . ')') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr><th>Date</th><th class="num">Transactions</th><th class="num">Gross</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($unclosed as $u): ?>
      <tr class="row-warn">
        <td class="nowrap"><?= e(date_fmt((string) $u['business_date'])) ?></td>
        <td class="num"><?= number_format((int) $u['txn']) ?></td>
        <?= num_cell($u['gross']) ?>
        <td class="right">
          <a class="btn btn--sm btn--ghost"
             href="<?= e(base_url('admin/day-end', ['date' => (string) $u['business_date']])) ?>">
            Close this day</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="small muted mt1">
    Close these oldest-first. Each one runs the exception scan for that day, so a backlog of
    unclosed days is also a backlog of unchecked trading.
  </p>
<?= card_close() ?>
<?php endif; ?>

<?= card_open('Recent closures') ?>
  <?php if (!$recent): ?>
    <?= empty_state('No trading day has been closed yet.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Date</th><th class="num">Txn</th><th class="num">Gross</th><th class="num">Net</th>
        <th class="num">VAT</th><th class="num">Profit</th><th class="num">Refunds</th>
        <th class="num">Cash variance</th><th class="num">Voids</th><th>Closed by</th>
      </tr></thead>
      <tbody>
      <?php foreach ($recent as $r):
          $variance = (float) $r['cash_variance']; ?>
        <tr class="<?= abs($variance) > setting_num('till_variance_tolerance', (float) TILL_VARIANCE_TOLERANCE)
                      ? 'row-warn' : '' ?>">
          <td class="nowrap">
            <a href="<?= e(base_url('reports/daily-sales', ['date' => (string) $r['business_date']])) ?>">
              <?= e(date_fmt((string) $r['business_date'])) ?></a>
          </td>
          <td class="num"><?= number_format((int) $r['transaction_count']) ?></td>
          <?= num_cell($r['gross_sales']) ?>
          <?= num_cell($r['net_sales']) ?>
          <?= num_cell($r['vat_total']) ?>
          <?= num_cell($r['gross_profit']) ?>
          <?= num_cell($r['refund_total']) ?>
          <?= num_cell($variance, true, true) ?>
          <td class="num"><?= number_format((int) $r['void_count']) ?></td>
          <td class="tiny"><?= e($r['closed_by_name'] ?? '-') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?php layout_foot(); ?>
