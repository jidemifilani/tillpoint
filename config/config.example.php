<?php
/**
 * TillPoint - application configuration, EXAMPLE.
 *
 * Copy this to config/config.php and fill in the database credentials.
 * The real config.php is deliberately excluded from version control:
 * a credential committed to a git history has to be rotated, not deleted.
 *
 * Loaded before any session is started (see includes/bootstrap.php).
 */
declare(strict_types=1);

// ---- Branding -------------------------------------------------------
define('SITE_NAME',    'TillPoint');
define('SITE_TAGLINE', 'Supermarket Inventory, Sales & Audit Suite');
define('COMPANY_NAME', 'Freshmart Stores Ltd');

// ---- Paths / URLs ---------------------------------------------------
define('APP_ROOT', dirname(__DIR__));
// Where the app sits under the web root. Derived rather than fixed, so the same
// code builds correct URLs whether it is served from http://localhost/tillpoint
// (BASE_PATH '/tillpoint') or from a vhost pointed at the app folder
// (BASE_PATH ''). CLI has no DOCUMENT_ROOT and falls back to the constant.
define('BASE_PATH_FALLBACK', '/tillpoint');

(function (): void {
    // An empty DOCUMENT_ROOT must be treated as absent: realpath('') returns the
    // working directory on Windows, which would make CLI look like a vhost.
    $raw     = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $docRoot = $raw !== '' ? realpath($raw) : false;
    $appRoot = realpath(APP_ROOT);

    if ($docRoot === false || $appRoot === false) {
        define('BASE_PATH', BASE_PATH_FALLBACK);
        return;
    }
    $norm = static fn (string $p): string => rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $p), '/');
    $d = $norm($docRoot);
    $a = $norm($appRoot);

    // Windows paths vary in case between DOCUMENT_ROOT and realpath().
    $starts = DIRECTORY_SEPARATOR !== '/' ? stripos($a, $d) === 0 : strpos($a, $d) === 0;
    define('BASE_PATH', $starts ? substr($a, strlen($d)) : BASE_PATH_FALLBACK);
})();

// ---- Database -------------------------------------------------------
define('DB_HOST',    '127.0.0.1');
define('DB_PORT',    '3306');
define('DB_NAME',    'tillpoint');
define('DB_USER',    'CHANGE_ME');
define('DB_PASS',    'CHANGE_ME');
define('DB_CHARSET', 'utf8mb4');

// ---- Money / locale -------------------------------------------------
define('BASE_CURRENCY',   'NGN');
define('CURRENCY_SYMBOL', "\u{20A6}");      // naira sign
define('APP_TIMEZONE',    'Africa/Lagos');
define('VAT_RATE',        0.075);           // 7.5% Nigerian VAT, default for new products

// ---- Security -------------------------------------------------------
define('SESSION_NAME',         'tillpoint_sess');
define('MAX_LOGIN_ATTEMPTS',   5);
define('LOCKOUT_MINUTES',      15);
define('IDLE_TIMEOUT_MINUTES', 45);
define('PASSWORD_MIN_LENGTH',  8);
define('PASSWORD_ALGO', defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);

// Supervisor PIN. A cashier stays logged in at the till all shift; an override
// (price change, void, big discount) is authorised by a supervisor typing a PIN
// at the terminal rather than by swapping sessions. The PIN is hashed like a
// password and is never a substitute for a login - it only authorises the one
// action, and who authorised it is recorded on the line.
define('PIN_LENGTH',           4);
define('PIN_MAX_ATTEMPTS',     5);
define('PIN_LOCKOUT_MINUTES',  10);

// ---- Login rate limiting by IP --------------------------------------
// The per-account lockout above stops someone grinding one password. These stop
// one address trying a few passwords against many accounts in turn. Limits are
// set well above normal fumbling because a store behind one NAT address shares it.
define('LOGIN_IP_WINDOW_MINUTES',    15);
define('LOGIN_IP_MAX_FAILS',         20);
define('LOGIN_IP_MAX_IDENTITIES',    6);
define('LOGIN_ATTEMPT_RETENTION_DAYS', 30);

// ---- Operational thresholds -----------------------------------------
// These are defaults. Each is also a row in `settings`, which wins at runtime,
// so a manager can tune them without a deploy (see setting()).
define('TILL_VARIANCE_TOLERANCE',   200.00);  // cash over/short accepted without review
define('REFUND_APPROVAL_LIMIT',     5000.00); // above this a supervisor must approve
define('WASTAGE_APPROVAL_LIMIT',    10000.00);
define('DISCOUNT_MAX_PCT',          10.0);    // cashier ceiling; above needs override
define('PRICE_OVERRIDE_MAX_PCT',    15.0);    // how far off list price an override may go
define('EXPIRY_WARN_DAYS',          30);      // "expiring soon" horizon
define('NEGATIVE_STOCK_ALLOWED',    false);   // refuse to sell what the system says is absent
define('LOW_MARGIN_WARN_PCT',       5.0);     // gross margin below this is flagged
define('DEFAULT_REORDER_LEAD_DAYS', 7);
define('SLOW_MOVER_DAYS',           60);      // no sale in this many days = slow mover
define('LOYALTY_POINTS_PER_UNIT',   100.0);   // spend this much to earn 1 point
define('LOYALTY_POINT_VALUE',       1.0);     // 1 point redeems for this much

// ---- Receipts -------------------------------------------------------
define('RECEIPT_FOOTER', 'Thank you for shopping with us. Goods returnable within 7 days with receipt.');
define('RECEIPT_WIDTH_MM', 80);

// ---- Environment ----------------------------------------------------
/**
 * Debug output is derived from the host rather than hard-coded, because a
 * hard-coded `true` is the one that gets deployed. On localhost it stays on so
 * development is workable; anywhere else it is off, and a stack trace never
 * reaches a customer-facing till.
 *
 * Set TILLPOINT_DEBUG=1 in the environment to force it on temporarily.
 */
(function (): void {
    $host  = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host  = (string) preg_replace('/:\d+$/', '', $host);
    $local = PHP_SAPI === 'cli'
             || in_array($host, ['localhost', '127.0.0.1', '::1', ''], true)
             || str_ends_with($host, '.test')
             || str_ends_with($host, '.localhost');

    define('APP_DEBUG', getenv('TILLPOINT_DEBUG') === '1' ? true : $local);
    define('APP_IS_LOCAL', $local);
})();

/**
 * Whether the app is being served over HTTPS. Drives the Secure cookie flag and
 * HSTS. Detected rather than configured, including the proxy header, because a
 * shop behind a load balancer sees plain HTTP on the PHP side and would
 * otherwise silently drop the Secure flag.
 */
define('APP_HTTPS',
    (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
);

date_default_timezone_set(APP_TIMEZONE);

// Session hardening - must run before session_start().
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode',  '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly',  '1');
    // Strict is right here: nothing in this application is reached by following
    // a link from another site, so there is no flow a Strict cookie breaks, and
    // it closes the cross-site cases Lax still permits on a GET.
    ini_set('session.cookie_samesite',  'Strict');
    // Only over HTTPS - setting it on plain HTTP makes the session cookie
    // unsendable and locks everyone out of a local install.
    ini_set('session.cookie_secure', APP_HTTPS ? '1' : '0');
    ini_set('session.gc_maxlifetime', (string) (IDLE_TIMEOUT_MINUTES * 60));
    session_name(SESSION_NAME);
}

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
    // Off-screen but not lost: a till that fails silently with nothing in the
    // log is the worst of both.
    ini_set('log_errors', '1');
}
