<?php
/**
 * Sign-in security.
 *
 * Shows what the throttles have been catching. The useful signal is rarely a
 * single failure - it is one address failing against many different accounts,
 * which is password spraying and which no per-account lockout would notice.
 * So the first table groups by address rather than listing attempts.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('admin.security');

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');

        if ($action === 'unlock') {
            $userId = post_int('user_id');
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
                'Locks cleared for ' . $u['full_name'] . ' from the security page',
                null, null, 'notice');
            flash_ok('Locks cleared for ' . $u['full_name'] . '.');
        }

        if ($action === 'prune') {
            $n = login_attempts_prune();
            audit_log('security.prune', 'login_attempt', null,
                "Pruned $n sign-in attempt record(s) past the retention period",
                null, null, 'notice');
            flash_ok($n === 0 ? 'Nothing was old enough to prune.' : "$n old record(s) pruned.");
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect('admin/security');
}

$window = (int) setting_num('login_ip_window_minutes', (float) LOGIN_IP_WINDOW_MINUTES);
$maxF   = (int) setting_num('login_ip_max_fails', (float) LOGIN_IP_MAX_FAILS);
$maxI   = (int) setting_num('login_ip_max_identities', (float) LOGIN_IP_MAX_IDENTITIES);
$days   = max(1, min(90, get_int('days', 7)));

// Grouped by address: a spray shows up here and nowhere else.
$byAddress = qa(
    'SELECT ip,
            COUNT(*) AS attempts,
            SUM(success = 0) AS failures,
            SUM(success = 1) AS successes,
            COUNT(DISTINCT identity) AS identities,
            COUNT(DISTINCT CASE WHEN success = 0 THEN identity END) AS failed_identities,
            MIN(created_at) AS first_seen,
            MAX(created_at) AS last_seen,
            GROUP_CONCAT(DISTINCT reason ORDER BY reason) AS reasons
       FROM login_attempts
      WHERE created_at >= (NOW() - INTERVAL ? DAY)
      GROUP BY ip
      ORDER BY failures DESC, identities DESC
      LIMIT 100',
    [$days]
);

// Currently throttled addresses, measured the same way login_ip_blocked() does.
$throttledNow = qa(
    'SELECT ip, COUNT(*) AS fails, COUNT(DISTINCT identity) AS identities
       FROM login_attempts
      WHERE success = 0 AND created_at >= (NOW() - INTERVAL ? MINUTE)
      GROUP BY ip
      HAVING fails >= ? OR identities >= ?',
    [$window, $maxF, $maxI]
);

$lockedAccounts = qa(
    'SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id
      WHERE (u.locked_until IS NOT NULL AND u.locked_until > NOW())
         OR (u.pin_locked_until IS NOT NULL AND u.pin_locked_until > NOW())
      ORDER BY u.locked_until DESC'
);

$recent = qa(
    'SELECT la.*, u.id AS user_id, u.full_name
       FROM login_attempts la
       LEFT JOIN users u ON u.email = la.identity
      WHERE la.created_at >= (NOW() - INTERVAL ? DAY)
      ORDER BY la.id DESC LIMIT 200',
    [$days]
);

$summary = q1(
    'SELECT COUNT(*) AS total, SUM(success = 1) AS ok, SUM(success = 0) AS failed,
            COUNT(DISTINCT ip) AS addresses, COUNT(DISTINCT identity) AS identities
       FROM login_attempts WHERE created_at >= (NOW() - INTERVAL ? DAY)',
    [$days]
);

// PIN attacks are recorded in the trail rather than login_attempts, because a
// PIN is not a sign-in.
$pinEvents = qa(
    "SELECT * FROM audit_log
      WHERE action IN ('pin.fail','pin.denied')
        AND created_at >= (NOW() - INTERVAL ? DAY)
      ORDER BY id DESC LIMIT 50",
    [$days]
);

$oldest = qv('SELECT MIN(created_at) FROM login_attempts');
$retention = (int) setting_num('login_attempt_retention_days', (float) LOGIN_ATTEMPT_RETENTION_DAYS);
$prunable = (int) qv('SELECT COUNT(*) FROM login_attempts
                       WHERE created_at < (NOW() - INTERVAL ? DAY)', [$retention], 0);

layout_head([
    'title'    => 'Sign-in security',
    'subtitle' => 'Last ' . $days . ' day(s)',
    'actions'  => '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<?php if ($throttledNow): ?>
  <div class="alert alert--error">
    <strong><?= count($throttledNow) ?></strong> address(es) are throttled right now.
    <?php foreach ($throttledNow as $t): ?>
      <br><code><?= e($t['ip']) ?></code> &mdash; <?= (int) $t['fails'] ?> failures against
      <?= (int) $t['identities'] ?> account(s) in the last <?= $window ?> minutes.
      <?php if ((int) $t['identities'] >= $maxI): ?>
        <strong>Many accounts, few attempts each &mdash; that pattern is password spraying.</strong>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">Period</span>
    <select name="days">
      <?= select_options([1 => 'Last 24 hours', 7 => 'Last 7 days',
                          14 => 'Last 14 days', 30 => 'Last 30 days'], $days) ?>
    </select>
  </label>
  <button class="btn" type="submit">Apply</button>
</form>

<div class="tiles">
  <?= tile('Sign-ins', number_format((int) $summary['ok']), 'successful') ?>
  <?= tile('Failures', number_format((int) $summary['failed']),
        'across ' . number_format((int) $summary['addresses']) . ' address(es)',
        (int) $summary['failed'] > 0 ? 'warn' : 'ok') ?>
  <?= tile('Locked out now', number_format(count($lockedAccounts)), 'accounts',
        $lockedAccounts ? 'bad' : 'ok') ?>
  <?= tile('Throttled now', number_format(count($throttledNow)), 'addresses',
        $throttledNow ? 'bad' : 'ok') ?>
  <?= tile('PIN refusals', number_format(count($pinEvents)),
        'wrong or unauthorised at a till',
        count($pinEvents) > 0 ? 'warn' : 'ok') ?>
  <?= tile('Thresholds', $maxF . ' / ' . $maxI,
        'failures / distinct accounts per ' . $window . ' min', 'info') ?>
</div>

<?php if ($lockedAccounts): ?>
<?= card_open('Locked accounts (' . count($lockedAccounts) . ')') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Staff no.</th><th>Name</th><th>Role</th><th class="num">Failures</th>
      <th>Sign-in locked until</th><th>PIN locked until</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($lockedAccounts as $u): ?>
      <tr class="row-warn">
        <td class="mono tiny"><?= e($u['staff_no']) ?></td>
        <td><a href="<?= e(base_url('admin/user', ['id' => (int) $u['id']])) ?>">
          <?= e($u['full_name']) ?></a></td>
        <td class="tiny"><?= e($u['role_name']) ?></td>
        <td class="num"><?= (int) $u['failed_attempts'] ?></td>
        <td class="tiny nowrap">
          <?= e($u['locked_until'] !== null ? date_fmt((string) $u['locked_until'], true) : '-') ?>
        </td>
        <td class="tiny nowrap">
          <?= e($u['pin_locked_until'] !== null ? date_fmt((string) $u['pin_locked_until'], true) : '-') ?>
        </td>
        <td class="right">
          <form method="post" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="unlock">
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <button class="btn btn--sm btn--ghost" type="submit">Clear locks</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="small muted mt1">
    A lock clears itself when it expires, so clearing one by hand is only for getting somebody
    back to work sooner. A PIN lock is worth asking about before clearing: repeated wrong PINs at
    a till is somebody standing at a terminal trying to authorise their own override.
  </p>
<?= card_close() ?>
<?php endif; ?>

<?= card_open('By address') ?>
  <?php if (!$byAddress): ?>
    <?= empty_state('No sign-in attempts in that period.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Address</th><th class="num">Attempts</th><th class="num">Failed</th>
        <th class="num">Succeeded</th><th class="num">Accounts tried</th>
        <th class="num">Accounts failed</th><th>First seen</th><th>Last seen</th><th>Pattern</th>
      </tr></thead>
      <tbody>
      <?php foreach ($byAddress as $a):
          $failed     = (int) $a['failures'];
          $identities = (int) $a['failed_identities'];
          // Many accounts with few attempts each is spraying; one account with
          // many attempts is a single password being guessed. They need
          // different responses, so they are labelled differently.
          $spray  = $identities >= 3 && $failed > 0 && ($failed / max(1, $identities)) < 4;
          $grind  = $identities === 1 && $failed >= 5;
          $throttled = $failed >= $maxF || $identities >= $maxI; ?>
        <tr class="<?= $spray ? 'row-bad' : ($grind || $throttled ? 'row-warn' : '') ?>">
          <td class="mono"><?= e($a['ip']) ?></td>
          <td class="num"><?= number_format((int) $a['attempts']) ?></td>
          <td class="num <?= $failed > 0 ? 'neg' : '' ?>"><?= number_format($failed) ?></td>
          <td class="num"><?= number_format((int) $a['successes']) ?></td>
          <td class="num"><?= number_format((int) $a['identities']) ?></td>
          <td class="num"><?= number_format($identities) ?></td>
          <td class="tiny nowrap"><?= e(date_fmt((string) $a['first_seen'], true)) ?></td>
          <td class="tiny nowrap"><?= e(ago((string) $a['last_seen'])) ?></td>
          <td class="tiny">
            <?php if ($spray): ?>
              <?= badge('Spraying', 'bad') ?>
              <br><span class="muted">many accounts, few tries each</span>
            <?php elseif ($grind): ?>
              <?= badge('Guessing', 'warn') ?>
              <br><span class="muted">one account, repeated tries</span>
            <?php elseif ($failed > 0): ?>
              <span class="muted">ordinary fumbling</span>
            <?php else: ?>
              <span class="ok-text">clean</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="small muted mt1">
      A whole shop usually sits behind one address, so a handful of failures from the same
      address is normal &mdash; that is why the thresholds are generous. What is not normal is one
      address failing against several different accounts: nobody fumbles four colleagues'
      passwords in a row.
    </p>
  <?php endif; ?>
<?= card_close() ?>

<?php if ($pinEvents): ?>
<?= card_open('Supervisor PIN refusals (' . count($pinEvents) . ')') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr><th>When</th><th>Account</th><th>What happened</th><th>Address</th><th>Severity</th></tr></thead>
    <tbody>
    <?php foreach ($pinEvents as $p): ?>
      <tr class="row-warn">
        <td class="nowrap tiny"><?= e(date_fmt((string) $p['created_at'], true)) ?></td>
        <td class="tiny">
          <?php if ($p['entity_id'] !== null): ?>
            <a href="<?= e(base_url('admin/user', ['id' => (int) $p['entity_id']])) ?>">
              <?= e((string) qv('SELECT full_name FROM users WHERE id = ?',
                    [(int) $p['entity_id']], 'unknown')) ?></a>
          <?php else: ?>
            <span class="muted">unknown staff number</span>
          <?php endif; ?>
        </td>
        <td class="small"><?= e($p['summary']) ?></td>
        <td class="mono tiny muted"><?= e($p['ip'] ?? '') ?></td>
        <td><?= severity_badge((string) $p['severity']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="small muted mt1">
    A PIN is not a sign-in, so these are recorded in the audit trail rather than the sign-in log.
    "PIN correct but role does not hold the permission" is the interesting one: the supervisor
    exists and typed their PIN correctly, but was trying to authorise something their role cannot
    do.
  </p>
<?= card_close() ?>
<?php endif; ?>

<?= card_open('Recent attempts') ?>
  <?php if (!$recent): ?>
    <?= empty_state('No attempts in that period.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>When</th><th>Email tried</th><th>Matched account</th><th>Address</th>
        <th>Result</th><th>Reason</th>
      </tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr class="<?= (int) $r['success'] === 0 ? 'row-warn' : '' ?>">
          <td class="nowrap tiny"><?= e(date_fmt((string) $r['created_at'], true)) ?></td>
          <td class="tiny"><?= e($r['identity']) ?></td>
          <td class="tiny">
            <?php if ($r['user_id'] !== null): ?>
              <a href="<?= e(base_url('admin/user', ['id' => (int) $r['user_id']])) ?>">
                <?= e((string) $r['full_name']) ?></a>
            <?php else: ?>
              <span class="muted">no such account</span>
            <?php endif; ?>
          </td>
          <td class="mono tiny"><?= e($r['ip']) ?></td>
          <td>
            <?= (int) $r['success'] === 1 ? badge('Signed in', 'ok') : badge('Failed', 'bad') ?>
          </td>
          <td class="mono tiny muted"><?= e($r['reason'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?= card_open('Retention') ?>
  <dl class="kv">
    <dt>Oldest record</dt>
    <dd><?= e($oldest !== null ? date_fmt((string) $oldest, true) : 'none') ?></dd>
    <dt>Retention period</dt><dd><?= $retention ?> days</dd>
    <dt>Past retention</dt><dd><?= number_format($prunable) ?> record(s)</dd>
  </dl>
  <p class="small muted">
    Pruning runs automatically from <code>cron/daily-scan.php</code>. The button is here for when
    the cron has not been scheduled yet.
  </p>
  <?php if ($prunable > 0): ?>
    <form method="post" data-confirm="Prune <?= number_format($prunable) ?> old sign-in record(s)?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="prune">
      <button class="btn btn--ghost" type="submit">Prune now</button>
    </form>
  <?php endif; ?>
<?= card_close() ?>

<?php layout_foot(); ?>
