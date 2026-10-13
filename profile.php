<?php
/**
 * My account: change password, set or change a supervisor PIN.
 *
 * The PIN section only appears for roles that hold an override permission. A
 * cashier with an override PIN would defeat the point of having one, so the
 * field is not merely hidden - there is no path here that would set it.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$u = current_user();
$mayHavePin = can_any(['pos.void', 'pos.discount', 'pos.price_override', 'pos.cash_movement', 'refund.approve']);

if (is_post()) {
    csrf_require();
    $action = (string) post('action');

    try {
        if ($action === 'password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $new     = (string) ($_POST['new_password'] ?? '');
            $again   = (string) ($_POST['confirm_password'] ?? '');

            if (!password_verify($current, (string) $u['password_hash'])) {
                throw new RuntimeException('Your current password was not correct.');
            }
            if ($new !== $again) {
                throw new RuntimeException('The two new passwords do not match.');
            }
            if ($problem = password_problem($new)) {
                throw new RuntimeException($problem);
            }
            if (password_verify($new, (string) $u['password_hash'])) {
                throw new RuntimeException('The new password must be different from the current one.');
            }

            db_update('users', [
                'password_hash'        => hash_password($new),
                'must_change_password' => 0,
            ], 'id = :id', ['id' => (int) $u['id']]);
            unset($_SESSION['must_change_password']);

            // The hash is never logged, before or after - the trail records that
            // the password changed, not what it changed to.
            audit_log('user.password', 'user', (int) $u['id'], 'Changed own password', null, null, 'notice');
            flash_ok('Your password has been changed.');
            redirect('profile');
        }

        if ($action === 'pin') {
            if (!$mayHavePin) {
                throw new RuntimeException('Your role does not authorise till overrides, so it does not use a PIN.');
            }
            $password = (string) ($_POST['pin_password'] ?? '');
            $pin      = (string) ($_POST['pin'] ?? '');
            $again    = (string) ($_POST['pin_confirm'] ?? '');

            // The PIN is a credential that authorises spending, so changing it
            // needs the password - otherwise anyone who finds a till unattended
            // could set their own override PIN on that supervisor's account.
            if (!password_verify($password, (string) $u['password_hash'])) {
                throw new RuntimeException('Enter your account password to change your PIN.');
            }
            if ($pin !== $again) {
                throw new RuntimeException('The two PINs do not match.');
            }
            if ($problem = pin_problem($pin)) {
                throw new RuntimeException($problem);
            }

            db_update('users', [
                'pin_hash'            => hash_password($pin),
                'pin_failed_attempts' => 0,
                'pin_locked_until'    => null,
            ], 'id = :id', ['id' => (int) $u['id']]);

            audit_log('user.pin', 'user', (int) $u['id'],
                'Set or changed own supervisor PIN', null, null, 'notice');
            flash_ok('Your supervisor PIN has been set.');
            redirect('profile');
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('profile');
    }
}

// My own recent activity, read from the trail. Useful to the user and a quiet
// deterrent: everyone can see that their actions are recorded.
$myActivity = qa(
    'SELECT action, entity, entity_id, summary, severity, created_at
       FROM audit_log WHERE user_id = ? ORDER BY id DESC LIMIT 25',
    [(int) $u['id']]
);
$myShifts = qa(
    'SELECT ts.*, t.code AS terminal_code FROM till_sessions ts
       JOIN terminals t ON t.id = ts.terminal_id
      WHERE ts.user_id = ? ORDER BY ts.opened_at DESC LIMIT 10',
    [(int) $u['id']]
);

layout_head(['title' => 'My account', 'subtitle' => $u['full_name'] . ' - ' . $u['role_name']]);

if (!empty($_SESSION['must_change_password'])): ?>
  <div class="alert alert--warning">
    Your password was set by an administrator. Choose your own before continuing.
  </div>
<?php endif; ?>

<div class="grid-2">
  <?= card_open('Change password') ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <label class="field">
        <span class="field__label">Current password</span>
        <input type="password" name="current_password" required autocomplete="current-password">
      </label>
      <label class="field">
        <span class="field__label">New password</span>
        <input type="password" name="new_password" required autocomplete="new-password"
               minlength="<?= (int) setting_num('password_min_length', (float) PASSWORD_MIN_LENGTH) ?>">
        <span class="field__hint">
          At least <?= (int) setting_num('password_min_length', (float) PASSWORD_MIN_LENGTH) ?> characters,
          including a letter and a digit.
        </span>
      </label>
      <label class="field">
        <span class="field__label">Confirm new password</span>
        <input type="password" name="confirm_password" required autocomplete="new-password">
      </label>
      <button class="btn" type="submit">Change password</button>
    </form>
  <?= card_close() ?>

  <?php if ($mayHavePin): ?>
    <?= card_open('Supervisor PIN', $u['pin_hash'] !== null ? badge('Set', 'ok') : badge('Not set', 'warn')) ?>
      <p class="small muted">
        Your PIN authorises a single override at a till &mdash; a price change, a void, a discount
        above a cashier's limit, a pay-out. It never signs you in, and it can only do what your
        role already permits. Whoever's PIN authorised an override is recorded on the line.
      </p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="pin">
        <label class="field">
          <span class="field__label">Your account password</span>
          <input type="password" name="pin_password" required autocomplete="current-password">
        </label>
        <div class="form-grid">
          <label class="field">
            <span class="field__label">New PIN</span>
            <input type="password" name="pin" required inputmode="numeric"
                   pattern="\d{<?= PIN_LENGTH ?>}" maxlength="<?= PIN_LENGTH ?>">
            <span class="field__hint"><?= PIN_LENGTH ?> digits. Not a repeat or a sequence.</span>
          </label>
          <label class="field">
            <span class="field__label">Confirm PIN</span>
            <input type="password" name="pin_confirm" required inputmode="numeric"
                   pattern="\d{<?= PIN_LENGTH ?>}" maxlength="<?= PIN_LENGTH ?>">
          </label>
        </div>
        <button class="btn" type="submit">
          <?= $u['pin_hash'] !== null ? 'Change PIN' : 'Set PIN' ?>
        </button>
      </form>
      <?php if ($u['pin_locked_until'] !== null && strtotime((string) $u['pin_locked_until']) > time()): ?>
        <div class="alert alert--warning mt1">
          Your PIN is locked until <?= e(date_fmt((string) $u['pin_locked_until'], true)) ?> after
          repeated wrong attempts at a till. Setting a new PIN clears the lock.
        </div>
      <?php endif; ?>
    <?= card_close() ?>
  <?php else: ?>
    <?= card_open('Your account') ?>
      <dl class="kv">
        <dt>Staff number</dt><dd class="mono"><?= e($u['staff_no']) ?></dd>
        <dt>Email</dt><dd><?= e($u['email']) ?></dd>
        <dt>Role</dt><dd><?= e($u['role_name']) ?></dd>
        <dt>Store</dt><dd><?= e($u['store_name'] ?? 'All stores') ?></dd>
        <dt>Last signed in</dt><dd><?= e(date_fmt((string) $u['last_login_at'], true)) ?></dd>
        <dt>Permissions</dt><dd><?= count(my_permissions()) ?> granted</dd>
      </dl>
    <?= card_close() ?>
  <?php endif; ?>
</div>

<?php if ($myShifts): ?>
<?= card_open('My recent shifts') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Session</th><th>Till</th><th>Opened</th><th>Closed</th>
      <th class="num">Expected</th><th class="num">Declared</th><th class="num">Variance</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($myShifts as $s):
        $var = $s['variance'] !== null ? (float) $s['variance'] : null; ?>
      <tr>
        <td class="mono"><a href="<?= e(base_url('pos/session', ['id' => (int) $s['id']])) ?>"><?= e($s['session_no']) ?></a></td>
        <td class="mono tiny"><?= e($s['terminal_code']) ?></td>
        <td class="nowrap tiny"><?= e(date_fmt((string) $s['opened_at'], true)) ?></td>
        <td class="nowrap tiny"><?= e(date_fmt($s['closed_at'], true)) ?></td>
        <?= num_cell($s['expected_cash'] ?? 0) ?>
        <?= num_cell($s['declared_cash'] ?? 0) ?>
        <?php if ($var === null): ?><td class="num">-</td><?php else: ?><?= num_cell($var, true, true) ?><?php endif; ?>
        <td><?= status_badge((string) $s['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?= card_open('My recent activity',
      '<span class="tiny muted">Read from the audit trail</span>') ?>
  <?php if (!$myActivity): ?>
    <?= empty_state('Nothing recorded yet.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr><th>When</th><th>Action</th><th>What happened</th></tr></thead>
      <tbody>
      <?php foreach ($myActivity as $a): ?>
        <tr>
          <td class="nowrap tiny"><?= e(date_fmt((string) $a['created_at'], true)) ?></td>
          <td class="mono tiny"><?= e($a['action']) ?></td>
          <td><?= e($a['summary']) ?>
            <?php if ($a['severity'] !== 'info'): ?> <?= severity_badge((string) $a['severity']) ?><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?php layout_foot(); ?>
