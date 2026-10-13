<?php
/**
 * Not allowed.
 *
 * Says which permission was missing rather than a bare refusal: the user is a
 * member of staff, not an attacker, and "ask your manager for X" is actionable
 * where "access denied" sends them to the IT queue.
 */
declare(strict_types=1);
if (!defined('APP_ROOT')) {
    require_once __DIR__ . '/includes/bootstrap.php';
}
http_response_code(403);
$reason = (string) ($GLOBALS['__deny_reason'] ?? 'You do not have permission to open that page.');

if (!is_logged_in()) {
    flash_err('Please sign in first.');
    redirect('login');
}
layout_head(['title' => 'Not allowed']);
?>
<section class="card"><div class="card__body">
  <h2>You cannot open that page</h2>
  <p><?= e($reason) ?></p>
  <p class="small muted">
    Your role is <strong><?= e((string) (current_user()['role_name'] ?? 'unknown')) ?></strong>.
    Permissions are set per role, so if this is part of your job, a manager or administrator
    can grant it under Administration &rarr; Roles.
  </p>
  <div class="btn-row">
    <a class="btn" href="<?= e(base_url('')) ?>">Back to the dashboard</a>
  </div>
</div></section>
<?php layout_foot();
