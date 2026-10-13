<?php
/**
 * Administration overview.
 *
 * A health check rather than a menu: it answers "is anything about this
 * installation wrong" first, then offers the links. The checks are the ones a
 * running shop actually gets caught by - an installer left on the server, days
 * never closed, dormant logins, a broken audit chain.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
if (!can_any(['admin.users', 'admin.roles', 'admin.stores', 'admin.settings',
              'admin.security', 'admin.dayend'])) {
    deny_403('You do not hold any administration permission.');
}

$storeId = current_store_id();

/* ------------------------------------------------------------------ */
/* Health checks                                                      */
/* ------------------------------------------------------------------ */
// Each returns [severity, headline, detail, link]. Ordered worst first below.
$checks = [];

// 1. The installer.
if (file_exists(APP_ROOT . '/install.php')) {
    $sealed = file_exists(APP_ROOT . '/.installed');
    $checks[] = [
        $sealed ? 'warning' : 'critical',
        'install.php is still on the server',
        $sealed
            ? 'It has sealed itself and will not run, but it still reveals the database host and '
              . 'table count to anyone who can reach the URL. Delete it.'
            : 'It is NOT sealed, so anyone who can reach the URL can re-sync reference data and '
              . 'read your database configuration. Delete it now.',
        null,
    ];
}

// 2. Debug output.
if (APP_DEBUG && !APP_IS_LOCAL) {
    $checks[] = ['critical', 'Debug output is on, on a non-local host',
        'Stack traces and SQL errors are being printed to the browser. Unset TILLPOINT_DEBUG.', null];
}

// 3. HTTPS.
if (!APP_HTTPS && !APP_IS_LOCAL) {
    $checks[] = ['critical', 'Not served over HTTPS',
        'Session cookies cannot carry the Secure flag, so a session can be captured in transit. '
        . 'Every password and receipt crosses the network in clear.', null];
}

// 4. The audit chain.
$lastCheck = q1('SELECT * FROM audit_integrity_checks ORDER BY id DESC LIMIT 1');
if ($lastCheck === null) {
    $checks[] = ['warning', 'The audit trail has never been verified',
        'Run an integrity check so there is a recorded baseline.',
        can('audit.integrity') ? base_url('audit/integrity') : null];
} elseif ($lastCheck['result'] === 'broken') {
    $checks[] = ['critical', 'The audit trail does not verify',
        'An entry was altered or removed outside the application. Nothing derived from the '
        . 'records can be relied on until that is explained.',
        can('audit.integrity') ? base_url('audit/integrity') : null];
} elseif (strtotime((string) $lastCheck['run_at']) < strtotime('-14 days')) {
    $checks[] = ['warning', 'The audit trail has not been verified recently',
        'Last checked ' . ago((string) $lastCheck['run_at']) . '.',
        can('audit.integrity') ? base_url('audit/integrity') : null];
}

// 5. Stock ledger.
$ledgerIssues = count(stock_ledger_discrepancies());
if ($ledgerIssues > 0) {
    $checks[] = ['critical', "$ledgerIssues stock line(s) disagree with their own ledger",
        'Every stock change goes through one write path that writes both, so this means a defect '
        . 'or a direct database edit. No stock or valuation figure is reliable until it is resolved.',
        can('audit.view') ? base_url('audit/shrinkage') : null];
}

// 6. Days never closed.
$unclosed = (int) qv(
    "SELECT COUNT(DISTINCT s.business_date) FROM sales s
       LEFT JOIN day_end_closures dc ON dc.store_id = s.store_id
              AND dc.business_date = s.business_date AND dc.status = 'closed'
      WHERE s.store_id = ? AND s.business_date < CURDATE()
        AND s.status IN ('completed','refunded','partly_refunded') AND dc.id IS NULL",
    [$storeId], 0
);
if ($unclosed > 0) {
    $checks[] = ['warning', "$unclosed trading day(s) traded and were never closed",
        'An unclosed day keeps recomputing instead of being frozen, and never had its exception '
        . 'scan run - so nothing in it has been checked.',
        can('admin.dayend') ? base_url('admin/day-end') : null];
}

