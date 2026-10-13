<?php
/**
 * Margin analysis.
 *
 * Profit is computed from VAT-exclusive revenue less the cost of the goods
 * actually sold. Counting VAT as margin would overstate profit by the VAT rate
 * on every line, and using today's cost rather than the cost at the moment of
 * sale would let a later price rise rewrite last month's performance.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('reports.financial');

$storeId = current_store_id();
$from    = date_or_null(get('from')) ?? date('Y-m-01');
$to      = date_or_null(get('to')) ?? date('Y-m-d');
$catId   = get_int('category_id');

$categories = report_margin_by_category($storeId, $from, $to);

$totals = ['gross' => 0.0, 'net' => 0.0, 'cost' => 0.0, 'discounts' => 0.0, 'units' => 0.0];
foreach ($categories as $c) {
    $totals['gross']     += (float) $c['gross_revenue'];
    $totals['net']       += (float) $c['net_revenue'];
    $totals['cost']      += (float) $c['cost'];
    $totals['discounts'] += (float) $c['discounts'];
    $totals['units']     += (float) $c['units'];
}
$totalProfit = $totals['net'] - $totals['cost'];
$totalMargin = $totals['net'] > 0 ? ($totalProfit / $totals['net']) * 100 : 0.0;

// Product level, optionally scoped to one category. Ordered by profit so the
// lines carrying the shop are at the top and the ones losing money at the
// bottom - both ends are the interesting ones.
$catFilter = $catId > 0 ? 'AND p.category_id = ?' : '';
$params    = [$storeId, $from, $to];
if ($catId > 0) {
    $params[] = $catId;
}
$products = qa(
    "SELECT p.id, p.sku, p.name, c.name AS category, p.sell_price, p.cost_price, p.vat_rate,
            SUM(sl.qty) AS units,
            COALESCE(SUM(sl.line_total),0) AS gross,
            COALESCE(SUM(sl.line_total - sl.vat_amount),0) AS net,
            COALESCE(SUM(sl.qty * sl.unit_cost),0) AS cost,
            COALESCE(SUM(sl.line_discount),0) AS discounts,
            c.target_margin_pct
       FROM sale_lines sl
       JOIN sales s ON s.id = sl.sale_id
       JOIN products p ON p.id = sl.product_id
       JOIN categories c ON c.id = p.category_id
      WHERE s.store_id = ? AND s.business_date BETWEEN ? AND ?
        AND s.status IN ('completed','refunded','partly_refunded') $catFilter
      GROUP BY p.id, p.sku, p.name, c.name, p.sell_price, p.cost_price, p.vat_rate, c.target_margin_pct
      ORDER BY (COALESCE(SUM(sl.line_total - sl.vat_amount),0)
                - COALESCE(SUM(sl.qty * sl.unit_cost),0)) DESC
      LIMIT 300",
    $params
);

if ((string) get('export') === 'csv') {
    audit_log('reports.margin_export', 'product', null,
        sprintf('Exported margin analysis for %s to %s', $from, $to), null, null, 'notice');
    send_csv('margin-' . $from . '-to-' . $to . '.csv', [
        'SKU', 'Product', 'Category', 'Units', 'Gross revenue', 'Net revenue',
        'Cost of sales', 'Gross profit', 'Margin %', 'Target %', 'Discounts',
    ], array_map(function ($p) {
        $net    = (float) $p['net'];
        $profit = $net - (float) $p['cost'];
        return [
            $p['sku'], $p['name'], $p['category'], $p['units'],
            $p['gross'], $net, $p['cost'], round($profit, 2),
            $net > 0 ? round(($profit / $net) * 100, 2) : 0,
            $p['target_margin_pct'], $p['discounts'],
        ];
    }, $products));
}

$categoryOptions = qpairs('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name');
$losers = array_filter($products, fn ($p) => ((float) $p['net'] - (float) $p['cost']) < 0);

layout_head([
    'title'    => 'Margin analysis',
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
  <label class="field">
    <span class="field__label">Category</span>
    <select name="category_id"><?= select_options($categoryOptions, $catId, true, 'All') ?></select>
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('reports/margin')) ?>">This month</a>
</form>

<div class="tiles">
  <?= tile('Gross revenue', money_short($totals['gross']), 'what customers paid') ?>
  <?= tile('Net of VAT', money_short($totals['net']), 'VAT removed') ?>
  <?= tile('Cost of sales', money_short($totals['cost']), 'cost at the moment of sale', 'mute') ?>
  <?= tile('Gross profit', money_short($totalProfit), pct_fmt($totalMargin) . ' margin',
        $totalMargin >= 18 ? 'ok' : 'warn') ?>
  <?= tile('Discount given', money($totals['discounts']),
        $totals['gross'] > 0 ? pct_fmt(($totals['discounts'] / ($totals['gross'] + $totals['discounts'])) * 100) . ' of shelf value' : '',
        $totals['discounts'] > 0 ? 'warn' : 'mute') ?>
  <?= tile('Lines sold at a loss', number_format(count($losers)),
        'cost exceeded net revenue',
        count($losers) > 0 ? 'bad' : 'ok') ?>
</div>

<?= card_open('By category, against target') ?>
  <?php if (!$categories): ?>
    <?= empty_state('Nothing sold in that period.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Category</th><th class="num">Units</th><th class="num">Gross</th>
        <th class="num">Net</th><th class="num">Cost</th><th class="num">Profit</th>
        <th class="num">Margin</th><th class="num">Target</th><th class="num">Gap</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($categories as $c):
          $net    = (float) $c['net_revenue'];
          $profit = $net - (float) $c['cost'];
          $margin = $net > 0 ? ($profit / $net) * 100 : 0.0;
          $target = (float) $c['target_margin_pct'];
          $gap    = $margin - $target; ?>
        <tr class="<?= $margin < 0 ? 'row-bad' : ($gap < -2 ? 'row-warn' : '') ?>">
          <td>
            <a href="<?= e(url_with(['category_id' => (int) $c['id']])) ?>"><?= e($c['name']) ?></a>
          </td>
          <td class="num"><?= e(qty_fmt((float) $c['units'])) ?></td>
          <?= num_cell($c['gross_revenue']) ?>
          <?= num_cell($net) ?>
          <?= num_cell($c['cost']) ?>
          <?= num_cell($profit) ?>
          <td class="num <?= $margin < 0 ? 'neg' : '' ?>"><?= e(pct_fmt($margin)) ?></td>
          <td class="num muted"><?= e(pct_fmt($target)) ?></td>
          <td class="num <?= $gap < 0 ? 'neg' : '' ?>">
            <?= e(($gap > 0 ? '+' : '') . pct_fmt($gap)) ?>
          </td>
          <td>
            <div class="bar <?= $gap < -2 ? 'bar--bad' : '' ?>" style="max-width:90px">
              <span style="width:<?= (int) round(max(0, min(100, $target > 0 ? ($margin / $target) * 100 : 0))) ?>%"></span>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td><strong>Total</strong></td>
          <td class="num"><?= e(qty_fmt($totals['units'])) ?></td>
          <td class="num"><?= e(money($totals['gross'])) ?></td>
          <td class="num"><?= e(money($totals['net'])) ?></td>
          <td class="num"><?= e(money($totals['cost'])) ?></td>
          <td class="num"><?= e(money($totalProfit)) ?></td>
          <td class="num"><?= e(pct_fmt($totalMargin)) ?></td>
          <td colspan="3"></td>
        </tr>
      </tfoot>
    </table>
    </div>
    <p class="small muted mt1">
      A category more than two points under its target is tinted. Usually that is a cost increase
      that never reached the shelf price &mdash; the
      <a href="<?= e(base_url('inventory/products', ['flag' => 'low_margin'])) ?>">low-margin
      product list</a> finds the specific lines.
    </p>
  <?php endif; ?>
<?= card_close() ?>

<?php if ($losers): ?>
<?= card_open('Sold at a loss (' . count($losers) . ')') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>SKU</th><th>Product</th><th class="num">Units</th>
      <th class="num">Net revenue</th><th class="num">Cost</th><th class="num">Loss</th>
      <th class="num">Shelf price</th><th class="num">Cost price</th>
    </tr></thead>
    <tbody>
    <?php foreach ($losers as $p):
        $net = (float) $p['net'];
        $loss = $net - (float) $p['cost']; ?>
      <tr class="row-bad">
        <td class="mono tiny"><?= e($p['sku']) ?></td>
        <td>
          <a href="<?= e(base_url('inventory/product', ['id' => (int) $p['id']])) ?>">
            <?= e($p['name']) ?></a>
        </td>
        <td class="num"><?= e(qty_fmt((float) $p['units'])) ?></td>
        <?= num_cell($net) ?>
        <?= num_cell($p['cost']) ?>
        <?= num_cell($loss) ?>
        <?= num_cell($p['sell_price']) ?>
        <?= num_cell($p['cost_price']) ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="small muted mt1">
    A loss leader is a legitimate decision; an accident is not. Check the price history on each
    line &mdash; if the cost moved and the shelf price did not, this is the latter.
  </p>
<?= card_close() ?>
<?php endif; ?>

<?= card_open('By product' . ($catId > 0 ? ' - ' . ($categoryOptions[$catId] ?? '') : '')) ?>
  <?php if (!$products): ?>
    <?= empty_state('Nothing sold in that period.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>SKU</th><th>Product</th><th>Category</th><th class="num">Units</th>
        <th class="num">Net revenue</th><th class="num">Cost</th><th class="num">Profit</th>
        <th class="num">Margin</th><th class="num">Target</th><th class="num">Discount</th>
      </tr></thead>
      <tbody>
      <?php foreach ($products as $p):
          $net    = (float) $p['net'];
          $profit = $net - (float) $p['cost'];
          $margin = $net > 0 ? ($profit / $net) * 100 : 0.0;
          $target = (float) $p['target_margin_pct']; ?>
        <tr class="<?= $margin < 0 ? 'row-bad' : ($margin < $target - 5 ? 'row-warn' : '') ?>">
          <td class="mono tiny"><?= e($p['sku']) ?></td>
          <td>
            <a href="<?= e(base_url('inventory/product', ['id' => (int) $p['id']])) ?>">
              <?= e($p['name']) ?></a>
          </td>
          <td class="tiny"><?= e($p['category']) ?></td>
          <td class="num"><?= e(qty_fmt((float) $p['units'])) ?></td>
          <?= num_cell($net) ?>
          <?= num_cell($p['cost']) ?>
          <?= num_cell($profit) ?>
          <td class="num <?= $margin < 0 ? 'neg' : '' ?>"><?= e(pct_fmt($margin)) ?></td>
          <td class="num muted"><?= e(pct_fmt($target)) ?></td>
          <?= num_cell($p['discounts']) ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?php layout_foot(); ?>
