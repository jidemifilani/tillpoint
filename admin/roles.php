<?php
/**
 * Roles and permissions.
 *
 * The grant matrix is the whole access-control model: every require_can() in the
 * application resolves against these rows. So the page warns rather than
 * silently allowing when a grant creates a duty-segregation conflict - giving
 * one role both "raise" and "approve" for the same document does not let anyone
 * approve their own paperwork (the posting routines refuse that outright), but
 * it does mean the second pair of eyes can come from the same job.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('admin.roles');

/** The pairs that should not usually sit in one role. Mirrors audit/sod.php. */
function sod_pairs(): array
{
    return [
        ['po.create',     'po.approve',           'raise and approve purchase orders'],
        ['invoice.enter', 'invoice.approve',      'enter and approve supplier invoices'],
        ['refund.request', 'refund.approve',      'raise and approve refunds'],
        ['count.enter',   'count.approve',        'count stock and sign off the variance'],
        ['wastage.log',   'wastage.approve',      'log and approve write-offs'],
        ['stock.adjust',  'stock.adjust.approve', 'raise and approve stock adjustments'],
        ['pos.operate',   'pos.session.reconcile', 'work a till and clear till variances'],
        ['transfer.dispatch', 'transfer.receive',  'dispatch and receive transfers'],
    ];
}

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');

        if ($action === 'save_grants') {
            $roleId = post_int('role_id');
            $role   = q1('SELECT * FROM roles WHERE id = ?', [$roleId]);
            if (!$role) {
                throw new RuntimeException('That role no longer exists.');
            }

            $wanted = array_values(array_unique(array_map('strval',
                (array) ($_POST['permissions'] ?? []))));
            $valid  = array_keys(permission_flat());
            $wanted = array_values(array_intersect($wanted, $valid));

            $before = qcol('SELECT permission_code FROM role_permissions WHERE role_id = ?', [$roleId]);
            $added   = array_values(array_diff($wanted, $before));
            $removed = array_values(array_diff($before, $wanted));

            if (!$added && !$removed) {
                flash_info('Nothing changed.');
                redirect('admin/roles', ['role' => $roleId]);
            }

            // Removing the last route to user administration would lock everyone
            // out of this page, which cannot be undone from the interface.
            if (in_array('admin.users', $removed, true)) {
                $othersWithIt = (int) qv(
                    'SELECT COUNT(DISTINCT u.id) FROM users u
                       JOIN role_permissions rp ON rp.role_id = u.role_id
                      WHERE rp.permission_code = ? AND u.is_active = 1 AND u.role_id <> ?',
                    ['admin.users', $roleId], 0
                );
                if ($othersWithIt === 0) {
                    throw new RuntimeException('No other active account would be able to manage '
                        . 'users. Removing that permission here would lock everyone out of '
                        . 'administration, and there is no way back from inside the application.');
                }
            }

            db_tx(function () use ($roleId, $wanted): void {
                q('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
                foreach ($wanted as $code) {
                    q('INSERT IGNORE INTO role_permissions (role_id, permission_code) VALUES (?, ?)',
                      [$roleId, $code]);
                }
            });

            // A grant is a change to what people can do, so the entry names the
            // specific permissions rather than just saying the role changed.
            audit_log('role.grants', 'role', $roleId,
                sprintf('%s permissions changed: %s%s%s',
                    $role['name'],
                    $added ? '+' . implode(', +', $added) : '',
                    $added && $removed ? '; ' : '',
                    $removed ? '-' . implode(', -', $removed) : ''),
                ['permissions' => $before], ['permissions' => $wanted], 'critical');

            // Warn about any conflict the new set creates.
            // Only pairs this change newly completed. Re-warning about a
            // conflict that was already there on every unrelated save would
            // train whoever is editing roles to ignore the warning.
            $conflicts = [];
            foreach (sod_pairs() as [$a, $b, $what]) {
                $nowBoth    = in_array($a, $wanted, true) && in_array($b, $wanted, true);
                $beforeBoth = in_array($a, $before, true) && in_array($b, $before, true);
                if ($nowBoth && !$beforeBoth) {
                    $conflicts[] = $what;
                }
            }
            foreach ($conflicts as $what) {
                flash_warn($role['name'] . ' can now ' . $what
                    . '. Nobody can act on their own document, but the second pair of eyes will '
                    . 'come from the same job. Check that is what you want.');
            }

            flash_ok(sprintf('%s updated: %d added, %d removed.',
                $role['name'], count($added), count($removed)));
            redirect('admin/roles', ['role' => $roleId]);
        }

        if ($action === 'create') {
            $code = strtolower(trim((string) post('code')));
            $name = trim((string) post('name'));
            if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code)) {
                throw new RuntimeException('The code must start with a letter and contain only '
                    . 'lowercase letters, digits and underscores.');
            }
            if ($name === '') {
                throw new RuntimeException('Give the role a name.');
            }
            if (qv('SELECT id FROM roles WHERE code = ?', [$code])) {
                throw new RuntimeException('That code is already in use.');
            }
            $newId = db_insert('roles', [
                'code'        => $code,
                'name'        => $name,
                'description' => (string) post('description') !== '' ? (string) post('description') : null,
                'is_system'   => 0,
            ]);
            audit_log('role.create', 'role', $newId,
                'Role created: ' . $name . ' (' . $code . ') with no permissions',
                null, null, 'critical');
            flash_ok('Role created with no permissions. Grant what it needs below.');
            redirect('admin/roles', ['role' => $newId]);
        }

        if ($action === 'delete') {
            $roleId = post_int('role_id');
            $role   = q1('SELECT * FROM roles WHERE id = ?', [$roleId]);
            if (!$role) {
                throw new RuntimeException('That role no longer exists.');
            }
            if ((int) $role['is_system'] === 1) {
                throw new RuntimeException('"' . $role['name'] . '" is one of the standard roles '
                    . 'and cannot be deleted. Remove its permissions instead if you do not want '
                    . 'it used.');
            }
            $holders = (int) qv('SELECT COUNT(*) FROM users WHERE role_id = ?', [$roleId], 0);
            if ($holders > 0) {
                throw new RuntimeException($holders . ' account(s) still hold this role. '
                    . 'Move them to another role first.');
            }
            db_tx(function () use ($roleId, $role): void {
                q('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
                q('DELETE FROM roles WHERE id = ?', [$roleId]);
                audit_log('role.delete', 'role', $roleId,
                    'Role deleted: ' . $role['name'], (array) $role, null, 'critical');
            });
            flash_ok('Role deleted.');
            redirect('admin/roles');
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('admin/roles' . (post_int('role_id') > 0 ? '?role=' . post_int('role_id') : ''));
    }
}

