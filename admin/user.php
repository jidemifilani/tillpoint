<?php
/**
 * One staff account: create, edit, reset the password, assign extra stores.
 *
 * An administrator can set a password but never read one - there is no field
 * here that shows an existing credential, because none is stored in a form a
 * person could read. A reset sets a temporary password and forces a change on
 * next sign-in, so the administrator who typed it cannot keep using it.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('admin.users');

$id    = get_int('id');
$isNew = $id === 0;

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');

        /* ---- Create or update -------------------------------------- */
        if ($action === 'save') {
            $name    = trim((string) post('full_name'));
            $email   = strtolower(trim((string) post('email')));
            $staffNo = strtoupper(trim((string) post('staff_no')));
            $roleId  = post_int('role_id');
            $storeId = post_int('store_id') ?: null;

            if ($name === '' || $email === '' || $staffNo === '') {
                throw new RuntimeException('Name, email and staff number are all required.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('That email address is not valid.');
            }
            if ($roleId === 0) {
                throw new RuntimeException('Choose a role.');
            }

            $emailClash = (int) qv('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?',
                [$email, $id], 0);
            if ($emailClash > 0) {
                throw new RuntimeException('Another account already uses that email address.');
            }
            $staffClash = (int) qv('SELECT COUNT(*) FROM users WHERE staff_no = ? AND id <> ?',
                [$staffNo, $id], 0);
            if ($staffClash > 0) {
                throw new RuntimeException('Another account already uses that staff number.');
            }

            $data = [
                'full_name' => $name,
                'email'     => $email,
                'staff_no'  => $staffNo,
                'phone'     => (string) post('phone') !== '' ? (string) post('phone') : null,
                'role_id'   => $roleId,
                'store_id'  => $storeId,
                'is_active' => post('is_active') === '1' ? 1 : 0,
            ];

            if ($isNew) {
                $password = (string) ($_POST['password'] ?? '');
                if ($problem = password_problem($password)) {
                    throw new RuntimeException($problem);
                }
                $data['password_hash']        = hash_password($password);
                // The administrator typed it, so it is not a secret between the
                // user and the system until the user changes it.
                $data['must_change_password'] = 1;

                $newId = db_insert('users', $data);
                audit_log('user.create', 'user', $newId,
                    sprintf('Account created for %s (%s) as %s%s',
                        $name, $staffNo,
                        (string) qv('SELECT name FROM roles WHERE id = ?', [$roleId], ''),
                        $storeId !== null
                            ? ' at ' . (string) qv('SELECT name FROM stores WHERE id = ?', [$storeId], '')
                            : ' with access to all stores'),
                    null, array_diff_key($data, ['password_hash' => null]), 'critical');

                flash_ok($name . ' can now sign in and will be asked to choose a new password.');
                redirect('admin/user', ['id' => $newId]);
            }

            $before = q1('SELECT * FROM users WHERE id = ?', [$id]);
            if (!$before) {
                throw new RuntimeException('That account no longer exists.');
            }

            // Locking yourself out of administration is the one change that
            // cannot be undone from inside the application.
            if ($id === (int) $_SESSION['uid']
                && $roleId !== (int) $before['role_id']
                && (int) qv('SELECT COUNT(*) FROM role_permissions
                              WHERE role_id = ? AND permission_code = ?',
                            [$roleId, 'admin.users'], 0) === 0) {
                throw new RuntimeException('That role cannot manage users, and it is your own '
                    . 'account. You would lock yourself out of administration. Ask another '
                    . 'administrator to make this change.');
            }
            if ($id === (int) $_SESSION['uid'] && $data['is_active'] === 0) {
                throw new RuntimeException('You cannot deactivate your own account.');
            }

            $roleChanged = $roleId !== (int) $before['role_id'];
            db_update('users', $data, 'id = :id', ['id' => $id]);

            audit_log('user.update', 'user', $id,
                sprintf('Account updated: %s%s', $name,
                    $roleChanged
                        ? ' - role changed from ' . (string) qv('SELECT name FROM roles WHERE id = ?',
                              [(int) $before['role_id']], '')
                          . ' to ' . (string) qv('SELECT name FROM roles WHERE id = ?', [$roleId], '')
                        : ''),
                array_diff_key($before, ['password_hash' => null, 'pin_hash' => null]),
                $data,
                // A role change is the one edit that alters what someone can do.
                $roleChanged ? 'critical' : 'notice');

            flash_ok('Account updated.');
            redirect('admin/user', ['id' => $id]);
        }

        /* ---- Reset the password ------------------------------------ */
        if ($action === 'reset_password') {
            $u = q1('SELECT * FROM users WHERE id = ?', [$id]);
            if (!$u) {
                throw new RuntimeException('That account no longer exists.');
            }
            $password = (string) ($_POST['new_password'] ?? '');
            if ($problem = password_problem($password)) {
                throw new RuntimeException($problem);
            }
            db_update('users', [
                'password_hash'        => hash_password($password),
                'must_change_password' => 1,
                'failed_attempts'      => 0,
                'locked_until'         => null,
            ], 'id = :id', ['id' => $id]);

            // The password itself is never logged, before or after.
            audit_log('user.password_reset', 'user', $id,
                'Password reset by an administrator for ' . $u['full_name']
                . '; they must choose a new one on next sign-in',
                null, null, 'critical');

            flash_ok('Password reset. Give it to ' . $u['full_name']
                . ' and they will be asked to change it immediately.');
            redirect('admin/user', ['id' => $id]);
        }

        /* ---- Clear the PIN ----------------------------------------- */
        if ($action === 'clear_pin') {
            $u = q1('SELECT * FROM users WHERE id = ?', [$id]);
            db_update('users', [
                'pin_hash'            => null,
                'pin_failed_attempts' => 0,
                'pin_locked_until'    => null,
            ], 'id = :id', ['id' => $id]);
            audit_log('user.pin_clear', 'user', $id,
                'Supervisor PIN removed for ' . ($u['full_name'] ?? ''), null, null, 'critical');
            flash_ok('PIN removed. They can set a new one from their own account page.');
            redirect('admin/user', ['id' => $id]);
        }

        /* ---- Extra stores ------------------------------------------ */
        if ($action === 'stores') {
            $chosen = array_map('intval', (array) ($_POST['extra_stores'] ?? []));
            db_tx(function () use ($id, $chosen): void {
                q('DELETE FROM user_stores WHERE user_id = ?', [$id]);
                foreach ($chosen as $sid) {
                    q('INSERT IGNORE INTO user_stores (user_id, store_id) VALUES (?, ?)', [$id, $sid]);
                }
            });
            audit_log('user.stores', 'user', $id,
                'Store access updated: ' . (count($chosen) ?: 'none') . ' additional store(s)',
                null, ['stores' => $chosen], 'notice');
            flash_ok('Store access updated.');
            redirect('admin/user', ['id' => $id]);
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect($isNew ? 'admin/user' : 'admin/user?id=' . $id);
    }
}

