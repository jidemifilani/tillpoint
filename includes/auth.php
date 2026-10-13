<?php
/**
 * Sign-in, session lifetime, store context, and supervisor PIN overrides.
 */
declare(strict_types=1);

function current_user(): ?array
{
    static $cache = null;
    static $loadedFor = null;
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if ($uid === 0) {
        return null;
    }
    if ($cache !== null && $loadedFor === $uid) {
        return $cache;
    }
    $loadedFor = $uid;
    return $cache = q1(
        'SELECT u.*, r.code AS role_code, r.name AS role_name, s.name AS store_name, s.code AS store_code
           FROM users u
           JOIN roles r ON r.id = u.role_id
           LEFT JOIN stores s ON s.id = u.store_id
          WHERE u.id = ? AND u.is_active = 1',
        [$uid]
    );
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect('login');
    }
}

/**
 * The store the user is working in.
 *
 * Head-office staff and auditors have no home store and may switch; store staff
 * are pinned to theirs. Everything that writes stock or cash reads the store
 * from here rather than from a form field, so a request cannot post into a store
 * the user has no business in by editing a hidden input.
 */
function current_store_id(): ?int
{
    $u = current_user();
    if (!$u) {
        return null;
    }
    if ($u['store_id'] !== null) {
        return (int) $u['store_id'];
    }
    $chosen = (int) ($_SESSION['store_id'] ?? 0);
    if ($chosen > 0 && user_may_use_store($chosen)) {
        return $chosen;
    }
    // No choice made yet: fall back to the first active store so dashboards and
    // reports have something to show rather than erroring.
    $first = qv('SELECT id FROM stores WHERE is_active = 1 ORDER BY id LIMIT 1');
    return $first !== null ? (int) $first : null;
}

function current_store(): ?array
{
    $id = current_store_id();
    return $id ? q1('SELECT * FROM stores WHERE id = ?', [$id]) : null;
}

/** Stores this user may act in: their own, any extras, or all if unpinned. */
function my_stores(): array
{
    $u = current_user();
    if (!$u) {
        return [];
    }
    if ($u['store_id'] === null) {
        return qa('SELECT * FROM stores WHERE is_active = 1 ORDER BY name');
    }
    return qa(
        'SELECT * FROM stores
          WHERE is_active = 1
            AND (id = ? OR id IN (SELECT store_id FROM user_stores WHERE user_id = ?))
          ORDER BY name',
        [(int) $u['store_id'], (int) $u['id']]
    );
}

function user_may_use_store(int $storeId): bool
{
    foreach (my_stores() as $s) {
        if ((int) $s['id'] === $storeId) {
            return true;
        }
    }
    return false;
}

function switch_store(int $storeId): bool
{
    if (!user_may_use_store($storeId)) {
        return false;
    }
    $_SESSION['store_id'] = $storeId;
    return true;
}

/* ------------------------------------------------------------------ */
/* Sign in / out                                                      */
/* ------------------------------------------------------------------ */

/**
 * Attempt a sign-in. Returns [ok, message].
 *
 * The failure messages are deliberately uniform for "no such account" and
 * "wrong password": distinguishing them turns the login form into an account
 * enumerator. A lockout is the one case worth naming, because the user needs to
 * know waiting will help.
 */
