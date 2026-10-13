<?php
/**
 * Categories.
 *
 * Each carries a target margin, which the margin report measures actual
 * performance against. A category target is the only sensible place to hold
 * that: a per-product target would never be maintained, and a shop-wide one
 * tells you nothing, because fresh produce and tinned goods do not and should
 * not earn the same margin.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('products.manage');

$storeId = current_store_id();

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');

        if ($action === 'create' || $action === 'update') {
            $name = trim((string) post('name'));
            if ($name === '') {
                throw new RuntimeException('Enter the category name.');
            }
            $parentId = post_int('parent_id') ?: null;
            $id       = post_int('id');

            if ($action === 'update' && $parentId === $id) {
                throw new RuntimeException('A category cannot be its own parent.');
            }

            $data = [
                'name'              => $name,
                'parent_id'         => $parentId,
                'vat_applicable'    => post('vat_applicable') === '1' ? 1 : 0,
                'target_margin_pct' => post_num('target_margin_pct'),
                'is_active'         => post('is_active') === '1' ? 1 : 0,
            ];

            if ($action === 'create') {
                $code = strtoupper(trim((string) post('code')));
                if ($code === '') {
                    throw new RuntimeException('Give the category a short code.');
                }
                if (qv('SELECT id FROM categories WHERE code = ?', [$code])) {
                    throw new RuntimeException('That code is already in use.');
                }
                $data['code'] = $code;
                $newId = db_insert('categories', $data);
                audit_log('category.create', 'category', $newId,
                    'Category added: ' . $name . ' (' . $code . ')', null, $data, 'notice');
                flash_ok('Category added.');
            } else {
                $before = q1('SELECT * FROM categories WHERE id = ?', [$id]);
                if (!$before) {
                    throw new RuntimeException('That category no longer exists.');
                }
                // Deactivating a category with live products would hide them from
                // the product file while leaving them sellable, so it is refused.
                if ($data['is_active'] === 0 && (int) $before['is_active'] === 1) {
                    $live = (int) qv('SELECT COUNT(*) FROM products WHERE category_id = ? AND is_active = 1',
                        [$id], 0);
                    if ($live > 0) {
                        throw new RuntimeException("$live active product(s) are still in this category. "
                            . 'Move or delist them before switching it off.');
                    }
                }
                db_update('categories', $data, 'id = :id', ['id' => $id]);
                audit_log('category.update', 'category', $id,
                    'Category updated: ' . $name, $before, $data, 'info');
                flash_ok('Category updated.');
            }
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect('inventory/categories');
}

$editId  = get_int('edit');
$editing = $editId > 0 ? q1('SELECT * FROM categories WHERE id = ?', [$editId]) : null;

$from = date('Y-m-d', strtotime('-30 days'));
$to   = date('Y-m-d');

$rows = qa(
    'SELECT c.*, pc.name AS parent_name,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) AS products,
            (SELECT COALESCE(SUM(sl.qty_on_hand * sl.avg_cost),0)
               FROM stock_levels sl JOIN products p ON p.id = sl.product_id
              WHERE p.category_id = c.id AND sl.store_id = ?) AS stock_value
       FROM categories c
       LEFT JOIN categories pc ON pc.id = c.parent_id
      ORDER BY c.name',
    [$storeId]
);

// Actual margin over the last 30 days, to compare against the target.
$actual = [];
foreach (report_margin_by_category($storeId, $from, $to) as $m) {
    $net  = (float) $m['net_revenue'];
    $cost = (float) $m['cost'];
    $actual[(int) $m['id']] = [
        'net'    => $net,
        'margin' => $net > 0 ? (($net - $cost) / $net) * 100 : null,
        'units'  => (float) $m['units'],
    ];
}

$parents = qpairs('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name');

layout_head([
    'title'    => 'Categories',
    'subtitle' => count($rows) . ' categories, margin measured over the last 30 days',
    'actions'  => $editing === null
        ? '<a class="btn" href="' . e(url_with(['edit' => -1])) . '">New category</a>' : '',
]);
?>

<?php if ($editing !== null || $editId === -1):
    $c = $editing ?? [
        'id' => 0, 'code' => '', 'name' => '', 'parent_id' => null,
        'vat_applicable' => 1, 'target_margin_pct' => 20, 'is_active' => 1,
    ]; ?>
  <?= card_open($editing !== null ? 'Edit ' . $c['name'] : 'New category') ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $editing !== null ? 'update' : 'create' ?>">
      <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
      <div class="form-grid">
        <?php if ($editing === null): ?>
          <label class="field">
            <span class="field__label">Code</span>
            <input type="text" name="code" required maxlength="20" placeholder="FRESH">
          </label>
        <?php endif; ?>
        <label class="field">
          <span class="field__label">Name</span>
          <input type="text" name="name" required maxlength="100" value="<?= e((string) $c['name']) ?>">
        </label>
        <label class="field">
          <span class="field__label">Parent category</span>
          <select name="parent_id"><?= select_options($parents, $c['parent_id'], true, '-- top level --') ?></select>
        </label>
        <label class="field">
          <span class="field__label">Target margin (%)</span>
          <input type="text" name="target_margin_pct" class="num-in"
                 value="<?= e(number_format((float) $c['target_margin_pct'], 2, '.', '')) ?>">
          <span class="field__hint">What the margin report measures actual performance against.</span>
        </label>
      </div>
      <div class="check">
        <input type="checkbox" name="vat_applicable" value="1" id="cat-vat"
               <?= (int) $c['vat_applicable'] === 1 ? 'checked' : '' ?>>
        <label for="cat-vat">VAT applies to products in this category</label>
      </div>
      <div class="check">
        <input type="checkbox" name="is_active" value="1" id="cat-active"
               <?= (int) $c['is_active'] === 1 ? 'checked' : '' ?>>
        <label for="cat-active">Active</label>
      </div>
      <div class="btn-row">
        <button class="btn" type="submit"><?= $editing !== null ? 'Save changes' : 'Add category' ?></button>
        <a class="btn btn--ghost" href="<?= e(base_url('inventory/categories')) ?>">Cancel</a>
      </div>
    </form>
  <?= card_close() ?>
<?php endif; ?>

<?= card_open('Categories') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No categories set up.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Code</th><th>Name</th><th>Parent</th>
        <th class="num">Products</th><th class="num">Stock at cost</th>
        <th class="num">Sales, 30d</th><th class="num">Target margin</th>
        <th class="num">Actual margin</th><th>VAT</th><th>State</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $c):
          $a       = $actual[(int) $c['id']] ?? null;
          $target  = (float) $c['target_margin_pct'];
          $margin  = $a['margin'] ?? null;
          $missing = $margin !== null && $margin < $target - 2; ?>
        <tr class="<?= (int) $c['is_active'] === 0 ? 'row-warn' : ($missing ? 'row-bad' : '') ?>">
          <td class="mono tiny"><?= e($c['code']) ?></td>
          <td><?= e($c['name']) ?></td>
          <td class="tiny"><?= e($c['parent_name'] ?? '-') ?></td>
          <td class="num"><?= number_format((int) $c['products']) ?></td>
          <?= num_cell($c['stock_value']) ?>
          <?= num_cell($a['net'] ?? 0) ?>
          <td class="num"><?= e(pct_fmt($target)) ?></td>
          <td class="num <?= $missing ? 'neg' : '' ?>">
            <?= e($margin !== null ? pct_fmt($margin) : '-') ?>
            <?php if ($missing): ?>
              <br><span class="tiny"><?= e(pct_fmt($target - $margin)) ?> under</span>
            <?php endif; ?>
          </td>
          <td><?= (int) $c['vat_applicable'] === 1 ? badge('Yes', 'neutral') : badge('No', 'info') ?></td>
          <td><?= (int) $c['is_active'] === 1 ? badge('Active', 'ok') : badge('Off', 'neutral') ?></td>
          <td class="right">
            <a class="btn btn--sm btn--ghost" href="<?= e(url_with(['edit' => (int) $c['id']])) ?>">Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<p class="small muted">
  Actual margin is measured on VAT-exclusive revenue against the cost of the goods actually sold
  over the last 30 days. Rows more than two points under target are marked &mdash; usually a cost
  increase that never reached the shelf price. The
  <a href="<?= e(base_url('reports/margin')) ?>">margin report</a> breaks it down by product.
</p>

<?php layout_foot(); ?>