$u = $isNew
    ? ['id' => 0, 'full_name' => '', 'email' => '', 'staff_no' => '', 'phone' => '',
       'role_id' => 0, 'store_id' => null, 'is_active' => 1, 'pin_hash' => null,
       'must_change_password' => 0, 'last_login_at' => null, 'created_at' => null,
       'locked_until' => null, 'pin_locked_until' => null, 'failed_attempts' => 0]
    : q1('SELECT * FROM users WHERE id = ?', [$id]);

if (!$u) {
    http_response_code(404);
    require APP_ROOT . '/404.php';
    exit;
}

$roles  = qpairs('SELECT id, CONCAT(name, " - ", description) FROM roles ORDER BY name');
$stores = qa('SELECT * FROM stores WHERE is_active = 1 ORDER BY name');
$extraStores = $isNew ? [] : array_map('intval',
    qcol('SELECT store_id FROM user_stores WHERE user_id = ?', [$id]));

$rolePerms = $isNew || (int) $u['role_id'] === 0 ? [] : qa(
    'SELECT p.code, p.label, p.module FROM role_permissions rp
       JOIN permissions p ON p.code = rp.permission_code
      WHERE rp.role_id = ? ORDER BY p.module, p.label',
    [(int) $u['role_id']]
);
$permsByModule = [];
foreach ($rolePerms as $p) {
    $permsByModule[(string) $p['module']][] = $p;
}