function attempt_login(string $email, string $password): array
{
    $email = strtolower(trim($email));

    if ($blocked = login_ip_blocked()) {
        login_attempt_record($email, false, 'ip_blocked');
        return [false, $blocked];
    }

    $u = q1('SELECT * FROM users WHERE email = ?', [$email]);

    if (!$u) {
        login_attempt_record($email, false, 'no_account');
        // Spend roughly the time a real verification would, so response timing
        // does not reveal whether the address exists.
        password_verify($password, '$2y$12$usesomesillystringfaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        return [false, 'Those sign-in details were not recognised.'];
    }

    if ((int) $u['is_active'] !== 1) {
        login_attempt_record($email, false, 'inactive');
        return [false, 'That account has been deactivated. Speak to your manager.'];
    }

    if ($u['locked_until'] !== null && strtotime((string) $u['locked_until']) > time()) {
        $mins = max(1, (int) ceil((strtotime((string) $u['locked_until']) - time()) / 60));
        login_attempt_record($email, false, 'locked');
        return [false, "This account is locked for another $mins minute(s) after repeated failed attempts."];
    }

    if (!password_verify($password, (string) $u['password_hash'])) {
        $attempts = (int) $u['failed_attempts'] + 1;
        $max      = (int) setting_num('max_login_attempts', (float) MAX_LOGIN_ATTEMPTS);
        $data     = ['failed_attempts' => $attempts];
        if ($attempts >= $max) {
            $lock = (int) setting_num('lockout_minutes', (float) LOCKOUT_MINUTES);
            $data['locked_until']    = date('Y-m-d H:i:s', time() + $lock * 60);
            $data['failed_attempts'] = 0;
        }
        db_update('users', $data, 'id = :id', ['id' => (int) $u['id']]);
        login_attempt_record($email, false, 'bad_password');

        if (isset($data['locked_until'])) {
            audit_log('auth.locked', 'user', (int) $u['id'],
                'Account locked after ' . $max . ' failed sign-in attempts', null, null, 'warning');
            return [false, 'Too many failed attempts. This account is now locked for '
                . (int) setting_num('lockout_minutes', (float) LOCKOUT_MINUTES) . ' minutes.'];
        }
        $left = $max - $attempts;
        return [false, 'Those sign-in details were not recognised.'
            . ($left <= 2 ? " $left attempt(s) left before the account locks." : '')];
    }

    // Success. Rotate the session id so a fixated id cannot be reused.
    session_regenerate_id(true);
    $_SESSION['uid']        = (int) $u['id'];
    $_SESSION['user_name']  = $u['full_name'];
    $_SESSION['last_seen']  = time();
    unset($_SESSION['store_id']);

    $role = q1('SELECT code FROM roles WHERE id = ?', [(int) $u['role_id']]);
    $_SESSION['role_code'] = $role['code'] ?? '';

    db_update('users', [
        'failed_attempts' => 0,
        'locked_until'    => null,
        'last_login_at'   => date('Y-m-d H:i:s'),
    ], 'id = :id', ['id' => (int) $u['id']]);

    login_attempt_record($email, true, 'ok');
    audit_log('auth.login', 'user', (int) $u['id'], 'Signed in', null, null, 'info',
        $u['store_id'] !== null ? (int) $u['store_id'] : null);

    if ((int) $u['must_change_password'] === 1) {
        $_SESSION['must_change_password'] = true;
    }
    return [true, 'Welcome back, ' . $u['full_name'] . '.'];
}

function logout(): void
{
    if (is_logged_in()) {
        audit_log('auth.logout', 'user', (int) $_SESSION['uid'], 'Signed out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}

/**
 * End an idle session.
 *
 * A till left signed in on the shop floor is a real exposure: anyone can refund
 * to their own card. The timeout is short for that reason, and the reason is
 * carried over to the login page so the user is not left guessing.
 */
function enforce_idle_timeout(): void
{
    if (!isset($_SESSION['uid'])) {
        return;
    }
    $limit = (int) setting_num('idle_timeout_minutes', (float) IDLE_TIMEOUT_MINUTES) * 60;
    $last  = (int) ($_SESSION['last_seen'] ?? time());
    if (time() - $last > $limit) {
        audit_log('auth.timeout', 'user', (int) $_SESSION['uid'], 'Session ended after inactivity');
        $_SESSION = [];
        session_destroy();
        session_start();
        flash_warn('You were signed out after a period of inactivity.');
        redirect('login');
    }
    $_SESSION['last_seen'] = time();
}

function password_problem(string $pw): ?string
{
    $min = (int) setting_num('password_min_length', (float) PASSWORD_MIN_LENGTH);
    if (strlen($pw) < $min) {
        return "Password must be at least $min characters.";
    }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        return 'Password must contain at least one letter and one digit.';
    }
    return null;
}

function hash_password(string $pw): string
{
    return password_hash($pw, PASSWORD_ALGO);
}

/* ------------------------------------------------------------------ */
/* Supervisor PIN overrides                                           */
/* ------------------------------------------------------------------ */

/**
 * Authorise one privileged action at a till with a supervisor's PIN.
 *
 * A cashier stays signed in for a whole shift; swapping sessions to approve a
 * price change would be unworkable at a queue, so a supervisor types a PIN
 * instead. Three things make that safe rather than convenient:
 *
 *   - the PIN authorises exactly one action and grants no session;
 *   - the supervisor must independently hold the permission being exercised,
 *     so a PIN cannot do what its owner's role cannot;
 *   - who authorised it is written onto the line and into the trail, so the
 *     override is attributable afterwards.
 *
 * Returns [ok, message, supervisorUserId].
 */
function pin_authorise(string $staffNo, string $pin, string $permission): array
{
    $staffNo = trim($staffNo);
    if ($staffNo === '' || $pin === '') {
        return [false, 'A supervisor staff number and PIN are required.', null];
    }

    $u = q1(
        'SELECT u.*, r.code AS role_code FROM users u
           JOIN roles r ON r.id = u.role_id
          WHERE u.staff_no = ? AND u.is_active = 1',
        [$staffNo]
    );
    if (!$u || $u['pin_hash'] === null) {
        audit_log('pin.fail', 'user', $u ? (int) $u['id'] : null,
            "PIN override refused for $permission: unknown staff number or no PIN set", null, null, 'warning');
        return [false, 'That staff number and PIN were not accepted.', null];
    }

    if ($u['pin_locked_until'] !== null && strtotime((string) $u['pin_locked_until']) > time()) {
        return [false, 'That supervisor\'s PIN is temporarily locked.', null];
    }

    if (!password_verify($pin, (string) $u['pin_hash'])) {
        $n    = (int) $u['pin_failed_attempts'] + 1;
        $data = ['pin_failed_attempts' => $n];
        if ($n >= PIN_MAX_ATTEMPTS) {
            $data['pin_locked_until']    = date('Y-m-d H:i:s', time() + PIN_LOCKOUT_MINUTES * 60);
            $data['pin_failed_attempts'] = 0;
        }
        db_update('users', $data, 'id = :id', ['id' => (int) $u['id']]);
        audit_log('pin.fail', 'user', (int) $u['id'],
            "Incorrect PIN for $permission override (attempt $n)", null, null, 'warning');

        // Someone standing at a till trying PINs is worth a look even if they
        // never succeed, so it is an exception in its own right.
        if ($n >= PIN_MAX_ATTEMPTS) {
            audit_raise_exception('PIN_BRUTE_FORCE', [
                'subject_user' => (int) $u['id'],
                'entity'       => 'user',
                'entity_id'    => (int) $u['id'],
                'metric'       => $n,
                'summary'      => 'Supervisor PIN locked after ' . $n . ' failed attempts at a till',
            ]);
        }
        return [false, 'That staff number and PIN were not accepted.', null];
    }

    // Correct PIN. Does its owner actually hold the permission?
    $has = (int) qv(
        'SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_code = ?',
        [(int) $u['role_id'], $permission], 0
    );
    if ($has === 0) {
        audit_log('pin.denied', 'user', (int) $u['id'],
            "PIN correct but {$u['role_code']} does not hold $permission", null, null, 'warning');
        return [false, 'That supervisor is not authorised for this action.', null];
    }

    if ((int) $u['pin_failed_attempts'] !== 0) {
        db_update('users', ['pin_failed_attempts' => 0, 'pin_locked_until' => null],
            'id = :id', ['id' => (int) $u['id']]);
    }
    audit_log('pin.ok', 'user', (int) $u['id'],
        "Authorised $permission by PIN at a till", null, null, 'notice');

    return [true, 'Authorised by ' . $u['full_name'] . '.', (int) $u['id']];
}

function pin_problem(string $pin): ?string
{
    if (!preg_match('/^\d{' . PIN_LENGTH . '}$/', $pin)) {
        return 'The PIN must be exactly ' . PIN_LENGTH . ' digits.';
    }
    // Sequences and repeats are the PINs people pick when the PIN is the only
    // thing standing between a cashier and an unlimited discount.
    if (preg_match('/^(\d)\1+$/', $pin)) {
        return 'That PIN is too easy to guess - do not use the same digit repeated.';
    }
    if (in_array($pin, ['1234', '4321', '0123', '1111', '2580'], true)) {
        return 'That PIN is too common. Choose another.';
    }
    return null;
}
