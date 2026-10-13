<?php
/**
 * System settings.
 *
 * These are the thresholds the controls actually use. The constants in
 * config.php are only the values the system ships with; once a row exists here
 * it wins everywhere, because every caller reads through setting_num() rather
 * than the constant.
 *
 * Changing one is logged, and loosening a control is logged at critical
 * severity. A refund limit quietly raised from 5,000 to 500,000 removes the
 * second pair of eyes from every refund in the shop, and that should not be
 * something only the person who did it knows about.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('admin.settings');

/**
 * Settings where a larger number means a weaker control. Used to decide the
 * severity of the trail entry: for these, up is loosening; for the rest
 * (password length, lockout duration) up is tightening.
 */
function setting_higher_is_looser(string $key): bool
{
    return in_array($key, [
        'till_variance_tolerance', 'refund_approval_limit', 'discount_max_pct',
        'price_override_max_pct', 'wastage_approval_limit', 'shrinkage_exception_value',
        'adjustment_exception_value', 'login_ip_max_fails', 'login_ip_max_identities',
        'max_login_attempts', 'idle_timeout_minutes', 'stale_count_min_value',
    ], true);
}

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');

        if ($action === 'save') {
            $changed = 0;
            $loosened = [];

            db_tx(function () use (&$changed, &$loosened): void {
                foreach ((array) ($_POST['setting'] ?? []) as $key => $raw) {
                    $key = (string) $key;
                    $row = q1('SELECT * FROM settings WHERE skey = ?', [$key]);
                    if (!$row) {
                        continue;   // unknown key: ignore rather than create
                    }

                    // Booleans arrive as a present-or-absent checkbox; everything
                    // else as typed text.
                    $new = $row['value_type'] === 'bool'
                        ? ((string) $raw === '1' ? '1' : '0')
                        : trim((string) $raw);

                    if ($row['value_type'] === 'bool' && !isset($_POST['setting'][$key])) {
                        $new = '0';
                    }

                    // Validate against the declared type, so a typo cannot turn a
                    // numeric threshold into zero and switch a control off.
                    if (in_array($row['value_type'], ['int', 'decimal'], true)) {
                        $clean = str_replace([',', ' '], '', $new);
                        if ($clean === '' || !is_numeric($clean)) {
                            throw new RuntimeException(
                                '"' . $row['label'] . '" must be a number. Nothing was saved.'
                            );
                        }
                        if ((float) $clean < 0) {
                            throw new RuntimeException(
                                '"' . $row['label'] . '" cannot be negative. Nothing was saved.'
                            );
                        }
                        $new = $row['value_type'] === 'int'
                            ? (string) (int) $clean
                            : (string) (float) $clean;
                    }

                    if ((string) $row['svalue'] === $new) {
                        continue;
                    }

                    db_update('settings', [
                        'svalue'     => $new,
                        'updated_by' => (int) $_SESSION['uid'],
                    ], 'skey = :k', ['k' => $key]);

                    $wasLoosened = false;
                    if (in_array($row['value_type'], ['int', 'decimal'], true)) {
                        $up = (float) $new > (float) $row['svalue'];
                        $wasLoosened = setting_higher_is_looser($key) ? $up : !$up;
                    } elseif ($row['value_type'] === 'bool') {
                        // negative_stock_allowed is the only bool here, and
                        // switching it on is a loosening.
                        $wasLoosened = $new === '1' && $key === 'negative_stock_allowed';
                    }

                    audit_log('setting.change', 'setting', null,
                        sprintf('%s changed from "%s" to "%s"%s',
                            $row['label'], (string) $row['svalue'], $new,
                            $wasLoosened ? ' - this WEAKENS a control' : ''),
                        ['key' => $key, 'value' => $row['svalue']],
                        ['key' => $key, 'value' => $new],
                        $wasLoosened ? 'critical' : 'notice');

                    if ($wasLoosened) {
                        $loosened[] = $row['label'];
                    }
                    $changed++;
                }
            });

            if ($changed === 0) {
                flash_info('Nothing changed.');
            } else {
                flash_ok("$changed setting(s) updated and logged.");
            }
            foreach ($loosened as $label) {
                flash_warn('"' . $label . '" now permits more than it did. Recorded at critical '
                    . 'severity in the audit trail.');
            }
            redirect('admin/settings');
        }

        if ($action === 'reset') {
            // Back to what the application ships with, from seed_settings().
            require_once APP_ROOT . '/includes/seed.php';
            db_tx(function (): void {
                q('DELETE FROM settings');
                seed_settings();
                audit_log('setting.reset', 'setting', null,
                    'Every setting reset to the shipped default', null, null, 'critical');
            });
            flash_ok('All settings reset to the values the application ships with.');
            redirect('admin/settings');
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('admin/settings');
    }
}

$rows = qa(
    'SELECT s.*, u.full_name AS updated_by_name FROM settings s
       LEFT JOIN users u ON u.id = s.updated_by
      ORDER BY s.category, s.label'
);

$byCategory = [];
foreach ($rows as $r) {
    $byCategory[(string) $r['category']][] = $r;
}

$categoryBlurb = [
    'security' => 'Sign-in throttling, lockouts and session lifetime. Loosening anything here '
                . 'widens the window for someone guessing at a password.',
    'till'     => 'What a cashier may do alone, and what needs a supervisor. These are the '
                . 'numbers the exception rules measure behaviour against.',
    'stock'    => 'Approval limits and the thresholds that decide when a stock movement becomes '
                . 'an audit finding.',
    'loyalty'  => 'How points are earned and what they are worth when redeemed. Both affect a '
                . 'real liability on the balance sheet.',
    'receipt'  => 'What appears on printed documents.',
    'general'  => 'Everything else.',
];

