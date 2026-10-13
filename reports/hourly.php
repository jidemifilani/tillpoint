<?php
/**
 * Hourly trade.
 *
 * What a till rota should be built from. Averaged across the selected period
 * rather than shown for one day, because a single day's shape is noise - the
 * question is which hours are reliably busy, and that only shows up over weeks.
 *
 * Split by day of week as well, since a Saturday afternoon and a Tuesday
 * afternoon are different businesses and staffing them the same way is how
 * queues form at one and staff stand idle at the other.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('reports.view');

$storeId = current_store_id();
$from    = date_or_null(get('from')) ?? date('Y-m-d', strtotime('-28 days'));
$to      = date_or_null(get('to')) ?? date('Y-m-d');

$tradingDays = max(1, (int) qv(
    "SELECT COUNT(DISTINCT business_date) FROM sales
      WHERE store_id = ? AND business_date BETWEEN ? AND ?
        AND status IN ('completed','refunded','partly_refunded')",
    [$storeId, $from, $to], 1
));

// Average per trading day, so a 28-day window and a 7-day window are comparable.
$byHour = qa(
    "SELECT HOUR(sold_at) AS hr,
            COUNT(*) AS txn,
            COALESCE(SUM(total), 0) AS gross,
            COALESCE(SUM(line_count), 0) AS items,
            COUNT(DISTINCT business_date) AS days
       FROM sales
      WHERE store_id = ? AND business_date BETWEEN ? AND ? AND sold_at IS NOT NULL
        AND status IN ('completed','refunded','partly_refunded')
      GROUP BY HOUR(sold_at) ORDER BY hr",
    [$storeId, $from, $to]
);

// Hour against weekday, for the heat grid.
$grid = [];
foreach (qa(
    "SELECT WEEKDAY(business_date) AS dow, HOUR(sold_at) AS hr,
            COUNT(*) AS txn, COALESCE(SUM(total), 0) AS gross,
            COUNT(DISTINCT business_date) AS days
       FROM sales
      WHERE store_id = ? AND business_date BETWEEN ? AND ? AND sold_at IS NOT NULL
        AND status IN ('completed','refunded','partly_refunded')
      GROUP BY WEEKDAY(business_date), HOUR(sold_at)",
    [$storeId, $from, $to]
) as $g) {
    $grid[(int) $g['dow']][(int) $g['hr']] = $g;
}

$byWeekday = qa(
    "SELECT WEEKDAY(business_date) AS dow,
            COUNT(*) AS txn, COALESCE(SUM(total), 0) AS gross,
            COUNT(DISTINCT business_date) AS days
       FROM sales
      WHERE store_id = ? AND business_date BETWEEN ? AND ?
        AND status IN ('completed','refunded','partly_refunded')
      GROUP BY WEEKDAY(business_date) ORDER BY dow",
    [$storeId, $from, $to]
);

// Tills actually open per hour, so busy hours can be compared against cover.
$tillHours = qpairs(
    'SELECT HOUR(ts.opened_at) AS hr, COUNT(*) AS n FROM till_sessions ts
      WHERE ts.store_id = ? AND DATE(ts.opened_at) BETWEEN ? AND ?
      GROUP BY HOUR(ts.opened_at)',
    [$storeId, $from, $to]
);

$dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$open     = (int) setting_num('trading_hour_open', 7.0);
$close    = (int) setting_num('trading_hour_close', 22.0);

$maxHourGross = $byHour ? max(array_map(fn ($h) => (float) $h['gross'], $byHour)) : 1.0;
$maxCell = 0.0;
foreach ($grid as $hours) {
    foreach ($hours as $cell) {
        $perDay = (float) $cell['gross'] / max(1, (int) $cell['days']);
        $maxCell = max($maxCell, $perDay);
    }
}
$maxCell = $maxCell ?: 1.0;

$totalGross = array_sum(array_map(fn ($h) => (float) $h['gross'], $byHour));
$totalTxn   = array_sum(array_map(fn ($h) => (int) $h['txn'], $byHour));

// The busiest and quietest hours inside trading hours - the actionable pair.
$inHours = array_values(array_filter($byHour,
    fn ($h) => (int) $h['hr'] >= $open && (int) $h['hr'] < $close));
usort($inHours, fn ($a, $b) => (float) $b['gross'] <=> (float) $a['gross']);
$busiest  = $inHours[0] ?? null;
$quietest = $inHours ? end($inHours) : null;

// Trade outside the declared trading hours, which the audit rule also flags.
$outOfHours = array_values(array_filter($byHour,
    fn ($h) => (int) $h['hr'] < $open || (int) $h['hr'] >= $close));
$outOfHoursGross = array_sum(array_map(fn ($h) => (float) $h['gross'], $outOfHours));

if ((string) get('export') === 'csv') {
    audit_log('reports.hourly_export', 'sale', null,
        sprintf('Exported hourly trade for %s to %s', $from, $to), null, null, 'notice');
    $csv = [];
    foreach ($byHour as $h) {
        $days = max(1, (int) $h['days']);
        $csv[] = [
            sprintf('%02d:00', (int) $h['hr']),
            $h['txn'], round((int) $h['txn'] / $days, 2),
            $h['gross'], round((float) $h['gross'] / $days, 2),
            $h['items'], $days,
        ];
    }
    send_csv('hourly-trade-' . $from . '-to-' . $to . '.csv', [
        'Hour', 'Transactions', 'Transactions per day', 'Gross', 'Gross per day',
        'Items', 'Days traded',
    ], $csv);
}

layout_head([
    'title'    => 'Hourly trade',
    'subtitle' => date_fmt($from) . ' to ' . date_fmt($to)
                . ' - ' . $tradingDays . ' trading day(s)',
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
  <a class="btn btn--ghost" href="<?= e(base_url('reports/hourly')) ?>">Last 4 weeks</a>
</form>

<?php if (!$byHour): ?>
  <?= card_open('Hourly trade') ?>
    <?= empty_state('No timed sales in that period.') ?>
  <?= card_close() ?>
<?php else: ?>

<div class="tiles">
  <?= tile('Takings', money_short($totalGross),
        number_format($totalTxn) . ' transactions') ?>
  <?= tile('Per trading day', money_short($totalGross / $tradingDays),
        number_format((int) round($totalTxn / $tradingDays)) . ' transactions a day') ?>
  <?= tile('Busiest hour',
        $busiest !== null ? sprintf('%02d:00', (int) $busiest['hr']) : '-',
        $busiest !== null
          ? money((float) $busiest['gross'] / max(1, (int) $busiest['days'])) . ' a day' : '',
        'ok') ?>
  <?= tile('Quietest trading hour',
        $quietest !== null ? sprintf('%02d:00', (int) $quietest['hr']) : '-',
        $quietest !== null
          ? money((float) $quietest['gross'] / max(1, (int) $quietest['days'])) . ' a day' : '',
        'mute') ?>
  <?= tile('Outside trading hours', money($outOfHoursGross),
        sprintf('before %02d:00 or after %02d:00', $open, $close),
        $outOfHoursGross > 0 ? 'warn' : 'ok') ?>
  <?= tile('Average basket',
        money($totalTxn > 0 ? $totalGross / $totalTxn : 0), 'across the period', 'info') ?>
</div>

<?php if ($outOfHoursGross > 0): ?>
  <div class="alert alert--warning">
    <?= e(money($outOfHoursGross)) ?> was rung up outside the declared trading hours of
    <?= sprintf('%02d:00 to %02d:00', $open, $close) ?>. That may simply mean the hours in
    <a href="<?= e(base_url('admin/settings')) ?>">Settings</a> are wrong for this store &mdash;
    but until they are corrected, the <code>AFTER_HOURS_SALES</code> rule will keep raising it.
  </div>
<?php endif; ?>

<?= card_open('By hour, averaged per trading day') ?>
  <div class="table-wrap">
  <table class="data">
    <thead><tr>
      <th>Hour</th><th class="num">Txn total</th><th class="num">Txn per day</th>
      <th class="num">Takings</th><th class="num">Per day</th>
      <th class="num">Avg basket</th><th class="num">Tills opened</th><th>Share of the day</th>
    </tr></thead>
    <tbody>
    <?php foreach ($byHour as $h):
        $hr      = (int) $h['hr'];
        $days    = max(1, (int) $h['days']);
        $gross   = (float) $h['gross'];
        $txn     = (int) $h['txn'];
        $perDay  = $gross / $days;
        $inHour  = $hr >= $open && $hr < $close; ?>
      <tr class="<?= $inHour ? '' : 'row-warn' ?>">
        <td class="mono"><?= sprintf('%02d:00', $hr) ?>
          <?php if (!$inHour): ?><br><span class="tiny muted">out of hours</span><?php endif; ?>
        </td>
        <td class="num"><?= number_format($txn) ?></td>
        <td class="num"><?= e(number_format($txn / $days, 1)) ?></td>
        <?= num_cell($gross) ?>
        <?= num_cell($perDay) ?>
        <?= num_cell($txn > 0 ? $gross / $txn : 0) ?>
        <td class="num"><?= number_format((int) ($tillHours[$hr] ?? 0)) ?></td>
        <td>
          <div class="bar" style="max-width:200px">
            <span style="width:<?= (int) round(($gross / $maxHourGross) * 100) ?>%"></span>
          </div>
          <span class="tiny muted">
            <?= e($totalGross > 0 ? pct_fmt(($gross / $totalGross) * 100) : '-') ?>
          </span>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>

<?= card_open('Hour against day of week') ?>
  <p class="small muted">
    Average takings per trading day in each slot. Darker is busier. A Saturday afternoon and a
    Tuesday afternoon are different businesses, and this is the grid a rota should be built from
    rather than a single day's shape.
  </p>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Hour</th>
      <?php foreach ($dayNames as $d): ?><th class="num"><?= e($d) ?></th><?php endforeach; ?>
      <th class="num">All week</th>
    </tr></thead>
    <tbody>
    <?php for ($hr = 0; $hr < 24; $hr++):
        // Skip hours nothing ever happens in, so the grid stays readable.
        $anything = false;
        for ($d = 0; $d < 7; $d++) {
            if (isset($grid[$d][$hr])) { $anything = true; break; }
        }
        if (!$anything) { continue; }
        $rowTotal = 0.0; ?>
      <tr>
        <td class="mono"><?= sprintf('%02d:00', $hr) ?></td>
        <?php for ($d = 0; $d < 7; $d++):
            $cell = $grid[$d][$hr] ?? null;
            if ($cell === null): ?>
              <td class="num muted">&mdash;</td>
            <?php else:
                $perDay = (float) $cell['gross'] / max(1, (int) $cell['days']);
                $rowTotal += $perDay;
                // Shade from the accent colour by intensity. Inline because the
                // value is data-driven; see the CSP note in bootstrap.php.
                $intensity = $maxCell > 0 ? $perDay / $maxCell : 0;
                $alpha = 0.08 + ($intensity * 0.72); ?>
              <td class="num" style="background: rgba(13,122,95,<?= e(number_format($alpha, 2)) ?>)"
                  title="<?= e($dayNames[$d] . ' ' . sprintf('%02d:00', $hr) . ': '
                              . money($perDay) . ' a day across ' . (int) $cell['days'] . ' day(s)') ?>">
                <?= e(money_short($perDay)) ?>
              </td>
            <?php endif;
        endfor; ?>
        <td class="num"><strong><?= e(money_short($rowTotal)) ?></strong></td>
      </tr>
    <?php endfor; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>

<?= card_open('By day of week') ?>
  <?php $maxDay = $byWeekday ? max(array_map(
      fn ($d) => (float) $d['gross'] / max(1, (int) $d['days']), $byWeekday)) ?: 1.0 : 1.0; ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Day</th><th class="num">Days in period</th><th class="num">Transactions</th>
      <th class="num">Takings</th><th class="num">Per day</th>
      <th class="num">Avg basket</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($byWeekday as $d):
        $days   = max(1, (int) $d['days']);
        $gross  = (float) $d['gross'];
        $txn    = (int) $d['txn'];
        $perDay = $gross / $days; ?>
      <tr>
        <td><?= e($dayNames[(int) $d['dow']] ?? '?') ?></td>
        <td class="num"><?= $days ?></td>
        <td class="num"><?= number_format($txn) ?></td>
        <?= num_cell($gross) ?>
        <?= num_cell($perDay) ?>
        <?= num_cell($txn > 0 ? $gross / $txn : 0) ?>
        <td><div class="bar" style="max-width:180px">
          <span style="width:<?= (int) round(($perDay / $maxDay) * 100) ?>%"></span>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>

<?= card_open('Using this for a rota') ?>
  <div class="grid-2">
    <div>
      <h3>Compare takings against tills opened</h3>
      <p class="small">
        The "tills opened" column counts shifts that started in each hour, which is a rough proxy
        for cover. An hour taking three times the average on the same number of tills is where
        queues form, and a queue at a supermarket till is lost baskets rather than delayed ones.
      </p>
      <p class="small muted">
        It counts shift <em>starts</em>, not tills open throughout, so treat it as an
        indication of when staff arrive rather than a measure of cover.
      </p>
    </div>
    <div>
      <h3>Use weeks, not days</h3>
      <p class="small">
        A single day tells you almost nothing: one large basket moves an hour's figure
        materially. Four weeks is enough for the shape to stabilise while still reflecting
        current trade, which is why that is the default window.
      </p>
      <p class="small muted">
        Watch the "days in period" column on the weekday table. A month rarely contains the same
        number of each weekday, and comparing a total across five Saturdays with one across four
        Mondays is the easiest mistake to make here.
      </p>
    </div>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?php layout_foot(); ?>
