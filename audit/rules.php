<?php
/**
 * Audit rule thresholds.
 *
 * Thresholds live in data so an auditor can tighten a rule without a deploy, and
 * so a report can state the threshold that was actually in force when a finding
 * was raised. Changing one is itself logged, because a rule quietly loosened is
 * a control quietly removed.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('audit.rules');

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');

        if ($action === 'save') {
            $changed = 0;
            db_tx(function () use (&$changed): void {
                foreach ((array) ($_POST['rule'] ?? []) as $code => $vals) {
                    $code = (string) $code;
                    $rule = q1('SELECT * FROM audit_rules WHERE code = ?', [$code]);
                    if (!$rule) {
                        continue;
                    }
                    $newThreshold = isset($vals['threshold']) && trim((string) $vals['threshold']) !== ''
                        ? num($vals['threshold'], (float) $rule['threshold'])
                        : null;
                    $newActive   = isset($vals['is_active']) ? 1 : 0;
                    $newSeverity = one_of($vals['severity'] ?? '',
                        ['low', 'medium', 'high', 'critical'], (string) $rule['severity']);

                    $oldThreshold = $rule['threshold'] !== null ? (float) $rule['threshold'] : null;
                    $thresholdMoved = ($oldThreshold === null) !== ($newThreshold === null)
                        || ($oldThreshold !== null && $newThreshold !== null
                            && abs($oldThreshold - $newThreshold) > 0.005);

                    if (!$thresholdMoved
                        && $newActive === (int) $rule['is_active']
                        && $newSeverity === (string) $rule['severity']) {
                        continue;
                    }

                    db_update('audit_rules', [
                        'threshold' => $newThreshold,
                        'is_active' => $newActive,
                        'severity'  => $newSeverity,
                    ], 'code = :c', ['c' => $code]);

                    // Loosening a threshold or switching a rule off is the change
                    // worth noticing, so the entry says which way it moved.
                    $loosened = $newActive === 0 && (int) $rule['is_active'] === 1;
                    if ($thresholdMoved && $oldThreshold !== null && $newThreshold !== null) {
                        $loosened = $loosened || $newThreshold > $oldThreshold;
                    }
                    audit_log('audit.rule_change', 'audit_rule', null,
                        sprintf('Rule %s: threshold %s to %s, %s, severity %s to %s',
                            $code,
                            $oldThreshold !== null ? (string) $oldThreshold : 'none',
                            $newThreshold !== null ? (string) $newThreshold : 'none',
                            $newActive === 1 ? 'active' : 'SWITCHED OFF',
                            $rule['severity'], $newSeverity),
                        ['threshold' => $oldThreshold, 'is_active' => (int) $rule['is_active'],
                         'severity' => $rule['severity']],
                        ['threshold' => $newThreshold, 'is_active' => $newActive,
                         'severity' => $newSeverity],
                        $loosened ? 'critical' : 'notice');
                    $changed++;
                }
            });
            flash_ok($changed === 0 ? 'Nothing changed.' : "$changed rule(s) updated and logged.");
        }

        if ($action === 'reset') {
            // Back to the shipped values from the code catalogue.
            $n = 0;
            db_tx(function () use (&$n): void {
                foreach (audit_rule_catalogue() as $code => $def) {
                    $rule = q1('SELECT * FROM audit_rules WHERE code = ?', [$code]);
                    if (!$rule) {
                        continue;
                    }
                    db_update('audit_rules', [
                        'threshold' => $def['threshold'],
                        'severity'  => $def['severity'],
                        'is_active' => 1,
                    ], 'code = :c', ['c' => $code]);
                    $n++;
                }
                audit_log('audit.rule_reset', 'audit_rule', null,
                    "All $n audit rule thresholds reset to the shipped defaults",
                    null, null, 'critical');
            });
            flash_ok("$n rules reset to the shipped defaults.");
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect('audit/rules');
}

$rules = qa(
    'SELECT ar.*,
            (SELECT COUNT(*) FROM audit_exceptions ae WHERE ae.rule_code = ar.code) AS raised,
            (SELECT COUNT(*) FROM audit_exceptions ae
              WHERE ae.rule_code = ar.code AND ae.status IN (\'open\',\'reviewing\')) AS open_now,
            (SELECT COUNT(*) FROM audit_exceptions ae
              WHERE ae.rule_code = ar.code AND ae.status = \'confirmed\') AS confirmed,
            (SELECT COUNT(*) FROM audit_exceptions ae
              WHERE ae.rule_code = ar.code AND ae.status = \'cleared\') AS cleared
       FROM audit_rules ar ORDER BY ar.module, ar.name'
);

$catalogue = audit_rule_catalogue();
$changes   = qa(
    'SELECT * FROM audit_log WHERE action IN (\'audit.rule_change\',\'audit.rule_reset\')
      ORDER BY id DESC LIMIT 20'
);

$byModule = [];
foreach ($rules as $r) {
    $byModule[(string) $r['module']][] = $r;
}
$offCount = count(array_filter($rules, fn ($r) => (int) $r['is_active'] === 0));

layout_head([
    'title'    => 'Audit rule thresholds',
    'subtitle' => count($rules) . ' rules, ' . ($offCount > 0 ? $offCount . ' switched off' : 'all active'),
    'actions'  => '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<?php if ($offCount > 0): ?>
  <div class="alert alert--warning">
    <strong><?= $offCount ?></strong> rule(s) are switched off and will not raise findings.
    A switched-off rule is a control that is not operating, whatever the policy says.
  </div>
<?php endif; ?>

<div class="alert alert--info">
  Changing a threshold here changes what the nightly scan looks for from the next run onwards.
  Existing findings keep the threshold that was in force when they were raised, so a report from
  last month still says what it was measured against. Every change is logged &mdash; loosening a
  threshold or switching a rule off is recorded at critical severity.
</div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <?php foreach ($byModule as $module => $moduleRules): ?>
    <?= card_open(ucfirst($module) . ' rules') ?>
      <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th>Rule</th><th>What it looks for</th><th>Kind</th>
          <th>Threshold</th><th>Severity</th><th>On</th>
          <th class="num">Raised</th><th class="num">Open</th>
          <th class="num">Confirmed</th><th class="num">Cleared</th>
        </tr></thead>
        <tbody>
        <?php foreach ($moduleRules as $r):
            $code = (string) $r['code'];
            $kind = $catalogue[$code]['kind'] ?? 'scan';
            // A rule that has only ever been cleared may be set too tight; one
            // that has been confirmed is earning its place. Both are worth
            // seeing next to the threshold you are about to change.
            $precision = (int) $r['raised'] > 0
                ? ((int) $r['confirmed'] / (int) $r['raised']) * 100 : null; ?>
          <tr class="<?= (int) $r['is_active'] === 0 ? 'row-warn' : '' ?>">
            <td>
              <strong><?= e($r['name']) ?></strong>
              <br><span class="tiny muted mono"><?= e($code) ?></span>
            </td>
            <td class="small"><?= e($r['description']) ?></td>
            <td class="tiny">
              <?= $kind === 'inline'
                    ? badge('At the moment it happens', 'info')
                    : badge('Daily scan', 'neutral') ?>
            </td>
            <td>
              <?php if ($r['threshold'] !== null): ?>
                <input type="text" name="rule[<?= e($code) ?>][threshold]" class="num-in"
                       style="max-width:104px" inputmode="decimal"
                       value="<?= e(rtrim(rtrim(number_format((float) $r['threshold'], 2, '.', ''), '0'), '.')) ?>">
                <br><span class="tiny muted"><?= e($r['threshold_label'] ?? '') ?></span>
              <?php else: ?>
                <span class="tiny muted">no threshold &mdash; fires on the event itself</span>
              <?php endif; ?>
            </td>
            <td>
              <select name="rule[<?= e($code) ?>][severity]" style="max-width:112px">
                <?= select_options(['low' => 'Low', 'medium' => 'Medium',
                                    'high' => 'High', 'critical' => 'Critical'],
                                   (string) $r['severity']) ?>
              </select>
            </td>
            <td class="center">
              <input type="checkbox" name="rule[<?= e($code) ?>][is_active]" value="1"
                     <?= (int) $r['is_active'] === 1 ? 'checked' : '' ?>
                     aria-label="Rule active">
            </td>
            <td class="num"><?= number_format((int) $r['raised']) ?></td>
            <td class="num <?= (int) $r['open_now'] > 0 ? 'neg' : '' ?>">
              <?= number_format((int) $r['open_now']) ?>
            </td>
            <td class="num"><?= number_format((int) $r['confirmed']) ?></td>
            <td class="num">
              <?= number_format((int) $r['cleared']) ?>
              <?php if ($precision !== null && (int) $r['raised'] >= 5 && $precision < 10): ?>
                <br><span class="tiny warn-text">mostly cleared</span>
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
    <button class="btn btn--lg" type="submit">Save thresholds</button>
  </div>
</form>

<?= card_open('Tuning these well') ?>
  <div class="grid-2">
    <div>
      <h3>A rule that is mostly cleared is too tight</h3>
      <p class="small">
        Look at the right-hand columns. A rule that has raised forty findings and had every one
        cleared is not detecting anything &mdash; it is generating work, and worse, it is training
        whoever reviews the queue to clear things without looking. Raise its threshold until it
        fires on cases that turn out to matter.
      </p>
      <p class="small">
        A rule with confirmations against it is earning its place. Tighten those if anything.
      </p>
    </div>
    <div>
      <h3>Switching a rule off</h3>
      <p class="small">
        Sometimes right &mdash; a shop with one till will trip <code>REFUND_OWN_SALE</code> every
        day, and that tells nobody anything. But a switched-off rule is a control that is not
        operating, so it is logged at critical severity and shown in amber above.
      </p>
      <p class="small muted">
        Prefer raising a threshold to switching a rule off: a rule that fires rarely still catches
        the extreme case, whereas one that is off catches nothing.
      </p>
    </div>
  </div>
<?= card_close() ?>

<?php if ($changes): ?>
<?= card_open('Threshold changes') ?>
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

<?= card_open('Reset to shipped defaults') ?>
  <form method="post"
        data-confirm="Reset every rule to its shipped threshold and switch them all back on?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset">
    <p class="small muted">
      Puts every threshold and severity back to the values in the code catalogue and switches all
      rules on. Useful after experimenting. Logged at critical severity.
    </p>
    <button class="btn btn--danger" type="submit">Reset all rules</button>
  </form>
<?= card_close() ?>

<?php layout_foot(); ?>
