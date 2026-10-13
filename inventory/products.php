<?php
/**
 * The product file.
 *
 * Margin is shown on every row because a supermarket's commonest quiet loss is
 * a product whose cost crept up and whose shelf price never followed. Computed
 * from the VAT-exclusive price, since VAT is not margin.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('products.view');

$storeId    = current_store_id();
$search     = (string) get('q');
$categoryId = get_int('category_id');
$supplierId = get_int('supplier_id');
$flag       = one_of((string) get('flag'), ['all', 'inactive', 'low_margin', 'no_barcode', 'perishable', 'age'], 'all');

$where  = ['1 = 1'];
$params = [$storeId];   // first placeholder is the stock_levels join

if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($categoryId > 0) {
    $where[]  = 'p.category_id = ?';
    $params[] = $categoryId;
}
if ($supplierId > 0) {
    $where[]  = 'p.supplier_id = ?';
    $params[] = $supplierId;
}
switch ($flag) {
    case 'inactive':   $where[] = 'p.is_active = 0'; break;
    case 'no_barcode': $where[] = '(p.barcode IS NULL OR p.barcode = \'\')'; break;
    case 'perishable': $where[] = 'p.is_perishable = 1'; break;
    case 'age':        $where[] = 'p.age_restricted = 1'; break;
    case 'low_margin':
        // Margin on the VAT-exclusive price, against the configured floor.
        $where[] = 'p.sell_price > 0 AND p.cost_price > 0
                    AND ((p.sell_price / (1 + p.vat_rate)) - p.cost_price)
                        / (p.sell_price / (1 + p.vat_rate)) * 100 < ?';
        $params[] = setting_num('low_margin_warn_pct', (float) LOW_MARGIN_WARN_PCT);
        break;
    default:
        if ($flag === 'all') {
            $where[] = 'p.is_active = 1';
        }
}
$whereSql = implode(' AND ', $where);

$baseSql = "FROM products p
            JOIN categories c ON c.id = p.category_id
            LEFT JOIN suppliers s ON s.id = p.supplier_id
            LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
           WHERE $whereSql";

if ((string) get('export') === 'csv') {
    require_can('reports.view');
    $rows = qa("SELECT p.sku, p.barcode, p.name, c.name AS category, s.name AS supplier,
                       p.unit_code, p.cost_price, p.sell_price, p.vat_rate,
                       COALESCE(sl.qty_on_hand,0) AS qty_on_hand, COALESCE(sl.avg_cost,0) AS avg_cost,
                       p.reorder_level, p.is_perishable, p.track_batches, p.age_restricted, p.is_active
                $baseSql ORDER BY p.name", $params);
    audit_log('products.export', 'product', null,
        'Exported ' . count($rows) . ' product rows', null, null, 'notice');
    send_csv('products-' . date('Y-m-d') . '.csv', [
        'SKU', 'Barcode', 'Name', 'Category', 'Supplier', 'Unit', 'Cost', 'Sell price',
        'VAT rate', 'On hand', 'Avg cost', 'Reorder level', 'Perishable', 'Batches',
        'Age restricted', 'Active',
    ], $rows);
}

[$limit, $offset, $page] = paging(60);
$total = (int) qv("SELECT COUNT(*) $baseSql", $params, 0);

$rows = qa(
    "SELECT p.*, c.name AS category, s.name AS supplier,
            COALESCE(sl.qty_on_hand, 0) AS qty_on_hand,
            COALESCE(sl.avg_cost, p.cost_price) AS avg_cost
     $baseSql ORDER BY p.name LIMIT $limit OFFSET $offset",
    $params
);

$categories = qpairs('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name');
$suppliers  = qpairs('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name');
$marginFloor = setting_num('low_margin_warn_pct', (float) LOW_MARGIN_WARN_PCT);

layout_head([
    'title'    => 'Products',
    'subtitle' => number_format($total) . ' matching',
    'actions'  => (can('products.manage')
            ? '<a class="btn" href="' . e(base_url('inventory/product')) . '">New product</a>' : '')
        . (can('reports.view')
            ? '<a class="btn btn--ghost" href="' . e(url_with(['export' => 'csv'])) . '">Export CSV</a>' : ''),
]);
?>

<form class="filters" method="get">
  <label class="field" style="flex:1;min-width:200px">
    <span class="field__label">Search</span>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, SKU or barcode">
  </label>
  <label class="field">
    <span class="field__label">Category</span>
    <select name="category_id"><?= select_options($categories, $categoryId, true, 'All') ?></select>
  </label>
  <label class="field">
    <span class="field__label">Supplier</span>
    <select name="supplier_id"><?= select_options($suppliers, $supplierId, true, 'All') ?></select>
  </label>
  <label class="field">
    <span class="field__label">Show</span>
    <select name="flag">
      <?= select_options([
          'all'        => 'Active products',
          'inactive'   => 'Delisted',
          'low_margin' => 'Margin under ' . pct_fmt($marginFloor, 0),
          'no_barcode' => 'No barcode',
          'perishable' => 'Perishable',
          'age'        => 'Age restricted',
      ], $flag) ?>
    </select>
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('inventory/products')) ?>">Reset</a>
</form>

<?= card_open('Products') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No products match those filters.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>SKU</th><th>Name</th><th>Category</th><th>Supplier</th>
        <th class="num">On hand</th><th class="num">Avg cost</th><th class="num">Shelf price</th>
        <th class="num">Margin</th><th>Flags</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $p):
          $netPrice = (float) $p['sell_price'] / (1 + (float) $p['vat_rate']);
          $cost     = (float) $p['avg_cost'];
          $margin   = $netPrice > 0 ? (($netPrice - $cost) / $netPrice) * 100 : 0.0;
          $onHand   = (float) $p['qty_on_hand'];
          $low      = (float) $p['reorder_level'] > 0 && $onHand <= (float) $p['reorder_level']; ?>
        <tr class="<?= $margin < 0 ? 'row-bad' : ($margin < $marginFloor ? 'row-warn' : '') ?>">
          <td class="mono tiny"><?= e($p['sku']) ?></td>
          <td>
            <a href="<?= e(base_url('inventory/product', ['id' => (int) $p['id']])) ?>"><?= e($p['name']) ?></a>
            <?php if ($p['barcode'] !== null): ?>
              <br><span class="tiny muted mono"><?= e((string) $p['barcode']) ?></span>
            <?php endif; ?>
          </td>
          <td class="tiny"><?= e($p['category']) ?></td>
          <td class="tiny"><?= e($p['supplier'] ?? '-') ?></td>
          <td class="num <?= $onHand < 0 ? 'neg' : '' ?>">
            <?= e(qty_fmt($onHand)) ?>
            <span class="tiny muted"><?= e($p['unit_code']) ?></span>
            <?php if ($low && $onHand >= 0): ?><br><?= badge('Low', 'warn') ?><?php endif; ?>
          </td>
          <?= num_cell($cost) ?>
          <?= num_cell($p['sell_price']) ?>
          <td class="num <?= $margin < 0 ? 'neg' : '' ?>"><?= e(pct_fmt($margin)) ?></td>
          <td class="tiny nowrap">
            <?php if ((int) $p['is_active'] === 0): ?><?= badge('Delisted', 'bad') ?><?php endif; ?>
            <?php if ((int) $p['track_batches'] === 1): ?><?= badge('Batches', 'info') ?><?php endif; ?>
            <?php if ((int) $p['is_weighed'] === 1): ?><?= badge('Weighed', 'neutral') ?><?php endif; ?>
            <?php if ((int) $p['age_restricted'] === 1): ?><?= badge('Age', 'warn') ?><?php endif; ?>
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
  Margin is calculated on the VAT-exclusive price against the weighted-average cost of the stock
  actually held, not the list cost &mdash; so it reflects what these units really cost.
  Rows under <?= e(pct_fmt($marginFloor, 0)) ?> are tinted, and anything selling at a loss is red.
</p>

<?php layout_foot(); ?>
