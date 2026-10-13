<?php
/**
 * Till session history.
 *
 * The default filter is "unreconciled first", because that is the list someone
 * has to act on; a chronological dump of every shift ever is a report, not a
 * work queue.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$storeId = current_store_id();
$maySeeAll = can_any(['pos.session.reconcile', 'reports.view', 'audit.view']);

$status = one_of((string) get('status'), ['all', 'open', 'closed', 'reconciled'], 'all');
$from   = date_or_null(get('from')) ?? date('Y-m-d', strtotime('-30 days'));
$to     = date_or_null(get('to')) ?? date('Y-m-d');
$userId = get_int('user_id');

$where  = ['ts.store_id = ?', 'DATE(ts.opened_at) BETWEEN ? AND ?'];
$params = [$storeId, $from, $to];

if (!$maySeeAll) {
    // A cashier sees only their own shifts: another cashier's drawer is none of
    // their business, and the figures would tell them what to blend in with.
    $where[]  = 'ts.user_id = ?';
    $params[] = (int) $_SESSION['uid'];
} elseif ($userId > 0) {
    $where[]  = 'ts.user_id = ?';
    $params[] = $userId;
}
if ($status !== 'all') {
    $where[]  = 'ts.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);

[$limit, $offset, $page] = paging(40);
$total = (int) qv("SELECT COUNT(*) FROM till_sessions ts WHERE $whereSql", $params, 0);

$rows = qa(
    "SELECT ts.*, u.full_name AS cashier, t.code AS terminal_code,
            rb.full_name AS reconciled_by_name,
            (SELECT COUNT(*) FROM sales s WHERE s.session_id = ts.id
               AND s.status IN ('completed','refunded','partly_refunded')) AS txn,
            (SELECT COALESCE(SUM(s.total),0) FROM sales s WHERE s.session_id = ts.id
               AND s.status IN ('completed','refunded','partly_refunded')) AS takings
       FROM till_sessions ts
       JOIN users u ON u.id = ts.user_id
       JOIN terminals t ON t.id = ts.terminal_id
       LEFT JOIN users rb ON rb.id = ts.reconciled_by
      WHERE $whereSql
      ORDER BY FIELD(ts.status, 'closed', 'open', 'reconciled'), ts.opened_at DESC
      LIMIT $limit OFFSET $offset",
    $params
);

$summary = q1(
    "SELECT COUNT(*) AS shifts,
            COALESCE(SUM(ts.variance), 0) AS net_variance,
            COALESCE(SUM(ABS(ts.variance)), 0) AS abs_variance,
            SUM(ts.status = 'closed') AS unreconciled
       FROM till_sessions ts WHERE $whereSql",
    $params
);

$cashiers = $maySeeAll
    ? qpairs('SELECT DISTINCT u.id, u.full_name FROM till_sessions ts
                JOIN users u ON u.id = ts.user_id
               WHERE ts.store_id = ? ORDER BY u.full_name', [$storeId])
    : [];

$tolerance = setting_num('till_variance_tolerance', (float) TILL_VARIANCE_TOLERANCE);

layout_head(['title' => 'Till sessions', 'subtitle' => (string) (current_store()['name'] ?? '')]);
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
  <label class="field">
    <span class="field__label">Status</span>
    <select name="status">
      <?= select_options([
          'all'        => 'All',
          'open'       => 'Open now',
          'closed'     => 'Closed, not signed off',
          'reconciled' => 'Settled',
      ], $status) ?>
    </select>
  </label>
  <?php if ($cashiers): ?>
    <label class="field">
      <span class="field__label">Cashier</span>
      <select name="user_id"><?= select_options($cashiers, $userId, true, 'Everyone') ?></select>
    </label>
  <?php endif; ?>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('pos/sessions')) ?>">Reset</a>
</form>

<div class="tiles">
  <?= tile('Shifts', number_format((int) $summary['shifts']), 'in the selected period') ?>
  <?= tile('Net variance', money((float) $summary['net_variance']),
        'shortages less overages',
        abs((float) $summary['net_variance']) > $tolerance ? 'bad' : 'ok') ?>
  <?= tile('Total discrepancy', money((float) $summary['abs_variance']),
        'ignoring direction', 'warn') ?>
  <?= tile('Awaiting sign-off', number_format((int) $summary['unreconciled']),
        'variances not yet explained',
        (int) $summary['unreconciled'] > 0 ? 'bad' : 'ok') ?>
</div>

<?= card_open('Sessions', '<button class="btn btn--sm btn--ghost" data-print>Print</button>') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No sessions in that period.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Session</th><th>Cashier</th><th>Till</th><th>Opened</th><th>Closed</th>
        <th class="num">Txn</th><th class="num">Takings</th>
        <th class="num">Expected</th><th class="num">Declared</th><th class="num">Variance</th>
        <th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $var    = $r['variance'] !== null ? (float) $r['variance'] : null;
          $outTol = $var !== null && abs($var) > $tolerance; ?>
        <tr class="<?= $r['status'] === 'closed' ? 'row-warn' : ($outTol ? 'row-bad' : '') ?>">
          <td class="mono tiny">
            <a href="<?= e(base_url('pos/session', ['id' => (int) $r['id']])) ?>"><?= e($r['session_no']) ?></a>
          </td>
          <td><?= e($r['cashier']) ?></td>
          <td class="mono tiny"><?= e($r['terminal_code']) ?></td>
          <td class="nowrap tiny"><?= e(date_fmt((string) $r['opened_at'], true)) ?></td>
          <td class="nowrap tiny"><?= e(date_fmt($r['closed_at'], true)) ?></td>
          <td class="num"><?= number_format((int) $r['txn']) ?></td>
          <?= num_cell($r['takings']) ?>
          <?= num_cell($r['expected_cash'] ?? 0) ?>
          <?= num_cell($r['declared_cash'] ?? 0) ?>
          <?php if ($var === null): ?>
            <td class="num muted">-</td>
          <?php else: ?>
            <?= num_cell($var, true, true) ?>
          <?php endif; ?>
          <td class="nowrap">
            <?= status_badge((string) $r['status']) ?>
            <?php if ($r['reconciled_by_name'] !== null && $r['status'] === 'reconciled'
                      && (int) $r['reconciled_by'] !== (int) $r['user_id']): ?>
              <br><span class="tiny muted">by <?= e($r['reconciled_by_name']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= pager($total, $limit, $page) ?>
  <?php endif; ?>
<?= card_close() ?>

<p class="small muted">
  Tolerance is <?= e(money($tolerance)) ?> per shift. Inside it a till closes settled;
  outside it, someone other than the cashier has to record what explains the difference.
</p>

<?php layout_foot(); ?>