$activity = $isNew ? [] : qa(
    'SELECT action, entity, entity_id, summary, severity, created_at
       FROM audit_log WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$id]
);
$aboutThem = $isNew ? [] : qa(
    'SELECT al.action, al.summary, al.severity, al.created_at, al.user_label
       FROM audit_log al WHERE al.entity = \'user\' AND al.entity_id = ?
      ORDER BY al.id DESC LIMIT 20', [$id]
);
$stats = $isNew ? null : q1(
    "SELECT (SELECT COUNT(*) FROM sales WHERE cashier_id = ?) AS sales,
            (SELECT COUNT(*) FROM till_sessions WHERE user_id = ?) AS shifts,
            (SELECT COALESCE(SUM(ABS(variance)),0) FROM till_sessions
              WHERE user_id = ? AND variance IS NOT NULL) AS abs_variance,
            (SELECT COUNT(*) FROM audit_exceptions
              WHERE subject_user_id = ? AND status IN ('open','reviewing')) AS open_exceptions",
    [$id, $id, $id, $id]
);

$mayHavePin = !$isNew && (int) qv(
    'SELECT COUNT(*) FROM role_permissions
      WHERE role_id = ? AND permission_code IN
            (\'pos.void\',\'pos.discount\',\'pos.price_override\',\'pos.cash_movement\',\'refund.approve\')',
    [(int) $u['role_id']], 0
) > 0;

layout_head([
    'title'    => $isNew ? 'New staff account' : (string) $u['full_name'],
    'subtitle' => $isNew ? 'Create a sign-in' : $u['staff_no'] . ' - ' . $u['email'],
    'actions'  => '<a class="btn btn--ghost" href="' . e(base_url('admin/users')) . '">All accounts</a>',
]);
?>

<?php if (!$isNew): ?>
  <div class="tiles">
    <?= tile('Transactions', number_format((int) ($stats['sales'] ?? 0)), 'rung up by this account') ?>
    <?= tile('Shifts', number_format((int) ($stats['shifts'] ?? 0)), 'till sessions worked') ?>
    <?= tile('Till discrepancy', money((float) ($stats['abs_variance'] ?? 0)),
          'lifetime, ignoring direction',
          (float) ($stats['abs_variance'] ?? 0) > 0 ? 'warn' : 'ok') ?>
    <?= tile('Open findings', number_format((int) ($stats['open_exceptions'] ?? 0)),
          'audit exceptions about them',
          (int) ($stats['open_exceptions'] ?? 0) > 0 ? 'bad' : 'ok') ?>
    <?= tile('Last signed in',
          $u['last_login_at'] !== null ? ago((string) $u['last_login_at']) : 'never',
          'account created ' . date_fmt((string) $u['created_at']), 'mute') ?>
    <?= tile('Permissions', number_format(count($rolePerms)), 'granted by their role', 'info') ?>
  </div>

  <?php if ($u['locked_until'] !== null && strtotime((string) $u['locked_until']) > time()): ?>
    <div class="alert alert--warning">
      Signed out and locked until <?= e(date_fmt((string) $u['locked_until'], true)) ?> after
      <?= (int) $u['failed_attempts'] ?> failed attempt(s).
      Clear it from the <a href="<?= e(base_url('admin/users', ['view' => 'locked'])) ?>">accounts list</a>.
    </div>
  <?php endif; ?>
<?php endif; ?>

<?= card_open($isNew ? 'Account details' : 'Edit account') ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="form-grid">
      <label class="field">
        <span class="field__label">Full name</span>
        <input type="text" name="full_name" required maxlength="120"
               value="<?= e((string) $u['full_name']) ?>" <?= $isNew ? 'data-autofocus' : '' ?>>
      </label>
      <label class="field">
        <span class="field__label">Staff number</span>
        <input type="text" name="staff_no" required maxlength="20"
               value="<?= e((string) $u['staff_no']) ?>">
        <span class="field__hint">What a supervisor types when authorising an override at a till.</span>
      </label>
      <label class="field">
        <span class="field__label">Email address</span>
        <input type="email" name="email" required maxlength="160"
               value="<?= e((string) $u['email']) ?>">
        <span class="field__hint">Used to sign in.</span>
      </label>
      <label class="field">
        <span class="field__label">Phone</span>
        <input type="tel" name="phone" maxlength="40" value="<?= e((string) ($u['phone'] ?? '')) ?>">
      </label>
      <label class="field">
        <span class="field__label">Role</span>
        <select name="role_id" required>
          <?= select_options($roles, $u['role_id'], true, '-- choose a role --') ?>
        </select>
        <span class="field__hint">The role decides everything this account can do.</span>
      </label>
      <label class="field">
        <span class="field__label">Home store</span>
        <select name="store_id">
          <option value="">All stores (head office)</option>
          <?= select_options(array_column($stores, 'name', 'id'), $u['store_id']) ?>
        </select>
        <span class="field__hint">
          Store staff are pinned to theirs. Leave blank only for head office and auditors.
        </span>
      </label>
    </div>

    <?php if ($isNew): ?>
      <label class="field">
        <span class="field__label">Temporary password</span>
        <input type="password" name="password" required
               minlength="<?= (int) setting_num('password_min_length', (float) PASSWORD_MIN_LENGTH) ?>">
        <span class="field__hint">
          They will be made to change it the first time they sign in, so this one only has to
          survive being told to them once.
        </span>
      </label>
    <?php endif; ?>

    <div class="check">
      <input type="checkbox" name="is_active" value="1" id="u-active"
             <?= (int) $u['is_active'] === 1 ? 'checked' : '' ?>>
      <label for="u-active">Active &mdash; can sign in</label>
    </div>

    <div class="btn-row">
      <button class="btn btn--lg" type="submit"><?= $isNew ? 'Create account' : 'Save changes' ?></button>
      <a class="btn btn--ghost" href="<?= e(base_url('admin/users')) ?>">Cancel</a>
    </div>
  </form>
<?= card_close() ?>

<?php if (!$isNew): ?>
<div class="grid-2">
  <?= card_open('Reset the password') ?>
    <p class="small muted">
      Sets a temporary password and forces a change on the next sign-in, so the one you type here
      stops working as soon as they use it. You cannot read their current password &mdash; nothing
      stores it in a readable form.
    </p>
    <form method="post" data-confirm="Reset this password?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_password">
      <label class="field">
        <span class="field__label">Temporary password</span>
        <input type="password" name="new_password" required
               minlength="<?= (int) setting_num('password_min_length', (float) PASSWORD_MIN_LENGTH) ?>">
      </label>
      <button class="btn btn--warn" type="submit">Reset password</button>
    </form>
    <?php if ((int) $u['must_change_password'] === 1): ?>
      <p class="small mt1"><?= badge('Must change at next sign-in', 'warn') ?></p>
    <?php endif; ?>
  <?= card_close() ?>

  <?= card_open('Supervisor PIN') ?>
    <?php if (!$mayHavePin): ?>
      <p class="small muted">
        This role holds no override permission, so it does not use a PIN. A cashier with an
        override PIN would defeat the point of having one.
      </p>
    <?php else: ?>
      <p class="small">
        Status: <?= $u['pin_hash'] !== null ? badge('Set', 'ok') : badge('Not set', 'warn') ?>
      </p>
      <p class="small muted">
        A PIN can only be set by its owner, from their own account page, and only with their
        account password. An administrator can remove one &mdash; useful when it has been shared
        or forgotten &mdash; but cannot set or read one, because a PIN an administrator chose
        would be a credential two people know.
      </p>
      <?php if ($u['pin_hash'] !== null): ?>
        <form method="post"
              data-confirm="Remove this PIN? They will not be able to authorise overrides until they set a new one.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="clear_pin">
          <button class="btn btn--danger" type="submit">Remove PIN</button>
        </form>
      <?php endif; ?>
      <?php if ($u['pin_locked_until'] !== null && strtotime((string) $u['pin_locked_until']) > time()): ?>
        <div class="alert alert--warning mt1">
          PIN locked until <?= e(date_fmt((string) $u['pin_locked_until'], true)) ?> after repeated
          wrong attempts at a till. That is worth asking about before clearing it.
        </div>
      <?php endif; ?>
    <?php endif; ?>
  <?= card_close() ?>
</div>

<?php if ($u['store_id'] !== null): ?>
<?= card_open('Additional stores') ?>
  <p class="small muted">
    Their home store is <strong><?= e((string) qv('SELECT name FROM stores WHERE id = ?',
        [(int) $u['store_id']], '')) ?></strong>. Tick any others they cover; they will be able
    to switch between them from the top bar.
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="stores">
    <div style="display:flex;gap:1.2rem;flex-wrap:wrap">
      <?php foreach ($stores as $s):
          if ((int) $s['id'] === (int) $u['store_id']) {
              continue;
          } ?>
        <label class="check" style="margin:0">
          <input type="checkbox" name="extra_stores[]" value="<?= (int) $s['id'] ?>"
                 <?= in_array((int) $s['id'], $extraStores, true) ? 'checked' : '' ?>>
          <?= e($s['name']) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <button class="btn mt1" type="submit">Save store access</button>
  </form>
<?= card_close() ?>
<?php endif; ?>

<?= card_open('What this role can do (' . count($rolePerms) . ' permissions)',
      can('admin.roles')
        ? '<a class="btn btn--sm btn--ghost" href="' . e(base_url('admin/roles')) . '">Edit roles</a>' : '') ?>
  <?php if (!$permsByModule): ?>
    <?= empty_state('This role holds no permissions.',
          'The account can sign in but will not be able to open anything.') ?>
  <?php else: ?>
    <div class="grid-3">
      <?php foreach ($permsByModule as $module => $perms): ?>
        <div>
          <h3><?= e(ucfirst($module)) ?></h3>
          <ul class="small" style="margin:.3rem 0 0 1rem;padding:0">
            <?php foreach ($perms as $p): ?>
              <li><?= e($p['label']) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?php if ($aboutThem): ?>
<?= card_open('Changes to this account') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr><th>When</th><th>By</th><th>What</th><th>Severity</th></tr></thead>
    <tbody>
    <?php foreach ($aboutThem as $a): ?>
      <tr class="<?= $a['severity'] === 'critical' ? 'row-warn' : '' ?>">
        <td class="nowrap tiny"><?= e(date_fmt((string) $a['created_at'], true)) ?></td>
        <td class="tiny"><?= e($a['user_label'] ?? 'system') ?></td>
        <td class="small"><?= e($a['summary']) ?></td>
        <td><?= severity_badge((string) $a['severity']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?php if ($activity && can('audit.trail')): ?>
<?= card_open('What they have been doing') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr><th>When</th><th>Action</th><th>What happened</th><th>Severity</th></tr></thead>
    <tbody>
    <?php foreach ($activity as $a): ?>
      <tr>
        <td class="nowrap tiny"><?= e(date_fmt((string) $a['created_at'], true)) ?></td>
        <td class="mono tiny"><?= e($a['action']) ?></td>
        <td class="small"><?= e($a['summary']) ?></td>
        <td><?= severity_badge((string) $a['severity']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="small mt1">
    <a href="<?= e(base_url('audit/trail', ['user_id' => $id])) ?>">Full trail for this account &rarr;</a>
  </p>
<?= card_close() ?>
<?php endif; ?>
<?php endif; ?>

<?php layout_foot(); ?>