$roles = qa(
    'SELECT r.*,
            (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.is_active = 1) AS holders,
            (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS grants
       FROM roles r ORDER BY r.is_system DESC, r.name'
);

$selectedId = get_int('role');
$selected   = $selectedId > 0 ? q1('SELECT * FROM roles WHERE id = ?', [$selectedId]) : null;
$held       = $selected !== null
    ? array_fill_keys(qcol('SELECT permission_code FROM role_permissions WHERE role_id = ?',
                           [$selectedId]), true)
    : [];

$catalogue = permission_catalogue();

// Permissions no role grants at all. Either dead weight in the catalogue or a
// capability nobody can currently use - both worth seeing.
$grantedAnywhere = array_fill_keys(qcol('SELECT DISTINCT permission_code FROM role_permissions'), true);
$ungranted = [];
foreach (permission_flat() as $code => $meta) {
    if (!isset($grantedAnywhere[$code])) {
        $ungranted[$code] = $meta;
    }
}

layout_head([
    'title'    => 'Roles and permissions',
    'subtitle' => count($roles) . ' roles, ' . count(permission_flat()) . ' permissions',
    'actions'  => can('audit.view')
        ? '<a class="btn btn--ghost" href="' . e(base_url('audit/sod')) . '">Duty segregation</a>' : '',
]);
?>

<?= card_open('Roles') ?>
  <div class="table-wrap">
  <table class="data">
    <thead><tr>
      <th>Role</th><th>Description</th><th class="num">Staff</th>
      <th class="num">Permissions</th><th>Conflicts</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($roles as $r):
        $rid   = (int) $r['id'];
        $perms = array_fill_keys(
            qcol('SELECT permission_code FROM role_permissions WHERE role_id = ?', [$rid]), true);
        $conflicts = [];
        foreach (sod_pairs() as [$a, $b, $what]) {
            if (isset($perms[$a], $perms[$b])) {
                $conflicts[] = $what;
            }
        } ?>
      <tr class="<?= $rid === $selectedId ? 'row-warn' : '' ?>">
        <td>
          <a href="<?= e(url_with(['role' => $rid])) ?>"><strong><?= e($r['name']) ?></strong></a>
          <br><span class="tiny muted mono"><?= e($r['code']) ?></span>
          <?php if ((int) $r['is_system'] === 1): ?> <?= badge('Standard', 'info') ?><?php endif; ?>
        </td>
        <td class="small muted"><?= e($r['description'] ?? '') ?></td>
        <td class="num"><?= number_format((int) $r['holders']) ?></td>
        <td class="num"><?= number_format((int) $r['grants']) ?></td>
        <td class="tiny">
          <?php if ($r['code'] === 'admin'): ?>
            <?= badge('All, by design', 'warn') ?>
          <?php elseif ($conflicts): ?>
            <?= badge(count($conflicts) . ' pair(s)', 'bad') ?>
            <br><span class="muted"><?= e(implode('; ', array_slice($conflicts, 0, 2))) ?></span>
          <?php else: ?>
            <span class="ok-text">none</span>
          <?php endif; ?>
        </td>
        <td class="right nowrap">
          <a class="btn btn--sm btn--ghost" href="<?= e(url_with(['role' => $rid])) ?>">Permissions</a>
          <?php if ((int) $r['is_system'] === 0 && (int) $r['holders'] === 0): ?>
            <form method="post" class="inline-form"
                  data-confirm="Delete the role <?= e($r['name']) ?>?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="role_id" value="<?= $rid ?>">
              <button class="btn btn--sm btn--danger" type="submit">Delete</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>

<?php if ($selected !== null): ?>
  <?= card_open('Permissions for ' . $selected['name'],
        '<a class="btn btn--sm btn--ghost" href="' . e(base_url('admin/roles')) . '">Close</a>') ?>
    <?php if ($selected['code'] === 'admin'): ?>
      <div class="alert alert--warning">
        This is the break-glass role. It is expected to hold everything, and the control over it
        is that very few accounts have it and every action is in the trail &mdash; not that its
        permissions are restricted. Removing things from it tends to produce confusing failures
        rather than better security.
      </div>
    <?php elseif ($selected['code'] === 'auditor'): ?>
      <div class="alert alert--info">
        The Internal Auditor role deliberately holds no permission that creates or approves a
        transaction. An auditor who can post stock is not an auditor. The smoke test asserts this,
        so granting one of those here will make <code>tests/smoke.php</code> fail &mdash; which is
        the intended behaviour, not a bug.
      </div>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_grants">
      <input type="hidden" name="role_id" value="<?= $selectedId ?>">

      <?php foreach ($catalogue as $module => $perms): ?>
        <fieldset>
          <legend><?= e(ucfirst($module)) ?></legend>
          <?php foreach ($perms as $code => $label): ?>
            <div class="check">
              <input type="checkbox" name="permissions[]" value="<?= e($code) ?>"
                     id="p-<?= e(str_replace('.', '-', $code)) ?>"
                     <?= isset($held[$code]) ? 'checked' : '' ?>>
              <label for="p-<?= e(str_replace('.', '-', $code)) ?>">
                <?= e($label) ?>
                <span class="tiny muted mono"><?= e($code) ?></span>
              </label>
            </div>
          <?php endforeach; ?>
        </fieldset>
      <?php endforeach; ?>

      <div class="btn-row">
        <button class="btn btn--lg" type="submit">Save permissions</button>
        <a class="btn btn--ghost" href="<?= e(base_url('admin/roles')) ?>">Cancel</a>
      </div>
    </form>
  <?= card_close() ?>
<?php endif; ?>

<?= card_open('Create a role') ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-grid">
      <label class="field">
        <span class="field__label">Code</span>
        <input type="text" name="code" required maxlength="40" placeholder="duty_manager">
        <span class="field__hint">Lowercase, no spaces. Cannot be changed afterwards.</span>
      </label>
      <label class="field">
        <span class="field__label">Name</span>
        <input type="text" name="name" required maxlength="80" placeholder="Duty Manager">
      </label>
      <label class="field">
        <span class="field__label">Description</span>
        <input type="text" name="description" maxlength="255"
               placeholder="What this role is for">
      </label>
    </div>
    <p class="small muted">
      A new role starts with no permissions, so nobody holding it can open anything until you
      grant something. That is safer than copying an existing role and forgetting to take
      something away.
    </p>
    <button class="btn" type="submit">Create role</button>
  </form>
<?= card_close() ?>

<?php if ($ungranted): ?>
<?= card_open('Permissions no role grants (' . count($ungranted) . ')') ?>
  <p class="small muted">
    Nobody in the system can do these. Either the capability is deliberately switched off, or a
    role is missing something it needs and somebody is hitting a refusal they cannot explain.
  </p>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr><th>Permission</th><th>Module</th><th>Code</th></tr></thead>
    <tbody>
    <?php foreach ($ungranted as $code => $meta): ?>
      <tr>
        <td><?= e($meta['label']) ?></td>
        <td class="tiny"><?= e(ucfirst($meta['module'])) ?></td>
        <td class="mono tiny"><?= e($code) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?php layout_foot(); ?>
