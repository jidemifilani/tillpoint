<?php
/**
 * Stock positions for the current store.
 *
 * Shows on hand, reserved and available as three separate columns rather than
 * one number, because they answer different questions: on hand is what is in
 * the building, reserved is what parked baskets are holding, and available is
 * what another till can actually sell.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('inventory.view');

$storeId    = current_store_id();
$search     = (string) get('q');
$categoryId = get_int('category_id');
$view       = one_of((string) get('view'), ['all', 'in_stock', 'out', 'negative', 'low', 'reserved', 'uncounted'], 'in_stock');

$where  = ['sl.store_id = ?', 'p.is_active = 1'];
$params = [$storeId];

if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($categoryId > 0) {
    $where[]  = 'p.category_id = ?';
    $params[] = $categoryId;
}
switch ($view) {
    case 'in_stock': $where[] = 'sl.qty_on_hand > 0'; break;
    case 'out':      $where[] = 'sl.qty_on_hand = 0'; break;
    case 'negative': $where[] = 'sl.qty_on_hand < -0.0005'; break;
    case 'low':      $where[] = 'p.reorder_level > 0 AND sl.qty_on_hand <= p.reorder_level'; break;
    case 'reserved': $where[] = 'sl.qty_reserved > 0.0005'; break;
    case 'uncounted':
        $where[]  = '(sl.last_counted_at IS NULL OR sl.last_counted_at < (CURDATE() - INTERVAL ? DAY))';
        $params[] = (int) audit_rule_threshold('STALE_COUNT', 90.0);
        break;
}
$whereSql = implode(' AND ', $where);

$baseSql = "FROM stock_levels sl
            JOIN products p ON p.id = sl.product_id
            JOIN categories c ON c.id = p.category_id
           WHERE $whereSql";

if ((string) get('export') === 'csv') {
    require_can('reports.view');
    $rows = qa("SELECT p.sku, p.name, c.name AS category, p.unit_code,
                       sl.qty_on_hand, sl.qty_reserved,
                       sl.qty_on_hand - sl.qty_reserved AS available,
                       sl.avg_cost, sl.qty_on_hand * sl.avg_cost AS value_at_cost,
                       p.sell_price, sl.qty_on_hand * p.sell_price AS value_at_retail,
                       p.reorder_level, sl.last_counted_at, sl.last_sold_at
                $baseSql ORDER BY p.name", $params);
    audit_log('stock.export', 'stock', null,
        'Exported ' . count($rows) . ' stock positions', null, null, 'notice');
    send_csv('stock-' . (string) (current_store()['code'] ?? '') . '-' . date('Y-m-d') . '.csv', [
        'SKU', 'Name', 'Category', 'Unit', 'On hand', 'Reserved', 'Available',
        'Avg cost', 'Value at cost', 'Shelf price', 'Value at retail',
        'Reorder level', 'Last counted', 'Last sold',
    ], $rows);
}

[$limit, $offset, $page] = paging(80);
$total = (int) qv("SELECT COUNT(*) $baseSql", $params, 0);

$rows = qa(
    "SELECT sl.*, p.sku, p.name, p.unit_code, p.sell_price, p.reorder_level,
            p.track_batches, c.name AS category,
            sl.qty_on_hand * sl.avg_cost AS value_at_cost,
            sl.qty_on_hand * p.sell_price AS value_at_retail
     $baseSql ORDER BY value_at_cost DESC, p.name LIMIT $limit OFFSET $offset",
    $params
);

$totals = q1(
    "SELECT COALESCE(SUM(sl.qty_on_hand),0) AS units,
            COALESCE(SUM(sl.qty_reserved),0) AS reserved,
            COALESCE(SUM(sl.qty_on_hand * sl.avg_cost),0) AS cost_value,
            COALESCE(SUM(sl.qty_on_hand * p.sell_price),0) AS retail_value
     $baseSql",
    $params
);

$categories = qpairs('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name');

layout_head([
    'title'    => 'Stock positions',
    'subtitle' => (string) (current_store()['name'] ?? '') . ' - ' . number_format($total) . ' lines',
    'actions'  => can('reports.view')
        ? '<a class="btn btn--ghost" href="' . e(url_with(['export' => 'csv'])) . '">Export CSV</a>' : '',
]);
?>

<form class="filters" method="get">
  <label class="field" style="flex:1;min-width:180px">
    <span class="field__label">Search</span>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, SKU or barcode">
  </label>
  <label class="field">
    <span class="field__label">Category</span>
    <select name="category_id"><?= select_options($categories, $categoryId, true, 'All') ?></select>
  </label>
  <label class="field">
    <span class="field__label">Show</span>
    <select name="view">
      <?= select_options([
          'in_stock'  => 'Holding stock',
          'all'       => 'Every line',
          'out'       => 'Out of stock',
          'negative'  => 'Negative positions',
          'low'       => 'At or below reorder level',
          'reserved'  => 'Reserved by parked baskets',
          'uncounted' => 'Not counted recently',
      ], $view) ?>
    </select>
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('inventory/stock')) ?>">Reset</a>
</form>

<div class="tiles">
  <?= tile('Units held', qty_fmt((float) $totals['units']), 'across the filtered lines') ?>
  <?= tile('Value at cost', money_short((float) $totals['cost_value']),
        'weighted average cost') ?>
  <?= tile('Value at retail', money_short((float) $totals['retail_value']),
        'if sold at full shelf price', 'ok') ?>
  <?= tile('Reserved', qty_fmt((float) $totals['reserved']),
        'held by parked baskets',
        (float) $totals['reserved'] > 0 ? 'warn' : 'mute') ?>
</div>

<?php if ($view === 'negative' && $rows): ?>
  <div class="alert alert--warning">
    A negative position means goods were sold before their delivery was posted to stock &mdash;
    or were never received at all. Find the unposted delivery first; raise an adjustment only
    once you are sure there is no paperwork outstanding.
  </div>
<?php endif; ?>

<?= card_open('Positions', '<button class="btn btn--sm btn--ghost" data-print>Print</button>') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No lines match those filters.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>SKU</th><th>Product</th><th>Category</th>
        <th class="num">On hand</th><th class="num">Reserved</th><th class="num">Available</th>
        <th class="num">Avg cost</th><th class="num">At cost</th><th class="num">At retail</th>
        <th>Last counted</th><th>Last sold</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $onHand = (float) $r['qty_on_hand'];
          $avail  = $onHand - (float) $r['qty_reserved'];
          $low    = (float) $r['reorder_level'] > 0 && $onHand <= (float) $r['reorder_level']; ?>
        <tr class="<?= $onHand < 0 ? 'row-bad' : ($low ? 'row-warn' : '') ?>">
          <td class="mono tiny"><?= e($r['sku']) ?></td>
          <td>
            <a href="<?= e(base_url('inventory/product', ['id' => (int) $r['product_id']])) ?>">
              <?= e($r['name']) ?></a>
            <?php if ((int) $r['track_batches'] === 1): ?>
              <a class="tiny" href="<?= e(base_url('inventory/batches', ['product_id' => (int) $r['product_id']])) ?>">
                batches</a>
            <?php endif; ?>
          </td>
          <td class="tiny"><?= e($r['category']) ?></td>
          <td class="num <?= $onHand < 0 ? 'neg' : '' ?>">
            <?= e(qty_fmt($onHand)) ?> <span class="tiny muted"><?= e($r['unit_code']) ?></span>
          </td>
          <td class="num"><?= e(qty_fmt((float) $r['qty_reserved'])) ?></td>
          <td class="num"><strong><?= e(qty_fmt($avail)) ?></strong></td>
          <?= num_cell($r['avg_cost']) ?>
          <?= num_cell($r['value_at_cost']) ?>
          <?= num_cell($r['value_at_retail']) ?>
          <td class="tiny nowrap">
            <?= e($r['last_counted_at'] !== null ? ago((string) $r['last_counted_at']) : 'never') ?>
          </td>
          <td class="tiny nowrap">
            <?= e($r['last_sold_at'] !== null ? ago((string) $r['last_sold_at']) : 'never') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3">Totals for the filtered lines</td>
          <td class="num"><?= e(qty_fmt((float) $totals['units'])) ?></td>
          <td class="num"><?= e(qty_fmt((float) $totals['reserved'])) ?></td>
          <td></td><td></td>
          <td class="num"><?= e(money((float) $totals['cost_value'])) ?></td>
          <td class="num"><?= e(money((float) $totals['retail_value'])) ?></td>
          <td></td><td></td>
        </tr>
      </tfoot>
    </table>
    </div>
    <?= pager($total, $limit, $page) ?>
  <?php endif; ?>
<?= card_close() ?>

<?php layout_foot(); ?>
