<?php
/**
 * Stores and terminals.
 *
 * A store is never deleted, only deactivated. Its stock ledger, sales and audit
 * trail all point at it, and removing the row would leave years of records
 * belonging to nowhere. The same applies to a terminal: every receipt names the
 * till it was rung up on.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('admin.stores');

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');

        /* ---- Stores ------------------------------------------------ */
        if ($action === 'save_store') {
            $storeId = post_int('store_id');
            $name    = trim((string) post('name'));
            if ($name === '') {
                throw new RuntimeException('Give the store a name.');
            }
            $data = [
                'name'                 => $name,
                'address'              => (string) post('address') !== '' ? (string) post('address') : null,
                'phone'                => (string) post('phone') !== '' ? (string) post('phone') : null,
                'tin'                  => (string) post('tin') !== '' ? (string) post('tin') : null,
                'is_warehouse'         => post('is_warehouse') === '1' ? 1 : 0,
                'shrinkage_target_pct' => post_num('shrinkage_target_pct', 2.0),
                'is_active'            => post('is_active') === '1' ? 1 : 0,
            ];

            if ($storeId === 0) {
                $code = strtoupper(trim((string) post('code')));
                if (!preg_match('/^[A-Z0-9]{2,12}$/', $code)) {
                    throw new RuntimeException('The store code must be 2-12 letters or digits. '
                        . 'It appears in every receipt and document number, so it cannot be '
                        . 'changed afterwards.');
                }
                if (qv('SELECT id FROM stores WHERE code = ?', [$code])) {
                    throw new RuntimeException('That store code is already in use.');
                }
                $data['code'] = $code;
                $newId = db_insert('stores', $data);
                audit_log('store.create', 'store', $newId,
                    'Store created: ' . $name . ' (' . $code . ')', null, $data, 'critical');
                flash_ok('Store created. Add at least one till before anyone can trade there.');
            } else {
                $before = q1('SELECT * FROM stores WHERE id = ?', [$storeId]);
                if (!$before) {
                    throw new RuntimeException('That store no longer exists.');
                }
                // Deactivating a store with stock on the shelf would hide that
                // stock from every report while leaving it on the books.
                if ($data['is_active'] === 0 && (int) $before['is_active'] === 1) {
                    $openTills = (int) qv("SELECT COUNT(*) FROM till_sessions
                                            WHERE store_id = ? AND status = 'open'", [$storeId], 0);
                    if ($openTills > 0) {
                        throw new RuntimeException("$openTills till(s) are open at this store. "
                            . 'Close them first.');
                    }
                    $stockValue = (float) qv(
                        'SELECT COALESCE(SUM(qty_on_hand * avg_cost), 0) FROM stock_levels
                          WHERE store_id = ? AND qty_on_hand > 0', [$storeId], 0.0);
                    if ($stockValue > 0) {
                        throw new RuntimeException('This store still holds '
                            . money($stockValue) . ' of stock. Transfer it out or write it off '
                            . 'first - deactivating would hide it from every report while it '
                            . 'stays on the books.');
                    }
                }
                db_update('stores', $data, 'id = :id', ['id' => $storeId]);
                audit_log('store.update', 'store', $storeId,
                    'Store updated: ' . $name, $before, $data,
                    $data['is_active'] !== (int) $before['is_active'] ? 'critical' : 'notice');
                flash_ok('Store updated.');
            }
            redirect('admin/stores');
        }

        /* ---- Terminals --------------------------------------------- */
        if ($action === 'save_terminal') {
            $terminalId = post_int('terminal_id');
            $name       = trim((string) post('terminal_name'));
            $storeId    = post_int('terminal_store_id');
            if ($name === '' || $storeId === 0) {
                throw new RuntimeException('A till needs a name and a store.');
            }
            $data = [
                'name'      => $name,
                'store_id'  => $storeId,
                'is_active' => post('terminal_active') === '1' ? 1 : 0,
            ];

            if ($terminalId === 0) {
                $code = strtoupper(trim((string) post('terminal_code')));
                if (!preg_match('/^[A-Z0-9\-]{2,20}$/', $code)) {
                    throw new RuntimeException('The till code must be 2-20 letters, digits or hyphens.');
                }
                if (qv('SELECT id FROM terminals WHERE code = ?', [$code])) {
                    throw new RuntimeException('That till code is already in use.');
                }
                $data['code'] = $code;
                $newId = db_insert('terminals', $data);
                audit_log('terminal.create', 'terminal', $newId,
                    'Till created: ' . $name . ' (' . $code . ') at '
                    . (string) qv('SELECT name FROM stores WHERE id = ?', [$storeId], ''),
                    null, $data, 'notice');
                flash_ok('Till added.');
            } else {
                $before = q1('SELECT * FROM terminals WHERE id = ?', [$terminalId]);
                if (!$before) {
                    throw new RuntimeException('That till no longer exists.');
                }
                if ($data['is_active'] === 0) {
                    $open = q1("SELECT session_no FROM till_sessions
                                 WHERE terminal_id = ? AND status = 'open'", [$terminalId]);
                    if ($open) {
                        throw new RuntimeException('Session ' . $open['session_no']
                            . ' is open on this till. Close and count it first.');
                    }
                }
                db_update('terminals', $data, 'id = :id', ['id' => $terminalId]);
                audit_log('terminal.update', 'terminal', $terminalId,
                    'Till updated: ' . $name, $before, $data, 'notice');
                flash_ok('Till updated.');
            }
            redirect('admin/stores');
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('admin/stores');
    }
}

$editStore    = get_int('edit_store');
$editTerminal = get_int('edit_terminal');
$storeRow     = $editStore > 0 ? q1('SELECT * FROM stores WHERE id = ?', [$editStore]) : null;
$terminalRow  = $editTerminal > 0 ? q1('SELECT * FROM terminals WHERE id = ?', [$editTerminal]) : null;

$stores = qa(
    "SELECT s.*,
            (SELECT COUNT(*) FROM terminals t WHERE t.store_id = s.id AND t.is_active = 1) AS tills,
            (SELECT COUNT(*) FROM users u WHERE u.store_id = s.id AND u.is_active = 1) AS staff,
            (SELECT COUNT(*) FROM till_sessions ts WHERE ts.store_id = s.id AND ts.status = 'open') AS open_tills,
            (SELECT COALESCE(SUM(sl.qty_on_hand * sl.avg_cost), 0) FROM stock_levels sl
              WHERE sl.store_id = s.id AND sl.qty_on_hand > 0) AS stock_value,
            (SELECT COALESCE(SUM(sa.total), 0) FROM sales sa
              WHERE sa.store_id = s.id AND sa.business_date >= (CURDATE() - INTERVAL 30 DAY)
                AND sa.status IN ('completed','refunded','partly_refunded')) AS sales_30d,
            (SELECT MAX(dc.business_date) FROM day_end_closures dc
              WHERE dc.store_id = s.id AND dc.status = 'closed') AS last_closed
       FROM stores s ORDER BY s.is_active DESC, s.is_warehouse, s.name"
);

$terminals = qa(
    "SELECT t.*, s.name AS store_name, s.code AS store_code,
            (SELECT COUNT(*) FROM till_sessions ts WHERE ts.terminal_id = t.id) AS sessions,
            (SELECT ts.session_no FROM till_sessions ts
              WHERE ts.terminal_id = t.id AND ts.status = 'open' LIMIT 1) AS open_session,
            (SELECT u.full_name FROM till_sessions ts JOIN users u ON u.id = ts.user_id
              WHERE ts.terminal_id = t.id AND ts.status = 'open' LIMIT 1) AS open_cashier
       FROM terminals t JOIN stores s ON s.id = t.store_id
      ORDER BY s.name, t.code"
);

$storeOptions = qpairs('SELECT id, name FROM stores WHERE is_active = 1 ORDER BY name');

layout_head([
    'title'    => 'Stores and tills',
    'subtitle' => count($stores) . ' stores, ' . count($terminals) . ' tills',
]);
?>

<?= card_open('Stores') ?>
  <div class="table-wrap">
  <table class="data">
    <thead><tr>
      <th>Code</th><th>Store</th><th class="num">Tills</th><th class="num">Staff</th>
      <th class="num">Stock at cost</th><th class="num">Sales, 30d</th>
      <th class="num">Shrink target</th><th>Last day closed</th><th>State</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($stores as $s):
        $sid       = (int) $s['id'];
        $lastClose = $s['last_closed'];
        $behind    = $lastClose === null
                     || strtotime((string) $lastClose) < strtotime('-3 days'); ?>
      <tr style="<?= (int) $s['is_active'] === 0 ? 'opacity:.55' : '' ?>"
          class="<?= (int) $s['is_active'] === 1 && $behind ? 'row-warn' : '' ?>">
        <td class="mono tiny"><?= e($s['code']) ?></td>
        <td>
          <strong><?= e($s['name']) ?></strong>
          <?php if ((int) $s['is_warehouse'] === 1): ?> <?= badge('Depot', 'info') ?><?php endif; ?>
          <?php if ($s['address']): ?>
            <br><span class="tiny muted"><?= e((string) $s['address']) ?></span>
          <?php endif; ?>
        </td>
        <td class="num">
          <?= (int) $s['tills'] ?>
          <?php if ((int) $s['open_tills'] > 0): ?>
            <br><span class="tiny ok-text"><?= (int) $s['open_tills'] ?> open</span>
          <?php endif; ?>
        </td>
        <td class="num"><?= (int) $s['staff'] ?></td>
        <?= num_cell($s['stock_value']) ?>
        <?= num_cell($s['sales_30d']) ?>
        <td class="num"><?= e(pct_fmt((float) $s['shrinkage_target_pct'])) ?></td>
        <td class="tiny nowrap">
          <?= e($lastClose !== null ? date_fmt((string) $lastClose) : 'never') ?>
          <?php if ((int) $s['is_active'] === 1 && $behind): ?>
            <br><?= badge('Behind', 'warn') ?>
          <?php endif; ?>
        </td>
        <td>
          <?= (int) $s['is_active'] === 1 ? badge('Active', 'ok') : badge('Closed', 'neutral') ?>
        </td>
        <td class="right">
          <a class="btn btn--sm btn--ghost" href="<?= e(url_with(['edit_store' => $sid, 'edit_terminal' => null])) ?>">Edit</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>

<?= card_open($storeRow !== null ? 'Edit ' . $storeRow['name'] : 'Add a store') ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_store">
    <input type="hidden" name="store_id" value="<?= $storeRow !== null ? (int) $storeRow['id'] : 0 ?>">
    <div class="form-grid">
      <?php if ($storeRow === null): ?>
        <label class="field">
          <span class="field__label">Code</span>
          <input type="text" name="code" required maxlength="12" placeholder="IKJ">
          <span class="field__hint">
            Appears in every receipt and document number for this store, so it is permanent.
          </span>
        </label>
      <?php else: ?>
        <label class="field">
          <span class="field__label">Code</span>
          <input type="text" value="<?= e((string) $storeRow['code']) ?>" readonly>
          <span class="field__hint">Fixed: existing document numbers contain it.</span>
        </label>
      <?php endif; ?>
      <label class="field">
        <span class="field__label">Name</span>
        <input type="text" name="name" required maxlength="120"
               value="<?= e((string) ($storeRow['name'] ?? '')) ?>">
      </label>
      <label class="field">
        <span class="field__label">Phone</span>
        <input type="tel" name="phone" maxlength="40"
               value="<?= e((string) ($storeRow['phone'] ?? '')) ?>">
      </label>
      <label class="field">
        <span class="field__label">Tax identification number</span>
        <input type="text" name="tin" maxlength="40"
               value="<?= e((string) ($storeRow['tin'] ?? '')) ?>">
        <span class="field__hint">Printed on receipts.</span>
      </label>
      <label class="field">
        <span class="field__label">Shrinkage target (%)</span>
        <input type="text" name="shrinkage_target_pct" class="num-in"
               value="<?= e(number_format((float) ($storeRow['shrinkage_target_pct'] ?? 2.0), 2, '.', '')) ?>">
        <span class="field__hint">
          What the shrinkage report measures against. Differs by store: a high-footfall branch
          will not match a depot.
        </span>
      </label>
    </div>
    <label class="field">
      <span class="field__label">Address</span>
      <input type="text" name="address" maxlength="255"
             value="<?= e((string) ($storeRow['address'] ?? '')) ?>">
    </label>
    <div class="check">
      <input type="checkbox" name="is_warehouse" value="1" id="s-warehouse"
             <?= (int) ($storeRow['is_warehouse'] ?? 0) === 1 ? 'checked' : '' ?>>
      <label for="s-warehouse">
        <strong>Depot rather than a shop</strong><br>
        <span class="small muted">Holds bulk stock and supplies the branches. Not expected to sell.</span>
      </label>
    </div>
    <div class="check">
      <input type="checkbox" name="is_active" value="1" id="s-active"
             <?= (int) ($storeRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
      <label for="s-active">Active</label>
    </div>
    <div class="btn-row">
      <button class="btn" type="submit"><?= $storeRow !== null ? 'Save store' : 'Add store' ?></button>
      <?php if ($storeRow !== null): ?>
        <a class="btn btn--ghost" href="<?= e(base_url('admin/stores')) ?>">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
<?= card_close() ?>

<?= card_open('Tills') ?>
  <?php if (!$terminals): ?>
    <?= empty_state('No tills set up.', 'Nobody can trade until a store has at least one.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Code</th><th>Name</th><th>Store</th><th class="num">Shifts worked</th>
        <th>In use now</th><th>State</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($terminals as $t): ?>
        <tr style="<?= (int) $t['is_active'] === 0 ? 'opacity:.55' : '' ?>">
          <td class="mono tiny"><?= e($t['code']) ?></td>
          <td><?= e($t['name']) ?></td>
          <td class="tiny"><?= e($t['store_name']) ?></td>
          <td class="num"><?= number_format((int) $t['sessions']) ?></td>
          <td class="tiny">
            <?php if ($t['open_session'] !== null): ?>
              <?= badge('Open', 'ok') ?>
              <br><span class="muted"><?= e((string) $t['open_cashier']) ?></span>
            <?php else: ?>
              <span class="muted">free</span>
            <?php endif; ?>
          </td>
          <td><?= (int) $t['is_active'] === 1 ? badge('Active', 'ok') : badge('Retired', 'neutral') ?></td>
          <td class="right">
            <a class="btn btn--sm btn--ghost"
               href="<?= e(url_with(['edit_terminal' => (int) $t['id'], 'edit_store' => null])) ?>">Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?= card_open($terminalRow !== null ? 'Edit till ' . $terminalRow['code'] : 'Add a till') ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_terminal">
    <input type="hidden" name="terminal_id" value="<?= $terminalRow !== null ? (int) $terminalRow['id'] : 0 ?>">
    <div class="form-grid">
      <?php if ($terminalRow === null): ?>
        <label class="field">
          <span class="field__label">Code</span>
          <input type="text" name="terminal_code" required maxlength="20" placeholder="IKJ-T4">
        </label>
      <?php else: ?>
        <label class="field">
          <span class="field__label">Code</span>
          <input type="text" value="<?= e((string) $terminalRow['code']) ?>" readonly>
        </label>
      <?php endif; ?>
      <label class="field">
        <span class="field__label">Name</span>
        <input type="text" name="terminal_name" required maxlength="80"
               value="<?= e((string) ($terminalRow['name'] ?? '')) ?>" placeholder="Ikeja Till 4">
      </label>
      <label class="field">
        <span class="field__label">Store</span>
        <select name="terminal_store_id" required>
          <?= select_options($storeOptions, $terminalRow['store_id'] ?? '', true, '-- choose --') ?>
        </select>
      </label>
    </div>
    <div class="check">
      <input type="checkbox" name="terminal_active" value="1" id="t-active"
             <?= (int) ($terminalRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
      <label for="t-active">Active &mdash; a cashier can open a session on it</label>
    </div>
    <div class="btn-row">
      <button class="btn" type="submit"><?= $terminalRow !== null ? 'Save till' : 'Add till' ?></button>
      <?php if ($terminalRow !== null): ?>
        <a class="btn btn--ghost" href="<?= e(base_url('admin/stores')) ?>">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
<?= card_close() ?>

<?= card_open('Why nothing here can be deleted') ?>
  <p class="small">
    Every stock movement, sale, till session and audit entry points at a store, and every receipt
    names the till it was rung up on. Deleting either row would leave years of records attributed
    to nothing, which is a worse outcome than keeping an inactive row.
  </p>
  <p class="small muted">
    Deactivating is the real control: a closed store accepts no new trading and a retired till
    cannot be opened, while the history stays readable. A store holding stock or with an open till
    cannot be deactivated at all &mdash; that would hide live figures rather than retire dead ones.
  </p>
<?= card_close() ?>

<?php layout_foot(); ?>
