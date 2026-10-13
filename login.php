<?php
/**
 * Sign-in.
 *
 * The failure messages here are deliberately uniform (see attempt_login): they
 * do not reveal whether an address exists, because a login form that does is an
 * account enumerator.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect('');
}

$email = '';
if (is_post()) {
    csrf_require();
    $email = strtolower((string) post('email'));
    [$ok, $message] = attempt_login($email, (string) ($_POST['password'] ?? ''));

    if ($ok) {
        flash_ok($message);
        $intended = $_SESSION['intended'] ?? null;
        unset($_SESSION['intended']);
        // Only follow a remembered destination inside this app, so a crafted
        // ?intended= cannot turn the login form into an open redirector.
        if (is_string($intended) && str_starts_with($intended, BASE_PATH) && !str_contains($intended, '//')) {
            redirect($intended);
        }
        redirect('');
    }
    flash_err($message);
}

$flashes = flash_take();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('css/app.css')) ?>">
<link rel="icon" href="<?= e(asset_url('img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body>
<div class="login-wrap">
  <form class="login" method="post" data-no-guard>
    <?= csrf_field() ?>
    <div class="login__brand">
      <?= brand_mark() ?>
      <div>
        <strong><?= e(SITE_NAME) ?></strong>
        <span><?= e(SITE_TAGLINE) ?></span>
      </div>
    </div>

    <?php foreach ($flashes as $f): ?>
      <div class="alert alert--<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <label class="field">
      <span class="field__label">Email address</span>
      <input type="email" name="email" required autocomplete="username"
             value="<?= e($email) ?>" data-autofocus>
    </label>
    <label class="field">
      <span class="field__label">Password</span>
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="btn btn--lg btn--block" type="submit">Sign in</button>

    <p class="login__hint">
      Tills sign out automatically after
      <?= (int) setting_num('idle_timeout_minutes', (float) IDLE_TIMEOUT_MINUTES) ?> minutes of inactivity.
      <?php if (!file_exists(APP_ROOT . '/.installed') && file_exists(APP_ROOT . '/install.php')): ?>
        <br>Not set up yet? Run the <a href="<?= e(base_url('install.php')) ?>">installer</a>.
      <?php endif; ?>
    </p>
  </form>
</div>
<script src="<?= e(asset_url('js/app.js')) ?>"></script>
</body>
</html>
