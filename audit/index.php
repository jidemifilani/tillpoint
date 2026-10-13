<?php
/**
 * The audit control centre.
 *
 * Opens with the one thing that determines whether anything else here is worth
 * reading: does the audit trail still verify? If the hash chain is broken, every
 * figure below it could have been edited, and saying so first is the honest
 * order to present it in.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('audit.view');

$storeId = current_store_id();

if (is_post()) {
    csrf_require();
    try {
        if ((string) post('action') === 'scan') {
            require_can('audit.scan');
            $date   = date_or_null(post('scan_date')) ?? date('Y-m-d', strtotime('-1 day'));
            $result = audit_scan($date, $storeId, 'manual', (int) $_SESSION['uid']);

            $found = 0;
            $errors = [];
            foreach ($result as $code => $outcome) {
                if (is_int($outcome)) {
                    $found += $outcome;
                } elseif (is_string($outcome) && str_starts_with($outcome, 'error')) {
                    $errors[] = $code . ': ' . $outcome;
                }
            }
            flash_ok(sprintf('Scan of %s complete. %d finding(s) raised or refreshed.',
                date_fmt($date), $found));
            foreach ($errors as $err) {
                flash_warn('Rule failed: ' . $err);
            }
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect('audit/');
}

// The chain check is read-only and cheap on a trail this size, so the centre
// always shows the current state rather than the last recorded check.
$lastCheck = q1('SELECT aic.*, u.full_name AS run_by_name FROM audit_integrity_checks aic
                  LEFT JOIN users u ON u.id = aic.run_by
                 ORDER BY aic.id DESC LIMIT 1');
$trailSize = (int) qv('SELECT COUNT(*) FROM audit_log', [], 0);

$openByseverity = qpairs(
    'SELECT severity, COUNT(*) FROM audit_exceptions
      WHERE status IN (\'open\',\'reviewing\') AND (store_id = ? OR store_id IS NULL)
      GROUP BY severity', [$storeId]
);
$exceptionStats = q1(
    'SELECT COUNT(*) AS total,
            SUM(status IN (\'open\',\'reviewing\')) AS open_now,
            SUM(status = \'cleared\') AS cleared,
            SUM(status = \'confirmed\') AS confirmed,
            SUM(status = \'escalated\') AS escalated
       FROM audit_exceptions WHERE store_id = ? OR store_id IS NULL', [$storeId]
);

$topRules = qa(
    'SELECT ae.rule_code, ar.name, ar.severity, COUNT(*) AS n,
            SUM(ae.status IN (\'open\',\'reviewing\')) AS open_now
       FROM audit_exceptions ae JOIN audit_rules ar ON ar.code = ae.rule_code
      WHERE (ae.store_id = ? OR ae.store_id IS NULL)
        AND ae.period_date >= (CURDATE() - INTERVAL 30 DAY)
      GROUP BY ae.rule_code, ar.name, ar.severity
      ORDER BY open_now DESC, n DESC LIMIT 12', [$storeId]
);

$recent = qa(
    'SELECT ae.*, ar.name AS rule_name, u.full_name AS subject_name
       FROM audit_exceptions ae
       JOIN audit_rules ar ON ar.code = ae.rule_code
       LEFT JOIN users u ON u.id = ae.subject_user_id
      WHERE (ae.store_id = ? OR ae.store_id IS NULL) AND ae.status IN (\'open\',\'reviewing\')
      ORDER BY FIELD(ae.severity,\'critical\',\'high\',\'medium\',\'low\'), ae.detected_at DESC
      LIMIT 10', [$storeId]
);

$lastScan = q1('SELECT asr.*, u.full_name AS run_by_name FROM audit_scan_runs asr
                 LEFT JOIN users u ON u.id = asr.run_by
                ORDER BY asr.id DESC LIMIT 1');

// The three figures a shop's loss actually breaks into.
$from      = date('Y-m-d', strtotime('-30 days'));
$to        = date('Y-m-d');
$shrinkage = report_shrinkage($storeId, $from, $to);

$tillVariance = q1(
    'SELECT COUNT(*) AS shifts, COALESCE(SUM(ABS(variance)),0) AS abs_var,
            COALESCE(SUM(variance),0) AS net_var,
            SUM(status = \'closed\') AS unreconciled
       FROM till_sessions
      WHERE store_id = ? AND DATE(opened_at) BETWEEN ? AND ? AND variance IS NOT NULL',
    [$storeId, $from, $to]
);
$ledgerIssues = stock_ledger_discrepancies($storeId);

layout_head([
    'title'    => 'Audit centre',
    'subtitle' => (string) (current_store()['name'] ?? '') . ' - last 30 days',
    'actions'  => can('audit.scan')
        ? '<form method="post" class="btn-row" style="gap:.4rem">' . csrf_field()
          . '<input type="hidden" name="action" value="scan">'
          . '<input type="date" name="scan_date" value="' . e(date('Y-m-d', strtotime('-1 day'))) . '">'
          . '<button class="btn" type="submit">Run scan</button></form>'
        : '',
]);
?>

<!-- ---- Is the trail intact? ---------------------------------- -->
<?php if ($lastCheck !== null && $lastCheck['result'] === 'broken'): ?>
  <div class="verdict verdict--broken">
    <h2>The audit trail does not verify</h2>
    <p>
      The last integrity check failed at entry #<?= (int) $lastCheck['broken_at_id'] ?>.
      An entry was altered or removed outside the application. Until that is explained, nothing
      else on this page can be relied on &mdash; every figure below is derived from records that
      may have been edited.
      <a href="<?= e(base_url('audit/integrity')) ?>">Investigate</a>
    </p>
  </div>
<?php elseif ($lastCheck !== null): ?>
  <div class="verdict verdict--ok">
    <h2>Audit trail verified</h2>
    <p>
      <?= number_format((int) $lastCheck['rows_checked']) ?> entries checked, chain unbroken, as at
      <?= e(date_fmt((string) $lastCheck['run_at'], true)) ?><?php if ($lastCheck['run_by_name'] !== null): ?>
      by <?= e((string) $lastCheck['run_by_name']) ?><?php endif; ?>.
      The trail now holds <?= number_format($trailSize) ?> entries.
      <?php if (can('audit.integrity')): ?>
        <a href="<?= e(base_url('audit/integrity')) ?>">Verify again</a>
      <?php endif; ?>
    </p>
  </div>
<?php else: ?>
  <div class="alert alert--warning">
    The audit trail has never been verified.
    <?php if (can('audit.integrity')): ?>
      <a href="<?= e(base_url('audit/integrity')) ?>">Run the first check</a>.
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Open exceptions', number_format((int) $exceptionStats['open_now']),
        (int) ($openByseverity['critical'] ?? 0) + (int) ($openByseverity['high'] ?? 0) . ' high or critical',
        (int) ($openByseverity['critical'] ?? 0) > 0 ? 'bad'
          : ((int) $exceptionStats['open_now'] > 0 ? 'warn' : 'ok')) ?>
  <?= tile('Confirmed findings', number_format((int) $exceptionStats['confirmed']),
        'reviewed and upheld',
        (int) $exceptionStats['confirmed'] > 0 ? 'bad' : 'ok') ?>
  <?= tile('Unexplained stock loss', money($shrinkage['unexplained_loss']),
        'count variance and adjustments',
        $shrinkage['unexplained_loss'] > 0 ? 'bad' : 'ok') ?>
  <?= tile('Accounted wastage', money($shrinkage['accounted_loss']),
        'written off with a reason', 'warn') ?>
  <?= tile('Till discrepancy', money((float) $tillVariance['abs_var']),
        number_format((int) $tillVariance['unreconciled']) . ' shifts unreconciled',
        (int) $tillVariance['unreconciled'] > 0 ? 'bad' : 'ok') ?>
  <?= tile('Shrinkage rate', pct_fmt($shrinkage['shrinkage_pct']),
        'of cost of sales',
        $shrinkage['shrinkage_pct'] > 2 ? 'bad' : ($shrinkage['shrinkage_pct'] > 1 ? 'warn' : 'ok')) ?>
</div>

<?php if ($ledgerIssues): ?>
  <div class="alert alert--error">
    <strong><?= count($ledgerIssues) ?></strong> stock line(s) hold a quantity that does not equal
    the sum of their own movements. Every change goes through one write path, so this should be
    impossible &mdash; it means a defect or a direct database edit.
    <a href="<?= e(base_url('audit/shrinkage')) ?>">See the detail</a>
  </div>
<?php endif; ?>

<div class="grid-2">
  <!-- ---- Needs review ---------------------------------------- -->
  <?= card_open('Needs review',
        '<a class="btn btn--sm btn--ghost" href="' . e(base_url('audit/exceptions')) . '">Full queue</a>') ?>
    <?php if (!$recent): ?>
      <?= empty_state('Nothing open.',
            'Every exception raised for this store has been disposed of.') ?>
    <?php else: ?>
      <?php foreach ($recent as $ex): ?>
        <div class="exc exc--<?= e($ex['severity']) ?>">
          <h3>
            <a href="<?= e(base_url('audit/exception', ['id' => (int) $ex['id']])) ?>">
              <?= e($ex['rule_name']) ?></a>
            <?= severity_badge((string) $ex['severity']) ?>
          </h3>
          <p><?= e($ex['summary']) ?></p>
          <div class="exc__meta">
            <?php if ($ex['subject_name'] !== null): ?>
              <span><?= e((string) $ex['subject_name']) ?></span>
            <?php endif; ?>
            <?php if ($ex['period_date'] !== null): ?>
              <span><?= e(date_fmt((string) $ex['period_date'])) ?></span>
            <?php endif; ?>
            <span><?= e(ago((string) $ex['detected_at'])) ?></span>
            <?php if ($ex['metric_value'] !== null && $ex['threshold'] !== null): ?>
              <span><?= e(number_format((float) $ex['metric_value'], 2)) ?>
                against a threshold of <?= e(number_format((float) $ex['threshold'], 2)) ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?= card_close() ?>

  <!-- ---- Which rules are firing ------------------------------ -->
  <?= card_open('Rules firing, last 30 days',
        can('audit.rules')
          ? '<a class="btn btn--sm btn--ghost" href="' . e(base_url('audit/rules')) . '">Thresholds</a>' : '') ?>
    <?php if (!$topRules): ?>
      <?= empty_state('No exceptions raised in the last 30 days.') ?>
    <?php else:
        $maxN = max(array_map(fn ($r) => (int) $r['n'], $topRules)) ?: 1; ?>
      <table class="data data--tight">
        <thead><tr><th>Rule</th><th class="num">Raised</th><th class="num">Still open</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($topRules as $r): ?>
          <tr>
            <td>
              <a href="<?= e(base_url('audit/exceptions', ['rule' => (string) $r['rule_code']])) ?>">
                <?= e($r['name']) ?></a>
              <br><?= severity_badge((string) $r['severity']) ?>
            </td>
            <td class="num"><?= number_format((int) $r['n']) ?></td>
            <td class="num <?= (int) $r['open_now'] > 0 ? 'neg' : '' ?>">
              <?= number_format((int) $r['open_now']) ?>
            </td>
            <td><div class="bar <?= (int) $r['open_now'] > 0 ? 'bar--bad' : '' ?>" style="max-width:90px">
              <span style="width:<?= (int) round(((int) $r['n'] / $maxN) * 100) ?>%"></span>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?= card_close() ?>
</div>

<!-- ---- Where to look next ----------------------------------- -->
<?= card_open('The standing reports') ?>
  <div class="grid-3">
    <div>
      <h3><a href="<?= e(base_url('audit/cashier-scorecard')) ?>">Cashier scorecard</a></h3>
      <p class="small muted">
        Void rate, discount rate, refunds, overrides and till variance per person, against the
        shop average. The comparison is the signal &mdash; an absolute figure tells you only who
        works the busiest till.
      </p>
    </div>
    <div>
      <h3><a href="<?= e(base_url('audit/till-variances')) ?>">Till variances</a></h3>
      <p class="small muted">
        Every shift that closed out of tolerance, whether it has been signed off, and by whom.
        Round-sum shortages are separated from ordinary miscounting.
      </p>
    </div>
    <div>
      <h3><a href="<?= e(base_url('audit/shrinkage')) ?>">Shrinkage</a></h3>
      <p class="small muted">
        Where stock went, read off the movement ledger rather than the documents: sold, written
        off, or gone with no explanation.
      </p>
    </div>
    <div>
      <h3><a href="<?= e(base_url('audit/sod')) ?>">Duty segregation</a></h3>
      <p class="small muted">
        Which roles can both raise and approve the same kind of document, and every case where one
        person stood on both sides of a control.
      </p>
    </div>
    <?php if (can('audit.trail')): ?>
      <div>
        <h3><a href="<?= e(base_url('audit/trail')) ?>">Audit trail</a></h3>
        <p class="small muted">
          Every mutating action, hash-chained and append-only. Filter by person, action, entity
          or date.
        </p>
      </div>
    <?php endif; ?>
    <?php if (can('audit.integrity')): ?>
      <div>
        <h3><a href="<?= e(base_url('audit/integrity')) ?>">Trail integrity</a></h3>
        <p class="small muted">
          Re-walk the hash chain and prove nothing has been altered or removed. Keeps a record of
          every check, so the checks themselves are evidenced.
        </p>
      </div>
    <?php endif; ?>
  </div>
<?= card_close() ?>

<?php if ($lastScan !== null): ?>
  <p class="small muted">
    Last exception scan: <?= e(date_fmt((string) $lastScan['period_date'])) ?>,
    run <?= e(ago((string) $lastScan['started_at'])) ?>
    <?= e($lastScan['run_by_name'] !== null ? 'by ' . $lastScan['run_by_name'] : 'automatically') ?>
    (<?= (int) $lastScan['rules_run'] ?> rules, trigger: <?= e((string) $lastScan['trigger_source']) ?>).
    The scan also runs when a trading day is closed, and from
    <code>cron/daily-scan.php</code> if it is scheduled.
  </p>
<?php endif; ?>

<?php layout_foot(); ?>
