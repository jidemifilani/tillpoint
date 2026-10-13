<?php
/**
 * Installer.
 *
 * Creates the database if it is absent, applies database/schema.sql, syncs the
 * reference data, and optionally generates a working supermarket to look at.
 *
 * Safe to re-run. schema.sql is CREATE TABLE IF NOT EXISTS throughout and the
 * seeders insert only what is missing, so running this against a live database
 * repairs reference data without touching trading records. The destructive
 * path is database/reset.sql, which this file never executes.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/numbering.php';
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/stock_docs.php';
require_once __DIR__ . '/includes/promotions.php';
require_once __DIR__ . '/includes/pos.php';
require_once __DIR__ . '/includes/audit_rules.php';
require_once __DIR__ . '/includes/reports.php';
require_once __DIR__ . '/includes/seed.php';
require_once __DIR__ . '/includes/seed_trading.php';

set_time_limit(900);     // generating a fortnight of trading takes a while

$steps  = [];
$errors = [];
$done   = false;

/**
 * Once installed, this file is a live re-sync endpoint that anyone who can
 * reach the URL could trigger. The sentinel locks it: after a successful run it
 * refuses to do anything until an administrator either deletes install.php
 * (the right answer) or removes the sentinel deliberately.
 */
define('INSTALL_SENTINEL', APP_ROOT . '/.installed');
$sealed = file_exists(INSTALL_SENTINEL);

/** Connect without naming a database, so the database itself can be created. */
function server_pdo(): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET),
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function db_exists(): bool
{
    try {
        $pdo = server_pdo();
        $st  = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $st->execute([DB_NAME]);
        return $st->fetchColumn() !== false;
    } catch (Throwable) {
        return false;
    }
}

function tables_present(): int
{
    try {
        return (int) qv('SELECT COUNT(*) FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\'', [DB_NAME], 0);
    } catch (Throwable) {
        return 0;
    }
}

