<?php
/**
 * Till variances.
 *
 * Separates round-sum shortages from ordinary miscounting, because they are
 * different events. Counting change wrong produces awkward amounts; taking
 * notes out produces multiples of a denomination. A shortage of exactly 5,000
 * deserves a different conversation from one of 4,873.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('audit.view');

$storeId = current_store_id();
$from    = date_or_null(get('from')) ?? date('Y-m-d', strtotime('-30 days'));
$to      = date_or_null(get('to')) ?? date('Y-m-d');
$view    = one_of((string) get('view'), ['out_of_tolerance', 'all', 'short', 'over', 'unreconciled'], 'out_of_tolerance');

$tolerance = setting_num('till_variance_tolerance', (float) TILL_VARIANCE_TOLERANCE);

$where  = ['ts.store_id = ?', 'DATE(ts.opened_at) BETWEEN ? AND ?', 'ts.variance IS NOT NULL'];
$params = [$storeId, $from, $to];

switch ($view) {
    case 'out_of_tolerance':
        $where[]  = 'ABS(ts.variance) > ?';
        $params[] = $tolerance;
        break;
    case 'short':
        $where[]  = 'ts.variance < -?';
        $params[] = $tolerance;
        break;
    case 'over':
        $where[]  = 'ts.variance > ?';
        $params[] = $tolerance;
        break;
    case 'unreconciled':
        $where[] = "ts.status = 'closed'";
        break;
}
$whereSql = implode(' AND ', $where);

$rows = qa(
    "SELECT ts.*, u.full_name AS cashier, u.staff_no, t.code AS terminal_code,
            rb.full_name AS reconciled_by_name,
            (SELECT COUNT(*) FROM sales s WHERE s.session_id = ts.id
               AND s.status IN ('completed','refunded','partly_refunded')) AS txn,
            (SELECT COUNT(*) FROM cash_movements cm
               WHERE cm.session_id = ts.id AND cm.type = 'no_sale') AS no_sales,
            (SELECT COALESCE(SUM(cm.amount),0) FROM cash_movements cm
               WHERE cm.session_id = ts.id AND cm.type IN ('pay_out','drop')) AS cash_out
       FROM till_sessions ts
       JOIN users u ON u.id = ts.user_id
       JOIN terminals t ON t.id = ts.terminal_id
       LEFT JOIN users rb ON rb.id = ts.reconciled_by
      WHERE $whereSql
      ORDER BY ABS(ts.variance) DESC
      LIMIT 300",
    $params
);

$summary = q1(
    'SELECT COUNT(*) AS shifts,
            COALESCE(SUM(ABS(variance)),0) AS abs_var,
            COALESCE(SUM(variance),0) AS net_var,
            SUM(ABS(variance) > ?) AS out_of_tol,
            SUM(status = \'closed\') AS unreconciled,
            COALESCE(SUM(CASE WHEN variance < 0 THEN variance ELSE 0 END),0) AS shortages,
            COALESCE(SUM(CASE WHEN variance > 0 THEN variance ELSE 0 END),0) AS overages
       FROM till_sessions
      WHERE store_id = ? AND DATE(opened_at) BETWEEN ? AND ? AND variance IS NOT NULL',
    [$tolerance, $storeId, $from, $to]
);

$byCashier = qa(
    'SELECT u.id, u.full_name, COUNT(*) AS shifts,
            COALESCE(SUM(ABS(ts.variance)),0) AS abs_var,
            COALESCE(SUM(ts.variance),0) AS net_var,
            SUM(ABS(ts.variance) > ?) AS out_of_tol
       FROM till_sessions ts JOIN users u ON u.id = ts.user_id
      WHERE ts.store_id = ? AND DATE(ts.opened_at) BETWEEN ? AND ? AND ts.variance IS NOT NULL
      GROUP BY u.id, u.full_name
      ORDER BY net_var ASC',
    [$tolerance, $storeId, $from, $to]
);

/** Is a shortage an exact multiple of a note denomination? */
$roundSum = static function (float $variance): ?int {
    $short = abs($variance);
    if ($variance >= 0 || $short < 100) {
        return null;
    }
    foreach ([1000, 500, 200, 100] as $note) {
        if (abs(fmod($short, $note)) < 0.005) {
            return $note;
        }
    }
    return null;
};
$roundCount = count(array_filter($rows, fn ($r) => $roundSum((float) $r['variance']) !== null));

