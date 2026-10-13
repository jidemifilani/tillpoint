<?php
/**
 * Staff accounts.
 *
 * Creating a user is creating the ability to move money or stock, so the list
 * leads with what each account can actually do rather than with contact
 * details: the role, the store, and whether the account is still being used.
 *
 * Dormant accounts are called out because they are the ones nobody notices.
 * A cashier who left three months ago with an active login is a bigger exposure
 * than any threshold on this system.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('admin.users');

$dormantDays = 60;

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');
        $userId = post_int('user_id');

        if ($action === 'toggle_active') {
            $u = q1('SELECT * FROM users WHERE id = ?', [$userId]);
            if (!$u) {
                throw new RuntimeException('That account no longer exists.');
            }
            if ($userId === (int) $_SESSION['uid']) {
                throw new RuntimeException('You cannot deactivate your own account.');
            }
            $now = (int) $u['is_active'] === 1 ? 0 : 1;

            // Deactivating someone mid-shift would orphan an open till with cash
            // in it that nobody can now close.
            if ($now === 0) {
                $open = q1("SELECT session_no FROM till_sessions
                             WHERE user_id = ? AND status = 'open'", [$userId]);
                if ($open) {
                    throw new RuntimeException($u['full_name'] . ' has till session '
                        . $open['session_no'] . ' open. Close and count that till before '
                        . 'deactivating the account, or the cash on it becomes unattributable.');
                }
            }

            db_update('users', ['is_active' => $now], 'id = :id', ['id' => $userId]);
            audit_log($now === 1 ? 'user.activate' : 'user.deactivate', 'user', $userId,
                sprintf('%s %s', $u['full_name'], $now === 1 ? 'reactivated' : 'deactivated'),
                ['is_active' => (int) $u['is_active']], ['is_active' => $now], 'critical');
            flash_ok($u['full_name'] . ($now === 1 ? ' reactivated.' : ' deactivated.'));
        }

        if ($action === 'unlock') {
            $u = q1('SELECT * FROM users WHERE id = ?', [$userId]);
            if (!$u) {
                throw new RuntimeException('That account no longer exists.');
            }
            db_update('users', [
                'locked_until'        => null,
                'failed_attempts'     => 0,
                'pin_locked_until'    => null,
                'pin_failed_attempts' => 0,
            ], 'id = :id', ['id' => $userId]);
            audit_log('user.unlock', 'user', $userId,
                'Sign-in and PIN locks cleared for ' . $u['full_name'], null, null, 'notice');
            flash_ok('Locks cleared for ' . $u['full_name'] . '.');
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect(url_with([]));
}

$search   = (string) get('q');
$roleId   = get_int('role_id');
$view     = one_of((string) get('view'), ['active', 'all', 'inactive', 'locked', 'dormant'], 'active');

$where  = ['1 = 1'];
$params = [];

if ($search !== '') {
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.staff_no LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($roleId > 0) {
    $where[]  = 'u.role_id = ?';
    $params[] = $roleId;
}
switch ($view) {
    case 'active':   $where[] = 'u.is_active = 1'; break;
    case 'inactive': $where[] = 'u.is_active = 0'; break;
    case 'locked':
        $where[] = '(u.locked_until IS NOT NULL AND u.locked_until > NOW())
                    OR (u.pin_locked_until IS NOT NULL AND u.pin_locked_until > NOW())';
        break;
    case 'dormant':
        $where[]  = 'u.is_active = 1 AND (u.last_login_at IS NULL
                     OR u.last_login_at < (NOW() - INTERVAL ? DAY))';
        $params[] = $dormantDays;
        break;
}
$whereSql = implode(' AND ', $where);

$rows = qa(
    "SELECT u.*, r.name AS role_name, r.code AS role_code, s.name AS store_name,
            (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = u.role_id) AS permission_count,
            (SELECT COUNT(*) FROM till_sessions ts WHERE ts.user_id = u.id AND ts.status = 'open') AS open_tills,
            (SELECT COUNT(*) FROM user_stores us WHERE us.user_id = u.id) AS extra_stores,
            (SELECT COUNT(*) FROM audit_exceptions ae
              WHERE ae.subject_user_id = u.id AND ae.status IN ('open','reviewing')) AS open_exceptions
       FROM users u
       JOIN roles r ON r.id = u.role_id
       LEFT JOIN stores s ON s.id = u.store_id
      WHERE $whereSql
      ORDER BY u.is_active DESC, r.name, u.full_name",
    $params
);

$counts = q1(
    "SELECT COUNT(*) AS total,
            SUM(is_active = 1) AS active,
            SUM(is_active = 0) AS inactive,
            SUM(is_active = 1 AND (last_login_at IS NULL OR last_login_at < (NOW() - INTERVAL ? DAY))) AS dormant,
            SUM((locked_until IS NOT NULL AND locked_until > NOW())
                OR (pin_locked_until IS NOT NULL AND pin_locked_until > NOW())) AS locked
       FROM users", [$dormantDays]
);

$roles = qpairs('SELECT id, name FROM roles ORDER BY name');

layout_head([
    'title'    => 'Staff accounts',
    'subtitle' => number_format((int) $counts['active']) . ' active of '
                . number_format((int) $counts['total']),
    'actions'  => '<a class="btn" href="' . e(base_url('admin/user')) . '">New account</a>',
]);
?>

<?php if ((int) $counts['dormant'] > 0): ?>
  <div class="alert alert--warning">
    <strong><?= number_format((int) $counts['dormant']) ?></strong> active account(s) have not
    signed in for <?= $dormantDays ?> days or more. A login belonging to someone who has left is
    the single largest exposure on a system like this &mdash; it has a role, it has permissions,
    and nobody is watching it.
    <a href="<?= e(url_with(['view' => 'dormant'])) ?>">Review them</a>
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Active', number_format((int) $counts['active']), 'can sign in') ?>
  <?= tile('Deactivated', number_format((int) $counts['inactive']), 'cannot sign in', 'mute') ?>
  <?= tile('Locked out', number_format((int) $counts['locked']),
        'after failed attempts',
        (int) $counts['locked'] > 0 ? 'warn' : 'ok') ?>
  <?= tile('Dormant', number_format((int) $counts['dormant']),
        'no sign-in in ' . $dormantDays . ' days',
        (int) $counts['dormant'] > 0 ? 'bad' : 'ok') ?>
</div>

<form class="filters" method="get">
  <label class="field" style="flex:1;min-width:180px">
    <span class="field__label">Search</span>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, email or staff number">
  </label>
  <label class="field">
    <span class="field__label">Role</span>
    <select name="role_id"><?= select_options($roles, $roleId, true, 'All roles') ?></select>
  </label>
  <label class="field">
    <span class="field__label">Show</span>
    <select name="view">
      <?= select_options([
          'active'   => 'Active accounts',
          'all'      => 'Everyone',
          'inactive' => 'Deactivated',
          'locked'   => 'Locked out',
          'dormant'  => 'Dormant',
      ], $view) ?>
    </select>
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('admin/users')) ?>">Reset</a>
</form>

<?= card_open('Accounts (' . count($rows) . ')') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No accounts match those filters.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Staff no.</th><th>Name</th><th>Role</th><th>Store</th>
        <th class="num">Perms</th><th>PIN</th><th>Last signed in</th>
        <th class="num">Open findings</th><th>State</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $u):
          $uid     = (int) $u['id'];
          $isMe    = $uid === (int) $_SESSION['uid'];
          $locked  = ($u['locked_until'] !== null && strtotime((string) $u['locked_until']) > time())
                     || ($u['pin_locked_until'] !== null && strtotime((string) $u['pin_locked_until']) > time());
          $dormant = (int) $u['is_active'] === 1
                     && ($u['last_login_at'] === null
                         || strtotime((string) $u['last_login_at']) < strtotime("-$dormantDays days")); ?>
        <tr class="<?= (int) $u['is_active'] === 0 ? '' : ($locked ? 'row-warn' : ($dormant ? 'row-warn' : '')) ?>"
            style="<?= (int) $u['is_active'] === 0 ? 'opacity:.55' : '' ?>">
          <td class="mono tiny"><?= e($u['staff_no']) ?></td>
          <td>
            <a href="<?= e(base_url('admin/user', ['id' => $uid])) ?>"><?= e($u['full_name']) ?></a>
            <?php if ($isMe): ?> <?= badge('you', 'info') ?><?php endif; ?>
            <br><span class="tiny muted"><?= e($u['email']) ?></span>
          </td>
          <td class="tiny">
            <?= e($u['role_name']) ?>
            <?php if ($u['role_code'] === 'admin'): ?><br><?= badge('Full access', 'warn') ?><?php endif; ?>
            <?php if ($u['role_code'] === 'auditor'): ?><br><?= badge('Read only', 'ok') ?><?php endif; ?>
          </td>
          <td class="tiny">
            <?= e($u['store_name'] ?? 'All stores') ?>
            <?php if ((int) $u['extra_stores'] > 0): ?>
              <br><span class="muted">+<?= (int) $u['extra_stores'] ?> more</span>
            <?php endif; ?>
          </td>
          <td class="num"><?= (int) $u['permission_count'] ?></td>
          <td>
            <?= $u['pin_hash'] !== null ? badge('Set', 'ok') : '<span class="tiny muted">&mdash;</span>' ?>
          </td>
          <td class="tiny nowrap">
            <?= e($u['last_login_at'] !== null ? ago((string) $u['last_login_at']) : 'never') ?>
            <?php if ($dormant): ?><br><?= badge('Dormant', 'bad') ?><?php endif; ?>
          </td>
          <td class="num <?= (int) $u['open_exceptions'] > 0 ? 'neg' : '' ?>">
            <?php if ((int) $u['open_exceptions'] > 0): ?>
              <a href="<?= e(base_url('audit/exceptions', ['subject' => $uid, 'status' => 'open'])) ?>">
                <?= (int) $u['open_exceptions'] ?></a>
            <?php else: ?>0<?php endif; ?>
          </td>
          <td class="nowrap">
            <?php if ((int) $u['is_active'] === 0): ?>
              <?= badge('Deactivated', 'neutral') ?>
            <?php elseif ($locked): ?>
              <?= badge('Locked', 'bad') ?>
            <?php elseif ((int) $u['open_tills'] > 0): ?>
              <?= badge('Till open', 'info') ?>
            <?php else: ?>
              <?= badge('Active', 'ok') ?>
            <?php endif; ?>
            <?php if ((int) $u['must_change_password'] === 1): ?>
              <br><span class="tiny muted">must reset</span>
            <?php endif; ?>
          </td>
          <td class="right nowrap">
            <a class="btn btn--sm btn--ghost" href="<?= e(base_url('admin/user', ['id' => $uid])) ?>">Edit</a>
            <?php if ($locked): ?>
              <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="unlock">
                <input type="hidden" name="user_id" value="<?= $uid ?>">
                <button class="btn btn--sm btn--ghost" type="submit">Unlock</button>
              </form>
            <?php endif; ?>
            <?php if (!$isMe): ?>
              <form method="post" class="inline-form"
                    data-confirm="<?= (int) $u['is_active'] === 1
                      ? 'Deactivate ' . e($u['full_name']) . '? They will not be able to sign in.'
                      : 'Reactivate ' . e($u['full_name']) . '?' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="user_id" value="<?= $uid ?>">
                <button class="btn btn--sm <?= (int) $u['is_active'] === 1 ? 'btn--danger' : '' ?>"
                        type="submit"><?= (int) $u['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?= card_open('Accounts are never deleted') ?>
  <p class="small">
    There is no delete on this page, and that is deliberate. Every sale, write-off, approval and
    audit entry in the system points at a user, and the trail records who did what by name. A
    deleted account would leave years of records attributed to nobody, which would make the audit
    trail far less useful than keeping a disabled row.
  </p>
  <p class="small muted">
    Deactivating stops the sign-in immediately, which is the part that matters for security. The
    history stays attributable. If someone's name genuinely has to be removed for data-protection
    reasons, that is a deliberate anonymisation of the user row &mdash; replacing the name and
    email while keeping the id &mdash; not a delete.
  </p>
<?= card_close() ?>

<?php layout_foot(); ?>
