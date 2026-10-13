<?php
/**
 * Login throttling.
 *
 * Two independent brakes, because they stop different attacks:
 *   - the per-account lockout in auth.php stops someone grinding one password;
 *   - the per-IP rules here stop one address trying a few common passwords
 *     against many accounts in turn, which no per-account counter would notice.
 *
 * Thresholds are deliberately generous: a whole store sits behind one NAT
 * address, so a tight limit would lock out a shift because one cashier fumbled.
 */
declare(strict_types=1);

function login_attempt_record(string $identity, bool $success, string $reason = ''): void
{
    try {
        db_insert('login_attempts', [
            'identity'   => substr($identity, 0, 160),
            'ip'         => client_ip(),
            'user_agent' => client_agent(),
            'success'    => $success ? 1 : 0,
            'reason'     => $reason !== '' ? substr($reason, 0, 60) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[TillPoint rate_limit] ' . $e->getMessage());
    }
}

/**
 * Is this address currently throttled? Returns null when not, otherwise the
 * message to show. Only failures count, and only inside the sliding window.
 */
function login_ip_blocked(): ?string
{
    $window = (int) setting_num('login_ip_window_minutes', (float) LOGIN_IP_WINDOW_MINUTES);
    $maxF   = (int) setting_num('login_ip_max_fails', (float) LOGIN_IP_MAX_FAILS);
    $maxI   = (int) setting_num('login_ip_max_identities', (float) LOGIN_IP_MAX_IDENTITIES);

    $row = q1(
        'SELECT COUNT(*) AS fails, COUNT(DISTINCT identity) AS identities
           FROM login_attempts
          WHERE ip = ? AND success = 0
            AND created_at >= (NOW() - INTERVAL ? MINUTE)',
        [client_ip(), $window]
    );
    $fails      = (int) ($row['fails'] ?? 0);
    $identities = (int) ($row['identities'] ?? 0);

    if ($fails >= $maxF) {
        return "Too many failed sign-in attempts from this location. Try again in $window minutes.";
    }
    if ($identities >= $maxI) {
        // Many accounts, few attempts each: password spraying. Say as little as
        // possible about what tripped it.
        return "Sign-in from this location is temporarily blocked. Try again in $window minutes.";
    }
    return null;
}

/**
 * A sliding-window throttle for an authenticated endpoint.
 *
 * Kept in the session rather than a table: the endpoints this guards are all
 * session-authenticated, so there is no unauthenticated caller to track, and a
 * row per product lookup would write more than the lookup reads.
 *
 * Returns true when the call is allowed. The caller decides what a refusal
 * means - the till's lookup degrades to "try again", it does not stop trading.
 */
function throttle(string $bucket, int $maxCalls, int $windowSeconds): bool
{
    $now  = time();
    $key  = '__throttle_' . $bucket;
    $hits = $_SESSION[$key] ?? [];

    // Drop anything outside the window, then decide.
    $hits = array_values(array_filter(
        is_array($hits) ? $hits : [],
        fn ($t) => is_int($t) && $t > $now - $windowSeconds
    ));

    if (count($hits) >= $maxCalls) {
        $_SESSION[$key] = $hits;
        return false;
    }
    $hits[] = $now;
    $_SESSION[$key] = $hits;
    return true;
}

/** Housekeeping, called from the daily cron. */
function login_attempts_prune(): int
{
    $days = (int) setting_num('login_attempt_retention_days', (float) LOGIN_ATTEMPT_RETENTION_DAYS);
    return q('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL ? DAY)', [$days])->rowCount();
}