// 7. Dormant logins.
$dormant = (int) qv(
    'SELECT COUNT(*) FROM users WHERE is_active = 1
      AND (last_login_at IS NULL OR last_login_at < (NOW() - INTERVAL 60 DAY))', [], 0
);
if ($dormant > 0) {
    $checks[] = ['warning', "$dormant active account(s) have not signed in for 60 days",
        'A login belonging to somebody who has left has a role, has permissions and nobody '
        . 'watching it.',
        can('admin.users') ? base_url('admin/users', ['view' => 'dormant']) : null];
}

// 8. Switched-off audit rules.
$rulesOff = (int) qv('SELECT COUNT(*) FROM audit_rules WHERE is_active = 0', [], 0);
if ($rulesOff > 0) {
    $checks[] = ['warning', "$rulesOff audit rule(s) are switched off",
        'A switched-off rule is a control that is not operating, whatever the policy says.',
        can('audit.rules') ? base_url('audit/rules') : null];
}

// 9. Negative stock allowed.
if (setting_bool('negative_stock_allowed', NEGATIVE_STOCK_ALLOWED)) {
    $checks[] = ['warning', 'Tills may sell stock the system says is absent',
        'Negative stock is allowed, so an unposted delivery will be hidden by sales against it '
        . 'rather than refused.',
        can('admin.settings') ? base_url('admin/settings') : null];
}

// 10. Nobody but one administrator.
$admins = (int) qv(
    "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
      WHERE r.code = 'admin' AND u.is_active = 1", [], 0
);
if ($admins <= 1) {
    $checks[] = ['warning', 'Only one active administrator',
        'If that account is locked out or leaves, nobody can manage users, and there is no way '
        . 'back from inside the application.',
        can('admin.users') ? base_url('admin/users') : null];
}