$changes = qa(
    "SELECT * FROM audit_log WHERE action IN ('setting.change','setting.reset')
      ORDER BY id DESC LIMIT 25"
);

layout_head([
    'title'    => 'Settings',
    'subtitle' => count($rows) . ' tunable values',
    'actions'  => '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<div class="alert alert--info">
  A value set here overrides the constant in <code>config/config.php</code> everywhere in the
  application, immediately. The constants are only the defaults the system ships with, which is
  why tuning a threshold does not need a deploy &mdash; and why every change is recorded.
</div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <?php foreach ($byCategory as $category => $settings): ?>
    <?= card_open(ucfirst($category)) ?>
      <?php if (isset($categoryBlurb[$category])): ?>
        <p class="small muted"><?= e($categoryBlurb[$category]) ?></p>
      <?php endif; ?>
      <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th>Setting</th><th>Value</th><th>Key</th><th>Last changed</th>
        </tr></thead>
        <tbody>
        <?php foreach ($settings as $s):
            $key    = (string) $s['skey'];
            $value  = (string) ($s['svalue'] ?? '');
            $looser = setting_higher_is_looser($key); ?>
          <tr>
            <td>
              <strong><?= e($s['label']) ?></strong>
              <?php if ($looser): ?>
                <br><span class="tiny muted">a higher number permits more</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($s['value_type'] === 'bool'): ?>
                <label class="check" style="margin:0">
                  <input type="checkbox" name="setting[<?= e($key) ?>]" value="1"
                         <?= setting_cast($value, 'bool') ? 'checked' : '' ?>>
                  <span class="small">
                    <?= $key === 'negative_stock_allowed'
                          ? 'Allowed &mdash; the till will sell what the system says is absent'
                          : 'Enabled' ?>
                  </span>
                </label>
              <?php elseif (in_array($s['value_type'], ['int', 'decimal'], true)): ?>
                <input type="text" name="setting[<?= e($key) ?>]" class="num-in"
                       style="max-width:140px" inputmode="decimal" value="<?= e($value) ?>">
              <?php else: ?>
                <input type="text" name="setting[<?= e($key) ?>]"
                       style="max-width:420px" maxlength="500" value="<?= e($value) ?>">
              <?php endif; ?>
            </td>
            <td class="mono tiny muted"><?= e($key) ?></td>
            <td class="tiny">
              <?php if ($s['updated_by_name'] !== null): ?>
                <?= e((string) $s['updated_by_name']) ?><br>
                <span class="muted"><?= e(ago((string) $s['updated_at'])) ?></span>
              <?php else: ?>
                <span class="muted">shipped default</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?= card_close() ?>
  <?php endforeach; ?>

  <div class="btn-row mb1 no-print">
    <button class="btn btn--lg" type="submit">Save settings</button>
  </div>
</form>

<?= card_open('A note on the till thresholds') ?>
  <div class="grid-2">
    <div>
      <h3>Set them to what the shop actually does</h3>
      <p class="small">
        A cashier discount ceiling of 10% is only meaningful if 10% is more than the shop normally
        gives. Set too high, it authorises everything and the <code>EXCESSIVE_DISCOUNTS</code>
        rule never fires; set too low, every basket needs a supervisor and the supervisor starts
        handing out their PIN.
      </p>
      <p class="small">
        The same applies to the till variance tolerance. Too tight and every shift lands in the
        audit queue, which trains whoever reviews it to clear things without reading. Too loose
        and a persistent shortfall hides inside it.
      </p>
    </div>
    <div>
      <h3>Changing these changes what gets detected</h3>
      <p class="small">
        Raising the refund limit does not just reduce paperwork &mdash; it removes the second
        person from every refund underneath the new figure. Raising the wastage limit does the
        same for write-offs.
      </p>
      <p class="small">
        That is why a loosening is logged at critical severity and shows in the
        <a href="<?= e(base_url('audit/trail', ['action_like' => 'setting'])) ?>">trail</a> with
        the before and after values. The audit rule thresholds themselves are separate, under
        <a href="<?= e(base_url('audit/rules')) ?>">Audit &rarr; Rule thresholds</a>.
      </p>
    </div>
  </div>
<?= card_close() ?>

<?php if ($changes): ?>
<?= card_open('Recent setting changes') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr><th>When</th><th>Who</th><th>Change</th><th>Severity</th></tr></thead>
    <tbody>
    <?php foreach ($changes as $c): ?>
      <tr class="<?= $c['severity'] === 'critical' ? 'row-warn' : '' ?>">
        <td class="nowrap tiny"><?= e(date_fmt((string) $c['created_at'], true)) ?></td>
        <td class="tiny"><?= e($c['user_label'] ?? 'system') ?></td>
        <td class="small"><?= e($c['summary']) ?></td>
        <td><?= severity_badge((string) $c['severity']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?= card_open('Reset everything') ?>
  <form method="post"
        data-confirm="Reset every setting to the value the application ships with?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset">
    <p class="small muted">
      Discards every tuned value and restores the defaults from <code>config/config.php</code>.
      Logged at critical severity.
    </p>
    <button class="btn btn--danger" type="submit">Reset all settings</button>
  </form>
<?= card_close() ?>

<?php layout_foot(); ?>
