<?php
/**
 * The audit trail viewer.
 *
 * Read-only by construction: there is no edit or delete control here because
 * there is no edit or delete path anywhere. The before and after values are
 * shown expanded, because "product updated" is useless without knowing the
 * price went from 950 to 650.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('audit.trail');

$userId   = get_int('user_id');
$action   = (string) get('action_like');
$entity   = (string) get('entity');
$entityId = get_int('entity_id');
$severity = one_of((string) get('severity'), ['all', 'critical', 'warning', 'notice', 'info'], 'all');
$from     = date_or_null(get('from')) ?? date('Y-m-d', strtotime('-7 days'));
$to       = date_or_null(get('to')) ?? date('Y-m-d');
$search   = (string) get('q');

$where  = ['DATE(al.created_at) BETWEEN ? AND ?'];
$params = [$from, $to];

if ($userId > 0) {
    $where[]  = 'al.user_id = ?';
    $params[] = $userId;
}
if ($action !== '') {
    $where[]  = 'al.action LIKE ?';
    $params[] = $action . '%';
}
if ($entity !== '') {
    $where[]  = 'al.entity = ?';
    $params[] = $entity;
}
if ($entityId > 0) {
    $where[]  = 'al.entity_id = ?';
    $params[] = $entityId;
}
if ($severity !== 'all') {
    $where[]  = 'al.severity = ?';
    $params[] = $severity;
}
if ($search !== '') {
    $where[]  = 'al.summary LIKE ?';
    $params[] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

if ((string) get('export') === 'csv') {
    require_can('reports.view');
    $rows = qa("SELECT al.id, al.created_at, al.user_label, al.action, al.entity, al.entity_id,
                       al.summary, al.severity, al.ip, al.before_json, al.after_json, al.row_hash
                  FROM audit_log al WHERE $whereSql ORDER BY al.id", $params);
    // Exporting the trail is itself a logged action - it moves the record
    // somewhere this system can no longer protect.
    audit_log('audit.trail_export', 'audit_log', null,
        sprintf('Exported %d trail entries for %s to %s', count($rows), $from, $to),
        null, null, 'warning');
    send_csv('audit-trail-' . $from . '-to-' . $to . '.csv', [
        'Entry', 'When', 'Who', 'Action', 'Entity', 'Entity ID', 'Summary',
        'Severity', 'IP', 'Before', 'After', 'Row hash',
    ], $rows);
}

[$limit, $offset, $page] = paging(60);
$total = (int) qv("SELECT COUNT(*) FROM audit_log al WHERE $whereSql", $params, 0);

$rows = qa(
    "SELECT al.*, st.name AS store_name FROM audit_log al
       LEFT JOIN stores st ON st.id = al.store_id
      WHERE $whereSql ORDER BY al.id DESC LIMIT $limit OFFSET $offset",
    $params
);

$summary = q1(
    "SELECT COUNT(*) AS n, COUNT(DISTINCT al.user_id) AS actors,
            SUM(al.severity = 'critical') AS critical,
            SUM(al.severity = 'warning') AS warning
       FROM audit_log al WHERE $whereSql",
    $params
);

$users    = qpairs('SELECT id, full_name FROM users ORDER BY full_name');
$entities = qpairs('SELECT DISTINCT entity, entity FROM audit_log ORDER BY entity');
$actions  = qcol('SELECT DISTINCT SUBSTRING_INDEX(action, \'.\', 1) AS prefix
                    FROM audit_log ORDER BY prefix');

layout_head([
    'title'    => 'Audit trail',
    'subtitle' => number_format($total) . ' entries, ' . date_fmt($from) . ' to ' . date_fmt($to),
    'actions'  => (can('reports.view')
            ? '<a class="btn btn--ghost" href="' . e(url_with(['export' => 'csv'])) . '">Export CSV</a>' : '')
        . (can('audit.integrity')
            ? '<a class="btn btn--ghost" href="' . e(base_url('audit/integrity')) . '">Verify chain</a>' : ''),
]);
?>

<div class="tiles">
  <?= tile('Entries', number_format((int) $summary['n']), 'matching the filter') ?>
  <?= tile('Distinct actors', number_format((int) $summary['actors']), 'people and the system', 'mute') ?>
  <?= tile('Critical', number_format((int) $summary['critical']), 'highest severity',
        (int) $summary['critical'] > 0 ? 'bad' : 'ok') ?>
  <?= tile('Warnings', number_format((int) $summary['warning']), 'voids, write-offs, overrides',
        (int) $summary['warning'] > 0 ? 'warn' : 'ok') ?>
</div>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">From</span>
    <input type="date" name="from" value="<?= e($from) ?>">
  </label>
  <label class="field">
    <span class="field__label">To</span>
    <input type="date" name="to" value="<?= e($to) ?>">
  </label>
  <label class="field">
    <span class="field__label">Who</span>
    <select name="user_id"><?= select_options($users, $userId, true, 'Anyone') ?></select>
  </label>
  <label class="field">
    <span class="field__label">Action group</span>
    <select name="action_like">
      <?= select_options(array_combine($actions, $actions), $action, true, 'All actions') ?>
    </select>
  </label>
  <label class="field">
    <span class="field__label">Entity</span>
    <select name="entity"><?= select_options($entities, $entity, true, 'All') ?></select>
  </label>
  <label class="field">
    <span class="field__label">Severity</span>
    <select name="severity">
      <?= select_options(['all' => 'Any', 'critical' => 'Critical', 'warning' => 'Warning',
                          'notice' => 'Notice', 'info' => 'Info'], $severity) ?>
    </select>
  </label>
  <label class="field">
    <span class="field__label">Text</span>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search summaries">
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('audit/trail')) ?>">Reset</a>
</form>

<?= card_open('Entries', '<span class="tiny muted">Append-only and hash-chained</span>') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No entries match those filters.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>#</th><th>When</th><th>Who</th><th>Action</th><th>What happened</th>
        <th>Record</th><th>Severity</th><th>Where from</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $before = $r['before_json'] !== null ? json_decode((string) $r['before_json'], true) : null;
          $after  = $r['after_json'] !== null ? json_decode((string) $r['after_json'], true) : null;
          $hasDiff = is_array($before) || is_array($after); ?>
        <tr class="<?= $r['severity'] === 'critical' ? 'row-bad'
                      : ($r['severity'] === 'warning' ? 'row-warn' : '') ?>">
          <td class="mono tiny"><?= (int) $r['id'] ?></td>
          <td class="nowrap tiny"><?= e(date_fmt((string) $r['created_at'], true)) ?></td>
          <td class="tiny"><?= e($r['user_label'] ?? 'system') ?></td>
          <td class="mono tiny"><?= e($r['action']) ?></td>
          <td>
            <?= e($r['summary']) ?>
            <?php if ($hasDiff): ?>
              <details>
                <summary class="tiny muted" style="cursor:pointer">before and after</summary>
                <pre class="trail-detail"><?php
                  if (is_array($before)) {
                      echo 'BEFORE: ' . e(json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n";
                  }
                  if (is_array($after)) {
                      echo 'AFTER:  ' . e(json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                  }
                ?></pre>
              </details>
            <?php endif; ?>
          </td>
          <td class="tiny">
            <?php
            // Link through to the record where there is a page for it.
            $link = match ((string) $r['entity']) {
                'sale'         => base_url('sales/view', ['id' => (int) $r['entity_id']]),
                'till_session' => base_url('pos/session', ['id' => (int) $r['entity_id']]),
                'product'      => base_url('inventory/product', ['id' => (int) $r['entity_id']]),
                'grn'          => base_url('inventory/grn', ['id' => (int) $r['entity_id']]),
                'purchase_order' => base_url('inventory/purchase-order', ['id' => (int) $r['entity_id']]),
                'stock_count'  => base_url('inventory/count', ['id' => (int) $r['entity_id']]),
                'adjustment'   => base_url('inventory/adjustment', ['id' => (int) $r['entity_id']]),
                'transfer'     => base_url('inventory/transfer', ['id' => (int) $r['entity_id']]),
                'customer'     => base_url('sales/customer', ['id' => (int) $r['entity_id']]),
                'audit_exception' => base_url('audit/exception', ['id' => (int) $r['entity_id']]),
                default        => null,
            };
            if ($link !== null && $r['entity_id'] !== null): ?>
              <a href="<?= e($link) ?>"><?= e($r['entity']) ?> #<?= (int) $r['entity_id'] ?></a>
            <?php else: ?>
              <span class="muted"><?= e($r['entity']) ?>
                <?= $r['entity_id'] !== null ? '#' . (int) $r['entity_id'] : '' ?></span>
            <?php endif; ?>
          </td>
          <td><?= severity_badge((string) $r['severity']) ?></td>
          <td class="mono tiny muted"><?= e($r['ip'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= pager($total, $limit, $page) ?>
  <?php endif; ?>
<?= card_close() ?>

<p class="small muted">
  Exporting the trail is itself recorded, at warning severity: an export moves the record somewhere
  this system can no longer protect, which is worth knowing about.
</p>

<?php layout_foot(); ?>
