<?php
/**
 * Report index: what each report answers, so nobody has to open six to find out.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('reports.view');

$storeId = current_store_id();
$today   = report_day_summary($storeId, date('Y-m-d'));
$month   = q1(
    'SELECT COALESCE(SUM(total),0) AS gross, COALESCE(SUM(subtotal - cost_total),0) AS profit,
            COUNT(*) AS txn
       FROM sales WHERE store_id = ? AND business_date >= DATE_FORMAT(CURDATE(), \'%Y-%m-01\')
         AND status IN (\'completed\',\'refunded\',\'partly_refunded\')',
    [$storeId]
);
$valuation = stock_valuation($storeId);

$reports = [
    ['Daily sales (Z report)', 'reports/daily-sales', 'reports.view',
     'One trading day end to end: takings, payment mix, every shift, voids, refunds and the cash position. The report a manager signs.'],
    ['Margin analysis', 'reports/margin', 'reports.financial',
     'Gross profit by category and product against the category target, on VAT-exclusive revenue. Finds the lines whose cost crept up and whose price never followed.'],
    ['Stock valuation', 'reports/valuation', 'reports.financial',
     'What the stock is worth at weighted-average cost and at retail, by category, with the implied margin. The figure that goes in the accounts.'],
    ['VAT summary', 'reports/vat', 'reports.financial',
     'Output VAT on sales less input VAT on purchases, which is the figure that gets remitted. Refunds are netted off.'],
    ['Expiring stock', 'reports/expiring', 'reports.view',
     'Batches at or near their date, with the value at risk. A food-safety control before it is a financial one.'],
    ['Slow movers', 'reports/slow-movers', 'reports.view',
     'Lines holding stock with no sale for weeks, and the cash tied up in them. Markdown or delist candidates.'],
    ['Supplier scorecard', 'reports/suppliers', 'reports.view',
     'Fill rate and lateness per supplier, measured off real orders and receipts rather than opinion.'],
    ['Hourly trade', 'reports/hourly', 'reports.view',
     'Takings and transactions by hour of the day, which is what a till rota should be built from.'],
];

layout_head([
    'title'    => 'Reports',
    'subtitle' => (string) (current_store()['name'] ?? ''),
]);
?>

<div class="tiles">
  <?= tile('Takings today', money_short($today['gross_sales']),
        $today['txn_count'] . ' transactions') ?>
  <?= tile('Month to date', money_short((float) $month['gross']),
        number_format((int) $month['txn']) . ' transactions') ?>
  <?= tile('Gross profit, month', money_short((float) $month['profit']),
        (float) $month['gross'] > 0
          ? pct_fmt(((float) $month['profit'] / (float) $month['gross']) * 100) . ' of takings' : '', 'ok') ?>
  <?= tile('Stock at cost', money_short($valuation['cost_value']),
        number_format($valuation['lines']) . ' lines', 'mute') ?>
</div>

<?= card_open('What each report answers') ?>
  <div class="grid-2">
    <?php foreach ($reports as [$title, $path, $cap, $description]):
        $allowed = can($cap); ?>
      <div style="<?= $allowed ? '' : 'opacity:.5' ?>">
        <h3>
          <?php if ($allowed): ?>
            <a href="<?= e(base_url($path)) ?>"><?= e($title) ?></a>
          <?php else: ?>
            <?= e($title) ?> <?= badge('No access', 'neutral') ?>
          <?php endif; ?>
        </h3>
        <p class="small muted"><?= e($description) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
<?= card_close() ?>

<?php if (can('audit.view')): ?>
  <?= card_open('Audit reports') ?>
    <p class="small muted">
      The exception-driven reports live under
      <a href="<?= e(base_url('audit/')) ?>">Audit</a>: cashier scorecard, till variances,
      shrinkage, duty segregation and the trail itself.
    </p>
  <?= card_close() ?>
<?php endif; ?>

<?php layout_foot();
