<?php
/**
 * Single entry point for every page.
 *
 * Order matters: config.php sets session_name() and the session.* ini values,
 * and PHP refuses those once a session is active - so config ALWAYS loads before
 * session_start().
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/numbering.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/stock_docs.php';
require_once __DIR__ . '/promotions.php';
require_once __DIR__ . '/pos.php';
// Loaded unconditionally and called without function_exists() guards. These are
// the controls that stop a cashier approving their own refund, and a control
// that fails open because a file was not included is worse than no control.
require_once __DIR__ . '/audit_rules.php';
require_once __DIR__ . '/invoices.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/layout.php';

/**
 * A per-request nonce for the few inline styles the pages need.
 *
 * The progress bars and spark charts set their width from data, which cannot be
 * done from a static stylesheet. Rather than keep 'unsafe-inline' in the policy
 * - which would also permit any style an injection managed to get onto the page
 * - each inline style carries this nonce, and nothing else is allowed.
 */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/** Emit a nonce attribute for an inline <style> block. */
function style_nonce_attr(): string
{
    return ' nonce="' . e(csp_nonce()) . '"';
}

// Baseline security headers.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 0');   // the legacy auditor is harmful; CSP is the control
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

    // style-src keeps 'unsafe-inline' alongside the nonce on purpose: the data
    // -driven width="N%" attributes on bars and sparklines are inline STYLE
    // ATTRIBUTES, which a nonce cannot cover - only 'unsafe-inline' or
    // 'unsafe-hashes' permits those. script-src takes no such exception, which
    // is the half that actually matters: there is no inline script anywhere.
    header("Content-Security-Policy: default-src 'self'; "
         . "base-uri 'self'; "
         . "object-src 'none'; "
         . "img-src 'self' data:; "
         . "style-src 'self' 'nonce-" . csp_nonce() . "' 'unsafe-inline'; "
         . "script-src 'self'; "
         . "form-action 'self'; "
         . "frame-ancestors 'self'");

    if (APP_HTTPS) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

enforce_idle_timeout();

// A user whose password was reset by an administrator must change it before
// doing anything else. Checked here rather than per page so no route misses it.
if (isset($_SESSION['must_change_password']) && $_SESSION['must_change_password'] === true) {
    $here = basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
    if (!in_array($here, ['profile', 'profile.php', 'logout', 'logout.php'], true)) {
        flash_warn('Please choose a new password before continuing.');
        redirect('profile');
    }
}