function admin_exists(): bool
{
    try {
        return (int) qv('SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
                          WHERE r.code = \'admin\'', [], 0) > 0;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Apply schema.sql.
 *
 * Split on semicolons at end of line, which is sufficient because the schema
 * deliberately contains no stored routines, no triggers and no string literal
 * holding a semicolon - the one case a naive split would break on.
 */
function apply_schema(): array
{
    $path = APP_ROOT . '/database/schema.sql';
    if (!is_readable($path)) {
        throw new RuntimeException('Cannot read database/schema.sql');
    }
    $sql = (string) file_get_contents($path);

    // A guard, not decoration: this file must never be able to drop anything.
    if (preg_match('/\b(DROP\s+(DATABASE|SCHEMA|TABLE)|TRUNCATE)\b/i', $sql, $m)) {
        throw new RuntimeException('Refusing to run schema.sql: it contains ' . $m[1]
            . '. The installer only ever creates.');
    }

    $statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]/', $sql)));
    $applied = 0;
    foreach ($statements as $stmt) {
        $clean = preg_replace('/^\s*--.*$/m', '', $stmt);
        if (trim((string) $clean) === '') {
            continue;
        }
        db()->exec($stmt);
        $applied++;
    }
    return ["$applied schema statements applied", tables_present() . ' tables present'];
}

/* ------------------------------------------------------------------ */
/* Run                                                                */
/* ------------------------------------------------------------------ */
$action = (string) ($_POST['action'] ?? '');

if ($sealed && is_post()) {
    $errors[] = 'This installation is sealed. TillPoint has already been installed, so this page '
        . 'will not run again. Delete install.php from the server - that is what the sentinel is '
        . 'there to prompt. If you genuinely need to re-sync reference data, remove the '
        . '.installed file in the application root first, and put it back afterwards.';
    $action = '';
}

if (is_post() && $action !== '') {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Reload this page and try again.';
    } else {
        try {
            if (!db_exists()) {
                server_pdo()->exec(sprintf(
                    'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    str_replace('`', '', DB_NAME)
                ));
                $steps[] = 'Database `' . DB_NAME . '` created';
            } else {
                $steps[] = 'Database `' . DB_NAME . '` already present';
            }

            $steps = array_merge($steps, apply_schema());
            $steps = array_merge($steps, seed_reference());

            /* ---- The first administrator ---------------------------- */
            $adminEmail = strtolower(trim((string) post('admin_email')));
            $adminName  = trim((string) post('admin_name'));
            $adminPass  = (string) ($_POST['admin_password'] ?? '');

            if (!admin_exists()) {
                if ($adminName === '' || $adminEmail === '') {
                    throw new RuntimeException('Enter a name and email for the first administrator.');
                }
                if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('That administrator email address is not valid.');
                }
                if ($problem = password_problem($adminPass)) {
                    throw new RuntimeException($problem);
                }
                $roleId = (int) qv('SELECT id FROM roles WHERE code = \'admin\'', [], 0);
                $adminId = db_insert('users', [
                    'staff_no'      => 'ADM001',
                    'full_name'     => $adminName,
                    'email'         => $adminEmail,
                    'password_hash' => hash_password($adminPass),
                    'role_id'       => $roleId,
                    'store_id'      => null,
                    'is_active'     => 1,
                ]);
                // Act as the new administrator, so the install itself is
                // attributable in the trail rather than appearing from nowhere.
                $_SESSION['uid']       = $adminId;
                $_SESSION['user_name'] = $adminName;
                $_SESSION['role_code'] = 'admin';
                audit_log('system.install', 'system', null,
                    'TillPoint installed; first administrator created', null, null, 'critical');
                $steps[] = "Administrator '$adminName' created";
            } else {
                $steps[] = 'An administrator already exists - left untouched';
                $first = q1('SELECT id, full_name FROM users u JOIN roles r ON r.id = u.role_id
                              WHERE r.code = \'admin\' ORDER BY u.id LIMIT 1');
                $_SESSION['uid']       = (int) $first['id'];
                $_SESSION['user_name'] = (string) $first['full_name'];
                $_SESSION['role_code'] = 'admin';
            }

            /* ---- Demonstration data -------------------------------- */
            if ($action === 'install_demo') {
                $ctx   = seed_demo();
                $steps = array_merge($steps, $ctx['log']);
                $steps = array_merge($steps, seed_opening_stock($ctx));

                $days  = max(1, min(30, (int) post_int('demo_days', 14)));
                $steps = array_merge($steps, seed_trading($ctx, $days));
                $steps = array_merge($steps, seed_run_audit_scan($ctx, $days));

                $check = audit_record_integrity_check((int) ($_SESSION['uid'] ?? 0),
                    'Verification immediately after install');
                $steps[] = 'Audit trail: ' . $check['detail'];

                $disc = stock_ledger_discrepancies();
                $steps[] = $disc
                    ? 'WARNING: ' . count($disc) . ' stock lines disagree with the ledger'
                    : 'Stock ledger reconciles against every position';
            }

            // Seal it. Written before the session is cleared so a failure to
            // write is visible in this request rather than on the next one.
            if (@file_put_contents(INSTALL_SENTINEL,
                    "TillPoint installed " . date('c') . PHP_EOL
                    . "Delete install.php from the server." . PHP_EOL) !== false) {
                $steps[] = 'Installation sealed (.installed written)';
            } else {
                $steps[] = 'WARNING: could not write the .installed sentinel - '
                    . 'delete install.php manually';
            }

            $done = true;
            // The installer signs the session in as the administrator, but leaving
            // it signed in would mean anyone who reloads install.php lands in the
            // app as them. Clear it and make them sign in properly.
            session_regenerate_id(true);
            unset($_SESSION['uid'], $_SESSION['user_name'], $_SESSION['role_code']);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            if (APP_DEBUG) {
                $errors[] = 'at ' . basename($e->getFile()) . ':' . $e->getLine();
            }
        }
    }
}

$alreadyInstalled = db_exists() && tables_present() > 20 && admin_exists();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install &middot; <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('css/app.css')) ?>">
<style>
  body { background: #f5f8f7; }
  .wrap { max-width: 780px; margin: 0 auto; padding: 2rem 1.2rem 4rem; }
  .steps { font-family: var(--mono); font-size: .8rem; background: #0c1a16; color: #cfe8df;
           padding: 1rem; border-radius: 10px; max-height: 420px; overflow-y: auto; }
  .steps div { padding: .1rem 0; }
  .hdr { display: flex; align-items: center; gap: .8rem; margin-bottom: 1.4rem; }
  .hdr svg { width: 48px; height: 48px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <svg viewBox="0 0 40 40" role="img" aria-label="TillPoint">
      <rect x="2" y="2" width="36" height="36" rx="9" fill="#0d7a5f"/>
      <path d="M10 24h20v6a2 2 0 0 1-2 2H12a2 2 0 0 1-2-2z" fill="#fff" opacity=".92"/>
      <path d="M13 24V13a3 3 0 0 1 3-3h8a3 3 0 0 1 3 3v11" fill="none" stroke="#fff" stroke-width="2.4" stroke-linejoin="round"/>
      <path d="M17 17h6" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>
      <circle cx="20" cy="28.5" r="1.9" fill="#0d7a5f"/>
    </svg>
    <div>
      <h1><?= e(SITE_NAME) ?> installer</h1>
      <p class="muted mb0"><?= e(SITE_TAGLINE) ?></p>
    </div>
  </div>

  <?php foreach ($errors as $err): ?>
    <div class="alert alert--error"><?= e($err) ?></div>
  <?php endforeach; ?>

  <?php if ($done): ?>
    <div class="alert alert--success">
      Installation finished. Sign in with the administrator account you created.
    </div>
    <section class="card"><div class="card__body">
      <div class="steps">
        <?php foreach ($steps as $s): ?><div><?= e($s) ?></div><?php endforeach; ?>
      </div>
      <div class="btn-row mt1">
        <a class="btn btn--lg" href="<?= e(base_url('login')) ?>">Go to sign-in</a>
      </div>
      <p class="small muted mt1">
        Delete <code>install.php</code> once you are satisfied the system is working.
        While it exists, anyone who can reach it can re-run the reference-data sync.
      </p>
    </div></section>

  <?php elseif ($sealed): ?>
    <div class="alert alert--info">
      <strong>This installation is sealed.</strong>
      TillPoint is installed and <code>install.php</code> has locked itself.
      Delete this file from the server &mdash; while it exists, anyone who can reach the URL can
      see your database host and table count.
    </div>
    <section class="card"><div class="card__body">
      <p class="small muted">
        To re-sync reference data after an upgrade, delete the <code>.installed</code> file in the
        application root, run this page, then restore it. Day to day, prefer
        <code>php database/migrate.php --apply</code>, which is additive and leaves no open endpoint.
      </p>
      <a class="btn" href="<?= e(base_url('login')) ?>">Go to sign-in</a>
    </div></section>

  <?php elseif ($alreadyInstalled): ?>
    <div class="alert alert--info">
      <?= e(SITE_NAME) ?> is already installed: <?= tables_present() ?> tables and at least one
      administrator account. Re-running the installer below will re-sync permissions, roles,
      reason codes, audit rules and settings. It will not touch trading records, and it cannot
      drop anything.
    </div>
    <form method="post" class="card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sync">
      <div class="card__body">
        <h2>Re-sync reference data</h2>
        <p class="small muted">Use this after an upgrade that adds a permission or an audit rule.</p>
        <button class="btn" type="submit">Re-sync reference data</button>
        <a class="btn btn--ghost" href="<?= e(base_url('login')) ?>">Sign in instead</a>
      </div>
    </form>

  <?php else: ?>
    <section class="card"><div class="card__body">
      <h2>What this will do</h2>
      <ol class="small">
        <li>Create the <code><?= e(DB_NAME) ?></code> database if it does not exist.</li>
        <li>Apply <code>database/schema.sql</code> &mdash; <?= count(audit_rule_catalogue()) ?> audit rules,
            <?= count(permission_flat()) ?> permissions and the full table set.</li>
        <li>Create the seven standard roles, including a read-only Internal Auditor
            that holds no permission to post a transaction.</li>
        <li>Create your administrator account.</li>
      </ol>
      <p class="small muted">
        Connecting to <code><?= e(DB_USER . '@' . DB_HOST . ':' . DB_PORT) ?></code>.
        Change that in <code>config/config.php</code> if it is wrong.
      </p>
    </div></section>

    <form method="post" class="card" data-no-guard>
      <?= csrf_field() ?>
      <div class="card__head"><h2>Administrator account</h2></div>
      <div class="card__body">
        <div class="form-grid">
          <label class="field">
            <span class="field__label">Full name</span>
            <input type="text" name="admin_name" required value="<?= e((string) post('admin_name')) ?>">
          </label>
          <label class="field">
            <span class="field__label">Email address</span>
            <input type="email" name="admin_email" required value="<?= e((string) post('admin_email')) ?>">
          </label>
        </div>
        <label class="field">
          <span class="field__label">Password</span>
          <input type="password" name="admin_password" required minlength="<?= PASSWORD_MIN_LENGTH ?>">
          <span class="field__hint">At least <?= PASSWORD_MIN_LENGTH ?> characters, with a letter and a digit.</span>
        </label>

        <fieldset>
          <legend>Demonstration data</legend>
          <p class="small muted">
            Builds three stores, twelve staff, <?= '70+' ?> products, opening stock and a
            fortnight of real trading &mdash; shifts, baskets, refunds, wastage, deliveries and a
            stock count. Everything is posted through the same routines the live system uses, so
            the reports and the exception queue have genuine data to show. It takes a minute or two.
          </p>
          <label class="field" style="max-width:220px">
            <span class="field__label">Days of trading history</span>
            <input type="number" name="demo_days" value="14" min="1" max="30" class="num-in">
          </label>
        </fieldset>

        <div class="btn-row">
          <button class="btn btn--lg" type="submit" name="action" value="install_demo">
            Install with demonstration data
          </button>
          <button class="btn btn--ghost btn--lg" type="submit" name="action" value="install_bare">
            Install empty
          </button>
        </div>
      </div>
    </form>
  <?php endif; ?>
</div>
<script src="<?= e(asset_url('js/app.js')) ?>"></script>
</body>
</html>
