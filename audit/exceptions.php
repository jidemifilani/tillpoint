<?php
/**
 * The exception queue.
 *
 * A work list, not a report: open items first, severest first. Bulk clearing is
 * deliberately absent - a reviewer who can tick forty boxes and type one note
 * has not reviewed anything, and the note would be worthless as evidence that
 * someone looked.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('audit.view');

$storeId = current_store_id();

$status   = one_of((string) get('status'), ['open', 'all', 'reviewing', 'cleared', 'confirmed', 'escalated'], 'open');
$severity = one_of((string) get('severity'), ['all', 'critical', 'high', 'medium', 'low'], 'all');
$rule     = (string) get('rule');
$subject  = get_int('subject');
$from     = date_or_null(get('from'));
$to       = date_or_null(get('to'));

$where  = ['(ae.store_id = ? OR ae.store_id IS NULL)'];
$params = [$storeId];

if ($status === 'open') {
    $where[] = "ae.status IN ('open','reviewing')";
} elseif ($status !== 'all') {
    $where[]  = 'ae.status = ?';
    $params[] = $status;
}
if ($severity === 'high') {
    // "High" on the filter means high and above, which is what someone
    // triaging actually wants - not high but excluding critical.
    $where[] = "ae.severity IN ('high','critical')";
} elseif ($severity !== 'all') {
    $where[]  = 'ae.severity = ?';
    $params[] = $severity;
}
if ($rule !== '') {
    $where[]  = 'ae.rule_code = ?';
    $params[] = $rule;
}
if ($subject > 0) {
    $where[]  = 'ae.subject_user_id = ?';
    $params[] = $subject;
}
if ($from !== null) {
    $where[]  = 'ae.period_date >= ?';
    $params[] = $from;
}
if ($to !== null) {
    $where[]  = 'ae.period_date <= ?';
    $params[] = $to;
}
$whereSql = implode(' AND ', $where);

$fromSql = 'FROM audit_exceptions ae
            JOIN audit_rules ar ON ar.code = ae.rule_code
            LEFT JOIN users su ON su.id = ae.subject_user_id
            LEFT JOIN users rv ON rv.id = ae.reviewed_by
            LEFT JOIN stores st ON st.id = ae.store_id';

if ((string) get('export') === 'csv') {
    require_can('reports.view');
    $rows = qa("SELECT ae.id, ae.rule_code, ar.name AS rule_name, ae.severity, ae.status,
                       ae.period_date, st.name AS store, su.full_name AS subject,
                       ae.entity, ae.entity_id, ae.metric_value, ae.threshold, ae.summary,
                       ae.detected_at, rv.full_name AS reviewed_by, ae.reviewed_at,
                       ae.disposition, ae.disposition_note
                $fromSql WHERE $whereSql ORDER BY ae.id DESC", $params);
    audit_log('audit.export', 'audit_exception', null,
        'Exported ' . count($rows) . ' audit exceptions', null, null, 'notice');
    send_csv('audit-exceptions-' . date('Y-m-d') . '.csv', [
        'ID', 'Rule', 'Rule name', 'Severity', 'Status', 'Period', 'Store', 'Subject',
        'Entity', 'Entity ID', 'Metric', 'Threshold', 'Summary', 'Detected',
        'Reviewed by', 'Reviewed at', 'Disposition', 'Note',
    ], $rows);
}

[$limit, $offset, $page] = paging(40);
$total = (int) qv("SELECT COUNT(*) $fromSql WHERE $whereSql", $params, 0);

$rows = qa(
    "SELECT ae.*, ar.name AS rule_name, ar.description AS rule_description,
            ar.threshold_label, su.full_name AS subject_name,
            rv.full_name AS reviewed_by_name, st.name AS store_name
     $fromSql WHERE $whereSql
     ORDER BY FIELD(ae.status,'open','reviewing','escalated','confirmed','cleared'),
              FIELD(ae.severity,'critical','high','medium','low'),
              ae.detected_at DESC
     LIMIT $limit OFFSET $offset",
    $params
);

$counts = q1(
    "SELECT COUNT(*) AS total,
            SUM(ae.status IN ('open','reviewing')) AS open_now,
            SUM(ae.severity IN ('high','critical') AND ae.status IN ('open','reviewing')) AS urgent,
            SUM(ae.status = 'confirmed') AS confirmed
     $fromSql WHERE (ae.store_id = ? OR ae.store_id IS NULL)",
    [$storeId]
);

$rules    = qpairs('SELECT code, name FROM audit_rules ORDER BY name');
$subjects = qpairs(
    'SELECT DISTINCT u.id, u.full_name FROM audit_exceptions ae
       JOIN users u ON u.id = ae.subject_user_id
      WHERE ae.store_id = ? OR ae.store_id IS NULL ORDER BY u.full_name', [$storeId]
);

layout_head([
    'title'    => 'Exception queue',
    'subtitle' => number_format((int) $counts['open_now']) . ' open, '
                . number_format((int) $counts['urgent']) . ' high or critical',
    'actions'  => can('reports.view')
        ? '<a class="btn btn--ghost" href="' . e(url_with(['export' => 'csv'])) . '">Export CSV</a>' : '',
]);
?>

<div class="tiles">
  <?= tile('Open', number_format((int) $counts['open_now']), 'awaiting review',
        (int) $counts['open_now'] > 0 ? 'warn' : 'ok') ?>
  <?= tile('High or critical', number_format((int) $counts['urgent']), 'open and serious',
        (int) $counts['urgent'] > 0 ? 'bad' : 'ok') ?>
  <?= tile('Confirmed', number_format((int) $counts['confirmed']), 'reviewed and upheld',
        (int) $counts['confirmed'] > 0 ? 'bad' : 'mute') ?>
  <?= tile('Raised all time', number_format((int) $counts['total']), 'for this store', 'mute') ?>
</div>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">Status</span>
    <select name="status">
      <?= select_options([
          'open'      => 'Open and reviewing',
          'all'       => 'All',
          'reviewing' => 'Under review',
          'cleared'   => 'Cleared',
          'confirmed' => 'Confirmed',
          'escalated' => 'Escalated',
      ], $status) ?>
    </select>
  </label>
  <label class="field">
    <span class="field__label">Severity</span>
    <select name="severity">
      <?= select_options([
          'all'      => 'Any',
          'critical' => 'Critical only',
          'high'     => 'High and above',
          'medium'   => 'Medium',
          'low'      => 'Low',
      ], $severity) ?>
    </select>
  </label>
  <label class="field" style="min-width:200px">
    <span class="field__label">Rule</span>
    <select name="rule"><?= select_options($rules, $rule, true, 'All rules') ?></select>
  </label>
  <label class="field">
    <span class="field__label">About</span>
    <select name="subject"><?= select_options($subjects, $subject, true, 'Anyone') ?></select>
  </label>
  <label class="field">
    <span class="field__label">From</span>
    <input type="date" name="from" value="<?= e((string) ($from ?? '')) ?>">
  </label>
  <label class="field">
    <span class="field__label">To</span>
    <input type="date" name="to" value="<?= e((string) ($to ?? '')) ?>">
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('audit/exceptions')) ?>">Reset</a>
</form>

<?= card_open('Exceptions (' . number_format($total) . ')') ?>
  <?php if (!$rows): ?>
    <?= empty_state('Nothing matches those filters.',
          $status === 'open' ? 'The open queue is clear.' : '') ?>
  <?php else: ?>
    <?php foreach ($rows as $ex):
        $isOpen = in_array($ex['status'], ['open', 'reviewing'], true); ?>
      <div class="exc exc--<?= e($ex['severity']) ?>" style="<?= $isOpen ? '' : 'opacity:.72' ?>">
        <h3>
          <a href="<?= e(base_url('audit/exception', ['id' => (int) $ex['id']])) ?>">
            <?= e($ex['rule_name']) ?></a>
          <?= severity_badge((string) $ex['severity']) ?>
          <?= status_badge((string) $ex['status']) ?>
        </h3>
        <p><?= e($ex['summary']) ?></p>
        <p class="small muted"><?= e($ex['rule_description']) ?></p>
        <div class="exc__meta">
          <span class="mono">#<?= (int) $ex['id'] ?></span>
          <span class="mono"><?= e($ex['rule_code']) ?></span>
          <?php if ($ex['subject_name'] !== null): ?>
            <span>About <?= e((string) $ex['subject_name']) ?></span>
          <?php endif; ?>
          <?php if ($ex['store_name'] !== null): ?>
            <span><?= e((string) $ex['store_name']) ?></span>
          <?php endif; ?>
          <?php if ($ex['period_date'] !== null): ?>
            <span>Trading day <?= e(date_fmt((string) $ex['period_date'])) ?></span>
          <?php endif; ?>
          <?php if ($ex['metric_value'] !== null): ?>
            <span>
              <?= e($ex['threshold_label'] ?? 'Measured') ?>:
              <strong><?= e(number_format((float) $ex['metric_value'], 2)) ?></strong>
              <?php if ($ex['threshold'] !== null): ?>
                against <?= e(number_format((float) $ex['threshold'], 2)) ?>
              <?php endif; ?>
            </span>
          <?php endif; ?>
          <span>Detected <?= e(ago((string) $ex['detected_at'])) ?></span>
          <?php if ($ex['reviewed_by_name'] !== null): ?>
            <span>
              <?= e((string) $ex['disposition'] ?? 'Reviewed') ?>
              by <?= e((string) $ex['reviewed_by_name']) ?>,
              <?= e(ago((string) $ex['reviewed_at'])) ?>
            </span>
          <?php endif; ?>
        </div>
        <?php if ($ex['disposition_note'] !== null): ?>
          <p class="small"><em>&ldquo;<?= e((string) $ex['disposition_note']) ?>&rdquo;</em></p>
        <?php endif; ?>
        <?php if ($isOpen && can('audit.review')): ?>
          <p class="small" style="margin-top:.4rem">
            <a class="btn btn--sm btn--ghost"
               href="<?= e(base_url('audit/exception', ['id' => (int) $ex['id']])) ?>">Review this</a>
          </p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?= pager($total, $limit, $page) ?>
  <?php endif; ?>
<?= card_close() ?>

<p class="small muted">
  There is no bulk clear. Each exception is disposed of on its own page with written grounds,
  because a reviewer who ticks forty boxes and types one note has not reviewed anything, and the
  note would be worthless as evidence that someone actually looked.
</p>

<?php layout_foot(); ?>
