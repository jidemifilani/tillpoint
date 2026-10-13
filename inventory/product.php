<?php
/**
 * Create or edit one product, with its price history and stock position.
 *
 * Price changes do not go through the ordinary save: they are routed through
 * product_change_price(), which writes the before and after into price_history
 * and raises an exception if the new price is at or below cost. A plain UPDATE
 * on cost_price would lose exactly the evidence a margin investigation needs.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('products.view');

$id      = get_int('id');
$storeId = current_store_id();
$isNew   = $id === 0;

if (is_post()) {
    csrf_require();
    require_can('products.manage');

    try {
        $action = (string) post('action');

        if ($action === 'save') {
            $name = trim((string) post('name'));
            $sku  = strtoupper(trim((string) post('sku')));
            if ($name === '' || $sku === '') {
                throw new RuntimeException('A product needs a name and a SKU.');
            }

            $barcode = trim((string) post('barcode'));
            if ($barcode !== '') {
                $clash = (int) qv('SELECT COUNT(*) FROM products WHERE barcode = ? AND id <> ?',
                    [$barcode, $id], 0);
                if ($clash > 0) {
                    throw new RuntimeException('That barcode is already on another product.');
                }
                // A mistyped EAN is worth flagging but not refusing: shops carry
                // own-label goods with internal codes that are not valid EANs.
                if (preg_match('/^\d{13}$/', $barcode) && !ean13_valid($barcode)) {
                    flash_warn('That looks like an EAN-13 but the check digit is wrong. Saved anyway - '
                        . 'verify it scans before putting the product on the shelf.');
                }
            }

            $cost  = post_num('cost_price');
            $price = post_num('sell_price');
            $data  = [
                'sku'             => $sku,
                'barcode'         => $barcode !== '' ? $barcode : null,
                'name'            => $name,
                'description'     => (string) post('description') !== '' ? (string) post('description') : null,
                'category_id'     => post_int('category_id'),
                'unit_code'       => (string) post('unit_code'),
                'supplier_id'     => post_int('supplier_id') ?: null,
                'pack_size'       => max(1.0, post_num('pack_size', 1.0)),
                'vat_rate'        => post_num('vat_rate') / 100,
                'is_weighed'      => post('is_weighed') === '1' ? 1 : 0,
                'is_perishable'   => post('is_perishable') === '1' ? 1 : 0,
                'track_batches'   => post('track_batches') === '1' ? 1 : 0,
                'shelf_life_days' => post_int('shelf_life_days') ?: null,
                'reorder_level'   => post_num('reorder_level'),
                'reorder_qty'     => post_num('reorder_qty'),
                'age_restricted'  => post('age_restricted') === '1' ? 1 : 0,
                'is_active'       => post('is_active') === '1' ? 1 : 0,
            ];

            if ($data['category_id'] === 0) {
                throw new RuntimeException('Choose a category.');
            }
            if ($data['is_weighed'] === 1 && (int) qv('SELECT is_decimal FROM units WHERE code = ?',
                    [$data['unit_code']], 0) === 0) {
                throw new RuntimeException('A weighed product needs a unit that allows fractions, such as kg or litres.');
            }

            if ($isNew) {
                if (qv('SELECT id FROM products WHERE sku = ?', [$sku])) {
                    throw new RuntimeException('That SKU is already in use.');
                }
                $data['cost_price'] = $cost;
                $data['sell_price'] = $price;
                $data['created_by'] = (int) $_SESSION['uid'];

                $id = db_insert('products', $data);
                db_insert('price_history', [
                    'product_id' => $id,
                    'old_cost'   => null, 'new_cost'  => $cost,
                    'old_price'  => null, 'new_price' => $price,
                    'reason'     => 'Product created',
                    'changed_by' => (int) $_SESSION['uid'],
                ]);
                audit_log('product.create', 'product', $id,
                    sprintf('Product created: %s (%s) at %s, cost %s', $name, $sku, money($price), money($cost)),
                    null, $data, 'notice');
                flash_ok('Product created.');
            } else {
                $before = q1('SELECT * FROM products WHERE id = ?', [$id]);
                if (!$before) {
                    throw new RuntimeException('That product no longer exists.');
                }
                // Prices are deliberately excluded here and handled below.
                db_update('products', $data, 'id = :id', ['id' => $id]);
                audit_log('product.update', 'product', $id,
                    'Product updated: ' . $name, $before, $data, 'info');

                if (can('products.price')) {
                    $reason = trim((string) post('price_reason'));
                    if (abs($cost - (float) $before['cost_price']) > 0.00005
                        || abs($price - (float) $before['sell_price']) > 0.005) {
                        if ($reason === '') {
                            throw new RuntimeException('A cost or price change needs a reason.');
                        }
                        product_change_price($id, $cost, $price, $reason, (int) $_SESSION['uid']);
                    }
                }
                flash_ok('Product saved.');
            }
            redirect('inventory/product', ['id' => $id]);
        }

        if ($action === 'add_barcode') {
            $code = trim((string) post('extra_barcode'));
            if ($code === '') {
                throw new RuntimeException('Enter the barcode.');
            }
            if (qv('SELECT id FROM product_barcodes WHERE barcode = ?', [$code])
                || qv('SELECT id FROM products WHERE barcode = ?', [$code])) {
                throw new RuntimeException('That barcode is already in use.');
            }
            db_insert('product_barcodes', [
                'product_id'   => $id,
                'barcode'      => $code,
                'qty_per_scan' => max(1.0, post_num('qty_per_scan', 1.0)),
                'label'        => (string) post('barcode_label') !== '' ? (string) post('barcode_label') : null,
            ]);
            audit_log('product.barcode', 'product', $id,
                'Extra barcode added: ' . $code, null, null, 'info');
            flash_ok('Barcode added.');
            redirect('inventory/product', ['id' => $id]);
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect($isNew ? 'inventory/product' : 'inventory/product?id=' . $id);
    }
}

$p = $isNew
    ? [
        'id' => 0, 'sku' => '', 'barcode' => '', 'name' => '', 'description' => '',
        'category_id' => 0, 'unit_code' => 'EA', 'supplier_id' => null, 'pack_size' => 1,
        'cost_price' => '', 'sell_price' => '', 'vat_rate' => VAT_RATE,
        'is_weighed' => 0, 'is_perishable' => 0, 'track_batches' => 0,
        'shelf_life_days' => '', 'reorder_level' => 0, 'reorder_qty' => 0,
        'age_restricted' => 0, 'is_active' => 1,
    ]
    : q1('SELECT * FROM products WHERE id = ?', [$id]);

if (!$p) {
    http_response_code(404);
    require APP_ROOT . '/404.php';
    exit;
}

$categories = qpairs('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name');
$suppliers  = qpairs('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name');
$units      = qpairs('SELECT code, CONCAT(name, " (", code, ")") FROM units ORDER BY name');

$levels = $isNew ? [] : qa(
    'SELECT sl.*, st.name AS store_name, st.code AS store_code
       FROM stock_levels sl JOIN stores st ON st.id = sl.store_id
      WHERE sl.product_id = ? ORDER BY st.name', [$id]
);
$batches = $isNew ? [] : qa(
    'SELECT b.*, st.name AS store_name, DATEDIFF(b.expiry_date, CURDATE()) AS days_left
       FROM stock_batches b JOIN stores st ON st.id = b.store_id
      WHERE b.product_id = ? AND b.qty_on_hand > 0
      ORDER BY (b.expiry_date IS NULL), b.expiry_date ASC', [$id]
);
$history = $isNew ? [] : qa(
    'SELECT ph.*, u.full_name AS who FROM price_history ph
       LEFT JOIN users u ON u.id = ph.changed_by
      WHERE ph.product_id = ? ORDER BY ph.id DESC LIMIT 30', [$id]
);
$extraBarcodes = $isNew ? [] : qa('SELECT * FROM product_barcodes WHERE product_id = ? ORDER BY id', [$id]);
$salesStats = $isNew ? null : q1(
    'SELECT COALESCE(SUM(sl.qty),0) AS units, COALESCE(SUM(sl.line_total),0) AS revenue,
            COALESCE(SUM(sl.qty * sl.unit_cost),0) AS cost, COUNT(DISTINCT s.id) AS baskets
       FROM sale_lines sl JOIN sales s ON s.id = sl.sale_id
      WHERE sl.product_id = ? AND s.status IN (\'completed\',\'refunded\',\'partly_refunded\')
        AND s.business_date >= (CURDATE() - INTERVAL 30 DAY)', [$id]
);

$netPrice  = (float) $p['sell_price'] / (1 + (float) $p['vat_rate']);
$margin    = $netPrice > 0 ? (($netPrice - (float) $p['cost_price']) / $netPrice) * 100 : 0.0;
$canPrice  = can('products.price');

layout_head([
    'title'    => $isNew ? 'New product' : (string) $p['name'],
    'subtitle' => $isNew ? 'Add something to the product file' : $p['sku'] . ' - ' . ($p['barcode'] ?? 'no barcode'),
    'actions'  => '<a class="btn btn--ghost" href="' . e(base_url('inventory/products')) . '">All products</a>',
]);
?>

<?php if (!$isNew): ?>
  <div class="tiles">
    <?= tile('Shelf price', money((float) $p['sell_price']),
          'net ' . money($netPrice) . ' + VAT') ?>
    <?= tile('Cost', money((float) $p['cost_price']), 'current list cost', 'mute') ?>
    <?= tile('Margin', pct_fmt($margin), 'on the net price',
          $margin < 0 ? 'bad' : ($margin < setting_num('low_margin_warn_pct', (float) LOW_MARGIN_WARN_PCT) ? 'warn' : 'ok')) ?>
    <?= tile('Sold in 30 days', qty_fmt((float) ($salesStats['units'] ?? 0)),
          number_format((int) ($salesStats['baskets'] ?? 0)) . ' baskets') ?>
    <?= tile('Revenue 30 days', money((float) ($salesStats['revenue'] ?? 0)),
          'gross profit ' . money((float) ($salesStats['revenue'] ?? 0)
              / (1 + (float) $p['vat_rate']) - (float) ($salesStats['cost'] ?? 0))) ?>
    <?= tile('Held across stores',
          qty_fmt(array_sum(array_map(fn ($l) => (float) $l['qty_on_hand'], $levels))),
          count($levels) . ' location(s)', 'info') ?>
  </div>
<?php endif; ?>

<?php if (can('products.manage')): ?>
<?= card_open($isNew ? 'Product details' : 'Edit product') ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <fieldset>
      <legend>Identification</legend>
      <div class="form-grid">
        <label class="field">
          <span class="field__label">SKU</span>
          <input type="text" name="sku" required maxlength="30" value="<?= e((string) $p['sku']) ?>"
                 <?= $isNew ? 'data-autofocus' : '' ?>>
        </label>
        <label class="field">
          <span class="field__label">Primary barcode</span>
          <input type="text" name="barcode" maxlength="40" value="<?= e((string) ($p['barcode'] ?? '')) ?>">
          <span class="field__hint">What the scanner reads. Leave blank for loose goods.</span>
        </label>
        <label class="field">
          <span class="field__label">Name</span>
          <input type="text" name="name" required maxlength="160" value="<?= e((string) $p['name']) ?>">
        </label>
        <label class="field">
          <span class="field__label">Category</span>
          <select name="category_id" required>
            <?= select_options($categories, $p['category_id'], true, '-- choose --') ?>
          </select>
        </label>
        <label class="field">
          <span class="field__label">Preferred supplier</span>
          <select name="supplier_id"><?= select_options($suppliers, $p['supplier_id'], true, '-- none --') ?></select>
        </label>
        <label class="field">
          <span class="field__label">Unit of sale</span>
          <select name="unit_code" required><?= select_options($units, $p['unit_code']) ?></select>
        </label>
      </div>
      <label class="field">
        <span class="field__label">Description</span>
        <input type="text" name="description" maxlength="255" value="<?= e((string) ($p['description'] ?? '')) ?>">
      </label>
    </fieldset>

    <fieldset>
      <legend>Price<?= $canPrice ? '' : ' (you cannot change these)' ?></legend>
      <div class="form-grid">
        <label class="field">
          <span class="field__label">Cost price</span>
          <input type="text" name="cost_price" class="num-in" required
                 value="<?= e($isNew ? '' : number_format((float) $p['cost_price'], 4, '.', '')) ?>"
                 <?= $canPrice ? '' : 'readonly' ?>>
        </label>
        <label class="field">
          <span class="field__label">Shelf price (VAT inclusive)</span>
          <input type="text" name="sell_price" class="num-in" required
                 value="<?= e($isNew ? '' : number_format((float) $p['sell_price'], 2, '.', '')) ?>"
                 <?= $canPrice ? '' : 'readonly' ?>>
          <span class="field__hint">What the customer pays. VAT is derived from it, not added to it.</span>
        </label>
        <label class="field">
          <span class="field__label">VAT rate (%)</span>
          <input type="text" name="vat_rate" class="num-in"
                 value="<?= e(number_format((float) $p['vat_rate'] * 100, 2, '.', '')) ?>">
        </label>
        <label class="field">
          <span class="field__label">Units per supplier case</span>
          <input type="text" name="pack_size" class="num-in"
                 value="<?= e(qty_fmt((float) $p['pack_size'])) ?>">
          <span class="field__hint">Reorder suggestions round up to whole cases.</span>
        </label>
      </div>
      <?php if (!$isNew && $canPrice): ?>
        <label class="field">
          <span class="field__label">Reason, if you are changing the cost or price</span>
          <input type="text" name="price_reason" maxlength="160"
                 placeholder="Supplier increase, competitor match, markdown">
          <span class="field__hint">
            Required for a price change. Kept in the price history with your name against it.
          </span>
        </label>
      <?php endif; ?>
    </fieldset>

    <fieldset>
      <legend>Stock behaviour</legend>
      <div class="form-grid">
        <label class="field">
          <span class="field__label">Reorder level</span>
          <input type="text" name="reorder_level" class="num-in"
                 value="<?= e(qty_fmt((float) $p['reorder_level'])) ?>">
          <span class="field__hint">Zero means it never appears in replenishment.</span>
        </label>
        <label class="field">
          <span class="field__label">Reorder quantity</span>
          <input type="text" name="reorder_qty" class="num-in"
                 value="<?= e(qty_fmt((float) $p['reorder_qty'])) ?>">
        </label>
        <label class="field">
          <span class="field__label">Shelf life (days)</span>
          <input type="text" name="shelf_life_days" class="num-in"
                 value="<?= e((string) ($p['shelf_life_days'] ?? '')) ?>">
          <span class="field__hint">Used to default an expiry date when a delivery does not state one.</span>
        </label>
      </div>
      <div class="check">
        <input type="checkbox" name="is_active" value="1" id="f-active" <?= (int) $p['is_active'] === 1 ? 'checked' : '' ?>>
        <label for="f-active"><strong>Active</strong> &mdash; tills can sell it</label>
      </div>
      <div class="check">
        <input type="checkbox" name="is_weighed" value="1" id="f-weighed" <?= (int) $p['is_weighed'] === 1 ? 'checked' : '' ?>>
        <label for="f-weighed"><strong>Sold by weight</strong> &mdash; fractional quantities allowed at the till</label>
      </div>
      <div class="check">
        <input type="checkbox" name="is_perishable" value="1" id="f-perish" <?= (int) $p['is_perishable'] === 1 ? 'checked' : '' ?>>
        <label for="f-perish"><strong>Perishable</strong> &mdash; appears in date-check and wastage reporting</label>
      </div>
      <div class="check">
        <input type="checkbox" name="track_batches" value="1" id="f-batch" <?= (int) $p['track_batches'] === 1 ? 'checked' : '' ?>>
        <label for="f-batch">
          <strong>Track batches and expiry</strong><br>
          <span class="small muted">
            A batch is created per delivery and consumed first-expiry-first-out, so a recall can
            name the affected deliveries. Turn this on for anything with a use-by date.
          </span>
        </label>
      </div>
      <div class="check">
        <input type="checkbox" name="age_restricted" value="1" id="f-age" <?= (int) $p['age_restricted'] === 1 ? 'checked' : '' ?>>
        <label for="f-age"><strong>Age restricted</strong> &mdash; the till prompts the cashier to check ID</label>
      </div>
    </fieldset>

    <div class="btn-row">
      <button class="btn btn--lg" type="submit"><?= $isNew ? 'Create product' : 'Save changes' ?></button>
      <a class="btn btn--ghost" href="<?= e(base_url('inventory/products')) ?>">Cancel</a>
    </div>
  </form>
<?= card_close() ?>
<?php endif; ?>

<?php if (!$isNew): ?>
<div class="grid-2">
  <?= card_open('Stock by location') ?>
    <?php if (!$levels): ?>
      <?= empty_state('None of this product is held anywhere.') ?>
    <?php else: ?>
      <table class="data data--tight">
        <thead><tr>
          <th>Store</th><th class="num">On hand</th><th class="num">Reserved</th>
          <th class="num">Available</th><th class="num">Avg cost</th><th>Last counted</th>
        </tr></thead>
        <tbody>
        <?php foreach ($levels as $l):
            $avail = (float) $l['qty_on_hand'] - (float) $l['qty_reserved']; ?>
          <tr>
            <td><?= e($l['store_name']) ?></td>
            <td class="num <?= (float) $l['qty_on_hand'] < 0 ? 'neg' : '' ?>">
              <?= e(qty_fmt((float) $l['qty_on_hand'])) ?></td>
            <td class="num"><?= e(qty_fmt((float) $l['qty_reserved'])) ?></td>
            <td class="num"><strong><?= e(qty_fmt($avail)) ?></strong></td>
            <?= num_cell($l['avg_cost']) ?>
            <td class="tiny"><?= e($l['last_counted_at'] !== null ? ago((string) $l['last_counted_at']) : 'never') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?= card_close() ?>

  <?= card_open('Extra barcodes') ?>
    <?php if ($extraBarcodes): ?>
      <table class="data data--tight">
        <thead><tr><th>Barcode</th><th class="num">Units per scan</th><th>Label</th></tr></thead>
        <tbody>
        <?php foreach ($extraBarcodes as $b): ?>
          <tr>
            <td class="mono"><?= e($b['barcode']) ?></td>
            <td class="num"><?= e(qty_fmt((float) $b['qty_per_scan'])) ?></td>
            <td class="tiny"><?= e($b['label'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="small muted">No extra barcodes. A case or multipack code can be added here.</p>
    <?php endif; ?>

    <?php if (can('products.manage')): ?>
      <form method="post" class="mt1">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_barcode">
        <div class="form-grid form-grid--3">
          <label class="field">
            <span class="field__label">Barcode</span>
            <input type="text" name="extra_barcode" required maxlength="40">
          </label>
          <label class="field">
            <span class="field__label">Units per scan</span>
            <input type="text" name="qty_per_scan" class="num-in" value="1">
          </label>
          <label class="field">
            <span class="field__label">Label</span>
            <input type="text" name="barcode_label" maxlength="60" placeholder="Case of 12">
          </label>
        </div>
        <button class="btn btn--sm" type="submit">Add barcode</button>
      </form>
    <?php endif; ?>
  <?= card_close() ?>
</div>

<?php if ($batches): ?>
<?= card_open('Open batches (first expiry, first out)') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Batch</th><th>Store</th><th>Expires</th><th class="num">Days left</th>
      <th class="num">On hand</th><th class="num">Unit cost</th><th class="num">At risk</th><th>Received</th>
    </tr></thead>
    <tbody>
    <?php foreach ($batches as $b):
        $left = $b['days_left'] !== null ? (int) $b['days_left'] : null; ?>
      <tr class="<?= $left !== null && $left < 0 ? 'row-bad' : ($left !== null && $left <= 7 ? 'row-warn' : '') ?>">
        <td class="mono tiny"><?= e($b['batch_no']) ?></td>
        <td class="tiny"><?= e($b['store_name']) ?></td>
        <td class="nowrap tiny"><?= e($b['expiry_date'] !== null ? date_fmt((string) $b['expiry_date']) : 'no date') ?></td>
        <td class="num <?= $left !== null && $left < 0 ? 'neg' : '' ?>">
          <?= e($left !== null ? (string) $left : '-') ?></td>
        <td class="num"><?= e(qty_fmt((float) $b['qty_on_hand'])) ?></td>
        <?= num_cell($b['unit_cost']) ?>
        <?= num_cell((float) $b['qty_on_hand'] * (float) $b['unit_cost']) ?>
        <td class="tiny"><?= e(date_fmt((string) $b['received_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?= card_open('Price history') ?>
  <?php if (!$history): ?>
    <?= empty_state('No price changes recorded.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>When</th><th class="num">Cost was</th><th class="num">Cost now</th>
        <th class="num">Price was</th><th class="num">Price now</th>
        <th class="num">Margin now</th><th>Reason</th><th>By</th>
      </tr></thead>
      <tbody>
      <?php foreach ($history as $h):
          $newNet = (float) ($h['new_price'] ?? 0) / (1 + (float) $p['vat_rate']);
          $m      = $newNet > 0 ? (($newNet - (float) ($h['new_cost'] ?? 0)) / $newNet) * 100 : 0.0; ?>
        <tr class="<?= $m < 0 ? 'row-bad' : '' ?>">
          <td class="nowrap tiny"><?= e(date_fmt((string) $h['created_at'], true)) ?></td>
          <td class="num"><?= e($h['old_cost'] !== null ? money((float) $h['old_cost']) : '-') ?></td>
          <td class="num"><?= e($h['new_cost'] !== null ? money((float) $h['new_cost']) : '-') ?></td>
          <td class="num"><?= e($h['old_price'] !== null ? money((float) $h['old_price']) : '-') ?></td>
          <td class="num"><?= e($h['new_price'] !== null ? money((float) $h['new_price']) : '-') ?></td>
          <td class="num <?= $m < 0 ? 'neg' : '' ?>"><?= e(pct_fmt($m)) ?></td>
          <td class="tiny"><?= e($h['reason'] ?? '') ?></td>
          <td class="tiny"><?= e($h['who'] ?? 'system') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<p class="small">
  <a href="<?= e(base_url('inventory/movements', ['product_id' => $id])) ?>">
    See every stock movement for this product &rarr;
  </a>
</p>
<?php endif; ?>

<?php layout_foot(); ?>
