<?php
/**
 * Promotions.
 *
 * Four shapes cover almost every supermarket offer: a percentage off, a fixed
 * price, buy-X-get-Y, and a percentage off once you buy a few. Each can be
 * scoped to one product, a whole category, or everything, and bounded by date,
 * day of the week and hour of the day.
 *
 * Only one promotion applies to a line - see promo_price_line(). Stacking is how
 * a shop accidentally sells below cost, and the cashier is in no position to
 * notice.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('promotions.view');

$editId = get_int('edit');

if (is_post()) {
    csrf_require();
    require_can('promotions.manage');
    $action = (string) post('action');

    try {
        if ($action === 'save') {
            $id    = post_int('id');
            $scope = one_of((string) post('scope'), ['product', 'category', 'all'], 'product');
            $type  = one_of((string) post('promo_type'), ['pct_off', 'fixed_price', 'bogo', 'bundle_pct'], 'pct_off');

            $starts = date_or_null(post('starts_at'));
            $ends   = date_or_null(post('ends_at'));
            if ($starts === null || $ends === null) {
                throw new RuntimeException('A promotion needs a start and an end date.');
            }
            if (strtotime($ends) < strtotime($starts)) {
                throw new RuntimeException('The end date cannot be before the start date.');
            }

            $productId  = $scope === 'product' ? post_int('product_id') : null;
            $categoryId = $scope === 'category' ? post_int('category_id') : null;
            if ($scope === 'product' && !$productId) {
                throw new RuntimeException('Choose the product this applies to.');
            }
            if ($scope === 'category' && !$categoryId) {
                throw new RuntimeException('Choose the category this applies to.');
            }

            $value   = post_num('value');
            $minQty  = max(1.0, post_num('min_qty', 1.0));
            $freeQty = post_num('free_qty');

            if (in_array($type, ['pct_off', 'bundle_pct'], true) && ($value <= 0 || $value >= 100)) {
                throw new RuntimeException('A percentage discount must be between 0 and 100.');
            }
            if ($type === 'fixed_price' && $value <= 0) {
                throw new RuntimeException('Enter the fixed price to charge.');
            }
            if ($type === 'bogo' && $freeQty <= 0) {
                throw new RuntimeException('A buy-X-get-Y offer needs a free quantity.');
            }

            // Selling below cost is legitimate as a loss leader, but it should be
            // a decision rather than an accident, so it is flagged at the point of
            // saving rather than discovered in next month's margin report.
            if ($type === 'fixed_price' && $productId !== null) {
                $cost = (float) qv('SELECT cost_price FROM products WHERE id = ?', [$productId], 0.0);
                if ($cost > 0 && $value < $cost) {
                    flash_warn(sprintf('Note: %s is below the %s cost of this product. '
                        . 'That is allowed, but every unit sold loses money.', money($value), money($cost)));
                }
            }

            $daysMask = 0;
            foreach ((array) ($_POST['days'] ?? []) as $bit) {
                $b = (int) $bit;
                if ($b >= 0 && $b <= 6) {
                    $daysMask |= (1 << $b);
                }
            }
            if ($daysMask === 0) {
                $daysMask = 127;   // no days ticked means every day, not never
            }

            $hourFrom = (string) post('hour_from') !== '' ? max(0, min(23, post_int('hour_from'))) : null;
            $hourTo   = (string) post('hour_to') !== '' ? max(0, min(24, post_int('hour_to'))) : null;
            if (($hourFrom === null) !== ($hourTo === null)) {
                throw new RuntimeException('An hour window needs both a from and a to, or neither.');
            }

            $data = [
                'name'        => (string) post('name'),
                'promo_type'  => $type,
                'scope'       => $scope,
                'product_id'  => $productId,
                'category_id' => $categoryId,
                'value'       => $value,
                'min_qty'     => $minQty,
                'free_qty'    => $freeQty,
                'starts_at'   => $starts . ' 00:00:00',
                'ends_at'     => $ends . ' 23:59:59',
                'hour_from'   => $hourFrom,
                'hour_to'     => $hourTo,
                'days_mask'   => $daysMask,
                'priority'    => max(1, post_int('priority', 100)),
                'is_active'   => post('is_active') === '1' ? 1 : 0,
            ];

            if ($id > 0) {
                $before = q1('SELECT * FROM promotions WHERE id = ?', [$id]);
                db_update('promotions', $data, 'id = :id', ['id' => $id]);
                audit_log('promotion.update', 'promotion', $id,
                    'Promotion updated: ' . $data['name'], $before, $data, 'notice');
                flash_ok('Promotion updated.');
            } else {
                $code = strtoupper(trim((string) post('code')));
                if ($code === '') {
                    throw new RuntimeException('Give the promotion a short code.');
                }
                if (qv('SELECT id FROM promotions WHERE code = ?', [$code])) {
                    throw new RuntimeException('That code is already in use.');
                }
                $data['code']        = $code;
                $data['created_by']  = (int) $_SESSION['uid'];
                $data['approved_by'] = (int) $_SESSION['uid'];
                $id = db_insert('promotions', $data);
                audit_log('promotion.create', 'promotion', $id,
                    'Promotion created: ' . $data['name'] . ' (' . $code . ')', null, $data, 'notice');
                flash_ok('Promotion created.');
            }
            redirect('sales/promotions');
        }

        if ($action === 'toggle') {
            $id = post_int('id');
            $p  = q1('SELECT * FROM promotions WHERE id = ?', [$id]);
            if (!$p) {
                throw new RuntimeException('That promotion no longer exists.');
            }
            $now = (int) $p['is_active'] === 1 ? 0 : 1;
            db_update('promotions', ['is_active' => $now], 'id = :id', ['id' => $id]);
            audit_log('promotion.toggle', 'promotion', $id,
                'Promotion ' . $p['code'] . ' ' . ($now ? 'switched on' : 'switched off'),
                ['is_active' => (int) $p['is_active']], ['is_active' => $now], 'notice');
            flash_ok('Promotion ' . ($now ? 'switched on' : 'switched off') . '.');
            redirect('sales/promotions');
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('sales/promotions' . ($editId > 0 ? '?edit=' . $editId : ''));
    }
}

$editing = $editId > 0 ? q1('SELECT * FROM promotions WHERE id = ?', [$editId]) : null;

$promos = qa(
    'SELECT p.*, pr.name AS product_name, c.name AS category_name,
            (SELECT COUNT(*) FROM sale_lines sl WHERE sl.promotion_id = p.id) AS times_used,
            (SELECT COALESCE(SUM(sl.line_discount),0) FROM sale_lines sl WHERE sl.promotion_id = p.id) AS given_away
       FROM promotions p
       LEFT JOIN products pr ON pr.id = p.product_id
       LEFT JOIN categories c ON c.id = p.category_id
      ORDER BY p.is_active DESC, p.ends_at DESC',
    []
);

$live = promotions_live();
$liveIds = array_fill_keys(array_map(fn ($p) => (int) $p['id'], $live), true);

$products   = qpairs('SELECT id, CONCAT(sku, \' - \', name) FROM products WHERE is_active = 1 ORDER BY name');
$categories = qpairs('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name');

layout_head([
    'title'    => 'Promotions',
    'subtitle' => count($live) . ' live right now, ' . count($promos) . ' on file',
    'actions'  => can('promotions.manage') && $editing === null
        ? '<a class="btn" href="' . e(url_with(['edit' => -1])) . '">New promotion</a>' : '',
]);
?>

<?php if (can('promotions.manage') && ($editing !== null || $editId === -1)):
    $p = $editing ?? [
        'id' => 0, 'code' => '', 'name' => '', 'promo_type' => 'pct_off', 'scope' => 'product',
        'product_id' => null, 'category_id' => null, 'value' => '', 'min_qty' => 1, 'free_qty' => 0,
        'starts_at' => date('Y-m-d'), 'ends_at' => date('Y-m-d', strtotime('+30 days')),
        'hour_from' => null, 'hour_to' => null, 'days_mask' => 127, 'priority' => 100, 'is_active' => 1,
    ]; ?>
  <?= card_open($editing !== null ? 'Edit ' . $p['code'] : 'New promotion') ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">

      <div class="form-grid">
        <?php if ($editing === null): ?>
          <label class="field">
            <span class="field__label">Code</span>
            <input type="text" name="code" required maxlength="20" placeholder="PROMO-BREAD">
          </label>
        <?php endif; ?>
        <label class="field">
          <span class="field__label">Name customers will see</span>
          <input type="text" name="name" required maxlength="140" value="<?= e((string) $p['name']) ?>"
                 placeholder="10% off all bakery before noon">
        </label>
        <label class="field">
          <span class="field__label">Type</span>
          <select name="promo_type">
            <?= select_options([
                'pct_off'     => 'Percentage off',
                'fixed_price' => 'Fixed price each',
                'bogo'        => 'Buy X, get Y free',
                'bundle_pct'  => 'Percentage off when buying several',
            ], $p['promo_type']) ?>
          </select>
        </label>
        <label class="field">
          <span class="field__label">Applies to</span>
          <select name="scope">
            <?= select_options([
                'product'  => 'One product',
                'category' => 'A whole category',
                'all'      => 'Everything in the shop',
            ], $p['scope']) ?>
          </select>
        </label>
        <label class="field">
          <span class="field__label">Product (if scoped to one)</span>
          <select name="product_id"><?= select_options($products, $p['product_id'], true, '-- none --') ?></select>
        </label>
        <label class="field">
          <span class="field__label">Category (if scoped to one)</span>
          <select name="category_id"><?= select_options($categories, $p['category_id'], true, '-- none --') ?></select>
        </label>
        <label class="field">
          <span class="field__label">Value</span>
          <input type="text" name="value" class="num-in" value="<?= e((string) $p['value']) ?>">
          <span class="field__hint">Percentage for a percentage offer, or the price to charge for a fixed price.</span>
        </label>
        <label class="field">
          <span class="field__label">Minimum quantity</span>
          <input type="text" name="min_qty" class="num-in" value="<?= e(qty_fmt((float) $p['min_qty'])) ?>">
          <span class="field__hint">"Buy this many" for a bundle or a buy-X-get-Y.</span>
        </label>
        <label class="field">
          <span class="field__label">Free quantity</span>
          <input type="text" name="free_qty" class="num-in" value="<?= e(qty_fmt((float) $p['free_qty'])) ?>">
          <span class="field__hint">Only for buy-X-get-Y.</span>
        </label>
        <label class="field">
          <span class="field__label">Starts</span>
          <input type="date" name="starts_at" required value="<?= e(date('Y-m-d', strtotime((string) $p['starts_at']))) ?>">
        </label>
        <label class="field">
          <span class="field__label">Ends</span>
          <input type="date" name="ends_at" required value="<?= e(date('Y-m-d', strtotime((string) $p['ends_at']))) ?>">
        </label>
        <label class="field">
          <span class="field__label">From hour (optional)</span>
          <input type="number" name="hour_from" min="0" max="23" class="num-in"
                 value="<?= e($p['hour_from'] !== null ? (string) $p['hour_from'] : '') ?>">
        </label>
        <label class="field">
          <span class="field__label">To hour (optional)</span>
          <input type="number" name="hour_to" min="0" max="24" class="num-in"
                 value="<?= e($p['hour_to'] !== null ? (string) $p['hour_to'] : '') ?>">
          <span class="field__hint">A window like 22 to 2 crosses midnight and is handled.</span>
        </label>
        <label class="field">
          <span class="field__label">Priority</span>
          <input type="number" name="priority" min="1" class="num-in" value="<?= (int) $p['priority'] ?>">
          <span class="field__hint">Lowest number wins when more than one offer could apply.</span>
        </label>
      </div>

      <fieldset>
        <legend>Days it runs</legend>
        <div style="display:flex;gap:.9rem;flex-wrap:wrap">
          <?php foreach (promo_day_names() as $bit => $dayName): ?>
            <label class="check" style="margin:0">
              <input type="checkbox" name="days[]" value="<?= $bit ?>"
                     <?= ((int) $p['days_mask'] & (1 << $bit)) ? 'checked' : '' ?>>
              <?= e($dayName) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <div class="check">
        <input type="checkbox" name="is_active" value="1" id="promo-active"
               <?= (int) $p['is_active'] === 1 ? 'checked' : '' ?>>
        <label for="promo-active">Active &mdash; tills will apply it inside its window</label>
      </div>

      <div class="btn-row">
        <button class="btn" type="submit"><?= $editing !== null ? 'Save changes' : 'Create promotion' ?></button>
        <a class="btn btn--ghost" href="<?= e(base_url('sales/promotions')) ?>">Cancel</a>
      </div>
    </form>
  <?= card_close() ?>
<?php endif; ?>

<?= card_open('All promotions') ?>
  <?php if (!$promos): ?>
    <?= empty_state('No promotions set up.',
          can('promotions.manage') ? 'Create one to see it applied at the till.' : '') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Code</th><th>Name</th><th>Offer</th><th>Applies to</th>
        <th>Window</th><th>Days</th><th class="num">Times used</th>
        <th class="num">Given away</th><th>State</th><?= can('promotions.manage') ? '<th></th>' : '' ?>
      </tr></thead>
      <tbody>
      <?php foreach ($promos as $p):
          $isLive  = isset($liveIds[(int) $p['id']]);
          $expired = strtotime((string) $p['ends_at']) < time(); ?>
        <tr class="<?= $isLive ? '' : ($expired ? 'row-warn' : '') ?>">
          <td class="mono tiny"><?= e($p['code']) ?></td>
          <td><?= e($p['name']) ?></td>
          <td class="tiny"><?= e(promo_describe($p)) ?></td>
          <td class="tiny"><?= e(promo_scope_label($p)) ?></td>
          <td class="tiny nowrap">
            <?= e(date_fmt((string) $p['starts_at'])) ?><br>
            to <?= e(date_fmt((string) $p['ends_at'])) ?>
            <?php if ($p['hour_from'] !== null): ?>
              <br><?= sprintf('%02d:00-%02d:00', (int) $p['hour_from'], (int) $p['hour_to']) ?>
            <?php endif; ?>
          </td>
          <td class="tiny"><?= e(promo_days_label((int) $p['days_mask'])) ?></td>
          <td class="num"><?= number_format((int) $p['times_used']) ?></td>
          <?= num_cell($p['given_away']) ?>
          <td>
            <?php if ($isLive): ?><?= badge('Live now', 'ok') ?>
            <?php elseif ((int) $p['is_active'] === 0): ?><?= badge('Off', 'neutral') ?>
            <?php elseif ($expired): ?><?= badge('Expired', 'bad') ?>
            <?php elseif (strtotime((string) $p['starts_at']) > time()): ?><?= badge('Scheduled', 'info') ?>
            <?php else: ?><?= badge('Out of window', 'warn') ?><?php endif; ?>
          </td>
          <?php if (can('promotions.manage')): ?>
            <td class="right nowrap">
              <a class="btn btn--sm btn--ghost" href="<?= e(url_with(['edit' => (int) $p['id']])) ?>">Edit</a>
              <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn--sm btn--ghost" type="submit">
                  <?= (int) $p['is_active'] === 1 ? 'Switch off' : 'Switch on' ?>
                </button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?php layout_foot(); ?>