layout_head([
    'title'    => 'Till variances',
    'subtitle' => date_fmt($from) . ' to ' . date_fmt($to)
                . ' - tolerance ' . money($tolerance) . ' per shift',
    'actions'  => '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<div class="tiles">
  <?= tile('Shifts counted', number_format((int) $summary['shifts']), 'with a declared figure') ?>
  <?= tile('Out of tolerance', number_format((int) $summary['out_of_tol']),
        'of ' . number_format((int) $summary['shifts']) . ' shifts',
        (int) $summary['out_of_tol'] > 0 ? 'warn' : 'ok') ?>
  <?= tile('Total shortage', money(abs((float) $summary['shortages'])), 'cash missing at close', 'bad') ?>
  <?= tile('Total overage', money((float) $summary['overages']), 'cash over at close', 'info') ?>
  <?= tile('Net position', money((float) $summary['net_var']),
        'shortages less overages',
        (float) $summary['net_var'] < -$tolerance ? 'bad' : 'ok') ?>
  <?= tile('Round-sum shortages', number_format($roundCount),
        'exact note multiples',
        $roundCount > 0 ? 'bad' : 'ok') ?>
</div>

<?php if ((int) $summary['unreconciled'] > 0): ?>
  <div class="alert alert--warning">
    <strong><?= number_format((int) $summary['unreconciled']) ?></strong> shift(s) closed out of
    tolerance and have never been signed off. An unexamined variance is indistinguishable from an
    accepted loss.
  </div>
<?php endif; ?>

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
    <span class="field__label">Show</span>
    <select name="view">
      <?= select_options([
          'out_of_tolerance' => 'Outside tolerance',
          'all'              => 'Every counted shift',
          'short'            => 'Short only',
          'over'             => 'Over only',
          'unreconciled'     => 'Not signed off',
      ], $view) ?>
    </select>
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('audit/till-variances')) ?>">Reset</a>
</form>

<div class="grid-2">
  <?= card_open('By cashier') ?>
    <?php if (!$byCashier): ?>
      <?= empty_state('No counted shifts in that period.') ?>
    <?php else: ?>
      <table class="data data--tight">
        <thead><tr>
          <th>Cashier</th><th class="num">Shifts</th><th class="num">Out of tol.</th>
          <th class="num">Total discrepancy</th><th class="num">Net</th>
        </tr></thead>
        <tbody>
        <?php foreach ($byCashier as $c):
            // A net figure near zero with a large absolute figure is carelessness.
            // A consistently negative net figure is something else, so the two
            // are shown side by side rather than netted into one number.
            $persistent = (float) $c['net_var'] < -$tolerance * max(1, (int) $c['shifts'] / 2); ?>
          <tr class="<?= $persistent ? 'row-bad' : '' ?>">
            <td><?= e($c['full_name']) ?></td>
            <td class="num"><?= number_format((int) $c['shifts']) ?></td>
            <td class="num <?= (int) $c['out_of_tol'] > 0 ? 'neg' : '' ?>">
              <?= number_format((int) $c['out_of_tol']) ?></td>
            <?= num_cell($c['abs_var']) ?>
            <?= num_cell($c['net_var'], true, true) ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="tiny muted mt1">
        Compare the two right-hand columns. A large total discrepancy with a net near zero is
        someone who miscounts in both directions. A net that is persistently negative is not.
      </p>
    <?php endif; ?>
  <?= card_close() ?>

  <?= card_open('Why round sums matter') ?>
    <p class="small">
      Miscounting change produces awkward amounts, because change is made up of mixed
      denominations and the errors are small and random. Removing cash produces round ones,
      because notes come in fixed sizes.
    </p>
    <p class="small">
      So a shift that is 4,873 short is almost certainly a counting error somewhere in the day.
      A shift that is exactly 5,000 short is five thousand-naira notes. Both are flagged by the
      tolerance rule, but only the second is a specific event with a specific amount, and the
      <code>ROUND_SUM_SHORTAGE</code> rule separates them so the second does not get lost among
      the first.
    </p>
    <p class="small muted">
      It is not proof &mdash; a round shortage can come from a single mis-tendered note &mdash; but
      it changes which question to ask, and it points at one transaction rather than a whole shift.
    </p>
  <?= card_close() ?>
</div>

<?= card_open('Shifts (' . count($rows) . ')') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No shifts match those filters.',
          $view === 'out_of_tolerance' ? 'Every counted till was within tolerance.' : '') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Session</th><th>Cashier</th><th>Till</th><th>Date</th>
        <th class="num">Txn</th><th class="num">Expected</th><th class="num">Declared</th>
        <th class="num">Variance</th><th class="num">No-sale</th><th class="num">Cash out</th>
        <th>Pattern</th><th>Signed off</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $var   = (float) $r['variance'];
          $note  = $roundSum($var);
          $outTol = abs($var) > $tolerance; ?>
        <tr class="<?= $note !== null ? 'row-bad' : ($outTol ? 'row-warn' : '') ?>">
          <td class="mono tiny">
            <a href="<?= e(base_url('pos/session', ['id' => (int) $r['id']])) ?>">
              <?= e($r['session_no']) ?></a>
          </td>
          <td><?= e($r['cashier']) ?></td>
          <td class="mono tiny"><?= e($r['terminal_code']) ?></td>
          <td class="nowrap tiny"><?= e(date_fmt((string) $r['opened_at'])) ?></td>
          <td class="num"><?= number_format((int) $r['txn']) ?></td>
          <?= num_cell($r['expected_cash'] ?? 0) ?>
          <?= num_cell($r['declared_cash'] ?? 0) ?>
          <?= num_cell($var, true, true) ?>
          <td class="num"><?= (int) $r['no_sales'] ?></td>
          <?= num_cell($r['cash_out']) ?>
          <td class="tiny">
            <?php if ($note !== null): ?>
              <?= badge((int) round(abs($var) / $note) . ' x ' . money((float) $note, false), 'bad') ?>
            <?php elseif ($outTol): ?>
              <span class="muted">odd amount</span>
            <?php else: ?>
              <span class="muted">within tolerance</span>
            <?php endif; ?>
          </td>
          <td class="tiny">
            <?php if ($r['status'] === 'reconciled'): ?>
              <?php if ((int) $r['reconciled_by'] === (int) $r['user_id']): ?>
                <?= badge('Self-signed', 'bad') ?>
              <?php else: ?>
                <?= e($r['reconciled_by_name'] ?? '-') ?>
              <?php endif; ?>
            <?php else: ?>
              <?= badge('Outstanding', 'warn') ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<div class="sign-off">
  <div>Reviewed by: ________________________</div>
  <div>Date: ________________________</div>
</div>

<?php layout_foot(); ?>