// 11. Pending migrations.
$missingColumns = 0;
foreach ([['refunds', 'self_approved'], ['users', 'password_reset_token'],
          ['stores', 'shrinkage_target_pct']] as [$t, $c]) {
    if ((int) qv('SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                 [DB_NAME, $t, $c], 0) === 0) {
        $missingColumns++;
    }
}
if ($missingColumns > 0) {
    $checks[] = ['warning', "$missingColumns schema migration(s) have not been applied",
        'Run php database/migrate.php --apply. Until then some pages will fall back to defaults '
        . 'or fail.', null];
}

// Worst first.
$order = ['critical' => 0, 'warning' => 1, 'info' => 2];
usort($checks, fn ($a, $b) => ($order[$a[0]] ?? 3) <=> ($order[$b[0]] ?? 3));
$critical = count(array_filter($checks, fn ($c) => $c[0] === 'critical'));

/* ------------------------------------------------------------------ */
/* Figures                                                            */
/* ------------------------------------------------------------------ */
$counts = q1(
    'SELECT (SELECT COUNT(*) FROM users WHERE is_active = 1) AS users,
            (SELECT COUNT(*) FROM roles) AS roles,
            (SELECT COUNT(*) FROM stores WHERE is_active = 1) AS stores,
            (SELECT COUNT(*) FROM terminals WHERE is_active = 1) AS terminals,
            (SELECT COUNT(*) FROM products WHERE is_active = 1) AS products,
            (SELECT COUNT(*) FROM audit_log) AS trail,
            (SELECT COUNT(*) FROM sales) AS sales,
            (SELECT COUNT(*) FROM stock_movements) AS movements'
);
$dbSize = (float) qv(
    'SELECT COALESCE(SUM(data_length + index_length), 0) / 1048576
       FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [DB_NAME], 0.0
);

$sections = [
    ['Staff accounts', 'admin/users', 'admin.users',
     'Create and deactivate sign-ins, reset passwords, assign stores.'],
    ['Roles and permissions', 'admin/roles', 'admin.roles',
     'The grant matrix every permission check resolves against, with duty-segregation warnings.'],
    ['Stores and tills', 'admin/stores', 'admin.stores',
     'Branches, depots and terminals. Nothing is deleted, only retired.'],
    ['Settings', 'admin/settings', 'admin.settings',
     'The thresholds the controls use. Loosening one is logged at critical severity.'],
    ['Reason codes', 'admin/reason-codes', 'admin.settings',
     'The closed lists behind every void, write-off and refund dropdown.'],
    ['Sign-in security', 'admin/security', 'admin.security',
     'What the throttles caught, grouped by address so password spraying is visible.'],
    ['Day end', 'admin/day-end', 'admin.dayend',
     'Freeze a trading day and run the exception scan over it.'],
];

layout_head([
    'title'    => 'Administration',
    'subtitle' => $checks === []
        ? 'No configuration problems found'
        : count($checks) . ' thing(s) worth attention'
          . ($critical > 0 ? ', ' . $critical . ' critical' : ''),
]);
?>

<?php if ($checks === []): ?>
  <div class="verdict verdict--ok">
    <h2>Nothing needs attention</h2>
    <p>
      The installer is gone, the audit chain verifies, the stock ledger reconciles, trading days
      are being closed, and no account has been left dormant.
    </p>
  </div>
<?php else: ?>
  <?php foreach ($checks as [$severity, $headline, $detail, $link]): ?>
    <div class="alert alert--<?= $severity === 'critical' ? 'error' : 'warning' ?>">
      <strong><?= e($headline) ?></strong><br>
      <?= e($detail) ?>
      <?php if ($link !== null): ?>
        <a href="<?= e($link) ?>">Go there</a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="tiles">
  <?= tile('Active staff', number_format((int) $counts['users']),
        (int) $counts['roles'] . ' roles') ?>
  <?= tile('Stores', number_format((int) $counts['stores']),
        (int) $counts['terminals'] . ' tills') ?>
  <?= tile('Products', number_format((int) $counts['products']), 'active lines') ?>
  <?= tile('Transactions', number_format((int) $counts['sales']), 'all time', 'mute') ?>
  <?= tile('Trail entries', number_format((int) $counts['trail']),
        number_format((int) $counts['movements']) . ' stock movements', 'info') ?>
  <?= tile('Database size', number_format($dbSize, 1) . ' MB',
        'across ' . (string) qv('SELECT COUNT(*) FROM information_schema.TABLES
                                  WHERE TABLE_SCHEMA = ?', [DB_NAME], '0') . ' tables', 'mute') ?>
</div>

<?= card_open('Administration') ?>
  <div class="grid-2">
    <?php foreach ($sections as [$title, $path, $cap, $blurb]):
        $allowed = can($cap); ?>
      <div style="<?= $allowed ? '' : 'opacity:.5' ?>">
        <h3>
          <?php if ($allowed): ?>
            <a href="<?= e(base_url($path)) ?>"><?= e($title) ?></a>
          <?php else: ?>
            <?= e($title) ?> <?= badge('No access', 'neutral') ?>
          <?php endif; ?>
        </h3>
        <p class="small muted"><?= e($blurb) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
<?= card_close() ?>

<?= card_open('Maintenance that is not in the interface') ?>
  <p class="small muted">
    These run from the command line, in the application directory. They are deliberately not
    buttons: each one either writes to the schema or corrects records, and both want a person at
    a terminal who has taken a backup.
  </p>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr><th>Command</th><th>What it does</th></tr></thead>
    <tbody>
      <tr>
        <td class="mono tiny">php database/migrate.php</td>
        <td class="small">
          Reports which additive schema changes are outstanding. Add <code>--apply</code> to write
          them. Idempotent, and never drops or retypes anything.
        </td>
      </tr>
      <tr>
        <td class="mono tiny">php cron/daily-scan.php</td>
        <td class="small">
          Runs the audit exception scan over yesterday for every store, verifies the audit chain,
          expires vouchers and loyalty points, and prunes old sign-in records. Schedule this.
        </td>
      </tr>
      <tr>
        <td class="mono tiny">php tests/smoke.php</td>
        <td class="small">
          Asserts the invariants: positions against the ledger, batches against positions, sale
          totals against their lines, refunds against what was sold, and the hash chain end to end.
        </td>
      </tr>
      <tr>
        <td class="mono tiny">php database/repair-batch-ledger.php</td>
        <td class="small">
          Brings batch balances back into step with their positions after a historic drift.
          Reports by default; <code>--apply</code> to correct.
        </td>
      </tr>
    </tbody>
  </table>
  </div>
<?= card_close() ?>

<?php layout_foot(); ?>
