<?php
/**
 * Review one exception.
 *
 * Shows the finding, the rule behind it, the underlying evidence, and every
 * decision anyone has recorded on it. The review history is append-only, so a
 * second reviewer disagreeing with the first does not erase the first - which
 * is the only reason a disposition is worth anything.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('audit.view');

$id = get_int('id');

$ex = q1(
    'SELECT ae.*, ar.name AS rule_name, ar.description AS rule_description,
            ar.module, ar.threshold_label, ar.severity AS rule_severity,
            su.full_name AS subject_name, su.staff_no AS subject_staff,
            rv.full_name AS reviewed_by_name, st.name AS store_name
       FROM audit_exceptions ae
       JOIN audit_rules ar ON ar.code = ae.rule_code
       LEFT JOIN users su ON su.id = ae.subject_user_id
       LEFT JOIN users rv ON rv.id = ae.reviewed_by
       LEFT JOIN stores st ON st.id = ae.store_id
      WHERE ae.id = ?',
    [$id]
);
if (!$ex) {
    http_response_code(404);
    require APP_ROOT . '/404.php';
    exit;
}

if (is_post()) {
    csrf_require();
    require_can('audit.review');
    try {
        audit_dispose_exception($id, (int) $_SESSION['uid'],
            (string) post('disposition'), (string) post('note'));
        flash_ok('Decision recorded.');
        redirect('audit/exception', ['id' => $id]);
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('audit/exception', ['id' => $id]);
    }
}

$reviews = qa(
    'SELECT arv.*, u.full_name AS reviewer_name FROM audit_reviews arv
       JOIN users u ON u.id = arv.reviewer_id
      WHERE arv.exception_id = ? ORDER BY arv.id',
    [$id]
);
$detail = $ex['detail_json'] !== null ? json_decode((string) $ex['detail_json'], true) : null;

/* ------------------------------------------------------------------ */
/* Evidence                                                           */
/* ------------------------------------------------------------------ */
// What the reviewer needs to see depends on what the exception is about, so the
// underlying records are pulled per entity type. A finding without its evidence
// is an accusation.
$evidence = [];
$entity   = (string) ($ex['entity'] ?? '');
$entityId = $ex['entity_id'] !== null ? (int) $ex['entity_id'] : null;

if ($entity === 'till_session' && $entityId !== null) {
    $evidence['session'] = q1(
        'SELECT ts.*, u.full_name AS cashier, t.code AS terminal_code
           FROM till_sessions ts JOIN users u ON u.id = ts.user_id
           JOIN terminals t ON t.id = ts.terminal_id WHERE ts.id = ?', [$entityId]
    );
    $evidence['movements'] = qa(
        'SELECT cm.*, u.full_name AS who, a.full_name AS authorised_by_name
           FROM cash_movements cm JOIN users u ON u.id = cm.user_id
           LEFT JOIN users a ON a.id = cm.authorised_by
          WHERE cm.session_id = ? ORDER BY cm.created_at', [$entityId]
    );
} elseif ($entity === 'sale' && $entityId !== null) {
    $evidence['sale'] = q1(
        'SELECT s.*, u.full_name AS cashier FROM sales s
           JOIN users u ON u.id = s.cashier_id WHERE s.id = ?', [$entityId]
    );
    $evidence['lines'] = qa(
        'SELECT sl.*, p.name, p.unit_code FROM sale_lines sl
           JOIN products p ON p.id = sl.product_id WHERE sl.sale_id = ?', [$entityId]
    );
} elseif ($entity === 'user' && $entityId !== null && $ex['period_date'] !== null) {
    // A behavioural rule: show the day's transactions for that person, which is
    // the evidence behind a rate.
    $evidence['user_day'] = qa(
        'SELECT s.id, s.receipt_no, s.status, s.total, s.discount_total, s.line_count,
                s.sold_at, s.void_reason
           FROM sales s
          WHERE s.cashier_id = ? AND s.business_date = ?
          ORDER BY s.id DESC LIMIT 120',
        [$entityId, (string) $ex['period_date']]
    );
    $evidence['user_overrides'] = qa(
        'SELECT sl.*, p.name, s.receipt_no, ob.full_name AS authorised_by
           FROM sale_lines sl JOIN sales s ON s.id = sl.sale_id
           JOIN products p ON p.id = sl.product_id
           LEFT JOIN users ob ON ob.id = sl.overridden_by
          WHERE s.cashier_id = ? AND s.business_date = ?
            AND (sl.price_overridden = 1 OR sl.line_discount > 0)
          ORDER BY sl.line_discount DESC LIMIT 60',
        [$entityId, (string) $ex['period_date']]
    );
} elseif (in_array($entity, ['grn', 'stock_count', 'adjustment', 'wastage', 'transfer'], true)
          && $entityId !== null) {
    $evidence['movements'] = qa(
        'SELECT sm.*, p.name, p.unit_code FROM stock_movements sm
           JOIN products p ON p.id = sm.product_id
          WHERE sm.ref_type = ? AND sm.ref_id = ? ORDER BY sm.id',
        [$entity === 'stock_count' ? 'count' : $entity, $entityId]
    );
}

// The trail entries around this finding, which is often the quickest way to see
// what a person was actually doing.
$trail = can('audit.trail') && $ex['subject_user_id'] !== null && $ex['period_date'] !== null
    ? qa('SELECT * FROM audit_log
           WHERE user_id = ? AND DATE(created_at) = ?
           ORDER BY id DESC LIMIT 60',
         [(int) $ex['subject_user_id'], (string) $ex['period_date']])
    : [];

$isOpen = in_array($ex['status'], ['open', 'reviewing'], true);

layout_head([
    'title'    => $ex['rule_name'],
    'subtitle' => 'Exception #' . $id . ' - ' . $ex['rule_code'],
    'actions'  => '<a class="btn btn--ghost" href="' . e(base_url('audit/exceptions')) . '">Back to the queue</a>'
                . '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<div class="verdict verdict--<?= in_array($ex['severity'], ['critical', 'high'], true) ? 'broken' : 'ok' ?>">
  <h2><?= e($ex['summary']) ?></h2>
  <p>
    <?= severity_badge((string) $ex['severity']) ?>
    <?= status_badge((string) $ex['status']) ?>
    <?php if ($ex['metric_value'] !== null): ?>
      &middot; <?= e($ex['threshold_label'] ?? 'Measured') ?>
      <strong><?= e(number_format((float) $ex['metric_value'], 2)) ?></strong>
      <?php if ($ex['threshold'] !== null): ?>
        against a threshold of <?= e(number_format((float) $ex['threshold'], 2)) ?>
      <?php endif; ?>
    <?php endif; ?>
  </p>
</div>

<div class="grid-2">
  <?= card_open('The finding') ?>
    <dl class="kv">
      <dt>Rule</dt><dd><?= e($ex['rule_name']) ?> <span class="mono tiny"><?= e($ex['rule_code']) ?></span></dd>
      <dt>Module</dt><dd><?= e(ucfirst((string) $ex['module'])) ?></dd>
      <dt>Store</dt><dd><?= e($ex['store_name'] ?? 'All stores') ?></dd>
      <?php if ($ex['subject_name'] !== null): ?>
        <dt>About</dt><dd><?= e((string) $ex['subject_name']) ?>
          <span class="tiny muted"><?= e((string) ($ex['subject_staff'] ?? '')) ?></span></dd>
      <?php endif; ?>
      <?php if ($ex['period_date'] !== null): ?>
        <dt>Trading day</dt><dd><?= e(date_fmt((string) $ex['period_date'])) ?></dd>
      <?php endif; ?>
      <?php if ($entity !== ''): ?>
        <dt>Record</dt><dd class="mono"><?= e($entity) ?> #<?= e((string) $entityId) ?></dd>
      <?php endif; ?>
      <dt>First detected</dt><dd><?= e(date_fmt((string) $ex['detected_at'], true)) ?></dd>
    </dl>

    <p class="small muted mt1"><?= e($ex['rule_description']) ?></p>

    <?php if ($entity === 'sale' && $entityId !== null): ?>
      <p class="small mt1">
        <a href="<?= e(base_url('sales/view', ['id' => $entityId])) ?>">Open the full receipt &rarr;</a>
      </p>
    <?php elseif ($entity === 'till_session' && $entityId !== null): ?>
      <p class="small mt1">
        <a href="<?= e(base_url('pos/session', ['id' => $entityId])) ?>">Open the shift report &rarr;</a>
      </p>
    <?php elseif ($entity === 'grn' && $entityId !== null): ?>
      <p class="small mt1">
        <a href="<?= e(base_url('inventory/grn', ['id' => $entityId])) ?>">Open the delivery &rarr;</a>
      </p>
    <?php elseif ($entity === 'stock_count' && $entityId !== null): ?>
      <p class="small mt1">
        <a href="<?= e(base_url('inventory/count', ['id' => $entityId])) ?>">Open the count sheet &rarr;</a>
      </p>
    <?php elseif ($entity === 'adjustment' && $entityId !== null): ?>
      <p class="small mt1">
        <a href="<?= e(base_url('inventory/adjustment', ['id' => $entityId])) ?>">Open the adjustment &rarr;</a>
      </p>
    <?php elseif ($entity === 'transfer' && $entityId !== null): ?>
      <p class="small mt1">
        <a href="<?= e(base_url('inventory/transfer', ['id' => $entityId])) ?>">Open the transfer &rarr;</a>
      </p>
    <?php endif; ?>
  <?= card_close() ?>

  <!-- ---- Record a decision ---------------------------------- -->
  <?php if (can('audit.review')): ?>
    <?= card_open($isOpen ? 'Record your decision' : 'Revisit this decision') ?>
      <form method="post">
        <?= csrf_field() ?>
        <label class="field">
          <span class="field__label">Decision</span>
          <select name="disposition" required>
            <option value="reviewing">Looking into it &mdash; keep it open</option>
            <option value="cleared">Cleared &mdash; there is an innocent explanation</option>
            <option value="confirmed">Confirmed &mdash; the concern is real</option>
            <option value="escalated">Escalated &mdash; needs someone more senior</option>
          </select>
        </label>
        <label class="field">
          <span class="field__label">Grounds</span>
          <textarea name="note" rows="4" required
            placeholder="What you checked, who you spoke to, and what you concluded."></textarea>
          <span class="field__hint">
            Required for anything other than "looking into it". Kept permanently, even if a later
            reviewer decides differently.
          </span>
        </label>
        <button class="btn" type="submit">Record decision</button>
      </form>

      <?php if (!$isOpen): ?>
        <p class="small muted mt1">
          This exception is already <?= e((string) $ex['status']) ?>. Recording another decision
          adds to the history rather than replacing what is there.
        </p>
      <?php endif; ?>
    <?= card_close() ?>
  <?php else: ?>
    <?= card_open('Decisions') ?>
      <p class="small muted">Your role can read exceptions but not dispose of them.</p>
    <?= card_close() ?>
  <?php endif; ?>
</div>

<!-- ---- Review history ------------------------------------- -->
<?php if ($reviews): ?>
<?= card_open('Review history (' . count($reviews) . ')',
      '<span class="tiny muted">Append-only</span>') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr><th>When</th><th>Reviewer</th><th>Decision</th><th>Grounds</th></tr></thead>
    <tbody>
    <?php foreach ($reviews as $r): ?>
      <tr>
        <td class="nowrap tiny"><?= e(date_fmt((string) $r['created_at'], true)) ?></td>
        <td><?= e($r['reviewer_name']) ?></td>
        <td><?= status_badge((string) $r['action']) ?></td>
        <td><?= e($r['note'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<!-- ---- Evidence ------------------------------------------- -->
<?php if (!empty($evidence['session'])): $s = $evidence['session']; ?>
<?= card_open('The shift') ?>
  <?php $cash = session_expected_cash((int) $s['id']); ?>
  <dl class="kv">
    <dt>Session</dt><dd class="mono"><?= e($s['session_no']) ?></dd>
    <dt>Cashier</dt><dd><?= e($s['cashier']) ?> on <?= e($s['terminal_code']) ?></dd>
    <dt>Opened</dt><dd><?= e(date_fmt((string) $s['opened_at'], true)) ?></dd>
    <dt>Closed</dt><dd><?= e(date_fmt($s['closed_at'], true)) ?></dd>
    <dt>Opening float</dt><dd><?= e(money((float) $s['opening_float'])) ?></dd>
    <dt>Cash sales</dt><dd><?= e(money((float) $cash['cash_sales'])) ?></dd>
    <dt>Expected in drawer</dt><dd><?= e(money((float) $cash['expected'])) ?></dd>
    <dt>Declared</dt><dd><?= e(money((float) ($s['declared_cash'] ?? 0))) ?></dd>
    <dt>Variance</dt><dd class="<?= (float) ($s['variance'] ?? 0) < 0 ? 'bad-text' : '' ?>">
      <strong><?= e(money((float) ($s['variance'] ?? 0))) ?></strong></dd>
  </dl>
<?= card_close() ?>
<?php endif; ?>

<?php if (!empty($evidence['movements'])): ?>
<?= card_open('Underlying movements') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <?php if (isset($evidence['movements'][0]['movement_type'])): ?>
        <th>When</th><th>Product</th><th>Type</th><th class="num">Qty</th>
        <th class="num">Unit cost</th><th class="num">Balance after</th><th>Reason</th>
      <?php else: ?>
        <th>When</th><th>Type</th><th class="num">Amount</th><th>Reason</th>
        <th>By</th><th>Authorised by</th><th>Note</th>
      <?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($evidence['movements'] as $m): ?>
      <tr>
        <td class="nowrap tiny"><?= e(date_fmt((string) $m['created_at'], true)) ?></td>
        <?php if (isset($m['movement_type'])): ?>
          <td><?= e((string) ($m['name'] ?? '')) ?></td>
          <td class="tiny"><?= e(str_replace('_', ' ', (string) $m['movement_type'])) ?></td>
          <?= num_cell($m['qty_delta'], false, true) ?>
          <?= num_cell($m['unit_cost']) ?>
          <td class="num"><?= e(qty_fmt((float) $m['balance_after'])) ?></td>
          <td class="tiny"><?= e((string) ($m['reason_code'] ?? '')) ?></td>
        <?php else: ?>
          <td><?= e(ucfirst(str_replace('_', ' ', (string) $m['type']))) ?></td>
          <?= num_cell($m['amount']) ?>
          <td class="tiny"><?= e((string) ($m['reason_code'] ?? '')) ?></td>
          <td class="tiny"><?= e((string) ($m['who'] ?? '')) ?></td>
          <td class="tiny"><?= e((string) ($m['authorised_by_name'] ?? '-')) ?></td>
          <td class="tiny muted"><?= e((string) ($m['note'] ?? '')) ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?php if (!empty($evidence['user_day'])): ?>
<?= card_open('That person\'s transactions on ' . date_fmt((string) $ex['period_date'])) ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Receipt</th><th>Time</th><th class="num">Items</th><th class="num">Discount</th>
      <th class="num">Total</th><th>Status</th><th>Void reason</th>
    </tr></thead>
    <tbody>
    <?php foreach ($evidence['user_day'] as $s): ?>
      <tr class="<?= $s['status'] === 'voided' ? 'row-warn' : '' ?>">
        <td class="mono tiny">
          <a href="<?= e(base_url('sales/view', ['id' => (int) $s['id']])) ?>"><?= e($s['receipt_no']) ?></a>
        </td>
        <td class="nowrap tiny"><?= e($s['sold_at'] !== null ? date('H:i', strtotime((string) $s['sold_at'])) : '-') ?></td>
        <td class="num"><?= (int) $s['line_count'] ?></td>
        <?= num_cell($s['discount_total']) ?>
        <?= num_cell($s['total']) ?>
        <td><?= status_badge((string) $s['status']) ?></td>
        <td class="tiny"><?= e($s['void_reason'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?php if (!empty($evidence['user_overrides'])): ?>
<?= card_open('Discounts and overrides that day') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Receipt</th><th>Product</th><th class="num">Qty</th>
      <th class="num">List</th><th class="num">Charged</th><th class="num">Discount</th>
      <th>Overridden</th><th>Authorised by</th>
    </tr></thead>
    <tbody>
    <?php foreach ($evidence['user_overrides'] as $l): ?>
      <tr>
        <td class="mono tiny"><?= e($l['receipt_no']) ?></td>
        <td><?= e($l['name']) ?></td>
        <td class="num"><?= e(qty_fmt((float) $l['qty'])) ?></td>
        <?= num_cell($l['list_price']) ?>
        <?= num_cell($l['unit_price']) ?>
        <?= num_cell($l['line_discount']) ?>
        <td><?= (int) $l['price_overridden'] === 1 ? badge('Yes', 'warn') : badge('No', 'neutral') ?></td>
        <td class="tiny"><?= e($l['authorised_by'] ?? '-') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?php if (is_array($detail) && $detail): ?>
<?= card_open('Detail captured when the rule fired') ?>
  <pre class="trail-detail"><?= e(json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
<?= card_close() ?>
<?php endif; ?>

<?php if ($trail): ?>
<?= card_open('What that person did that day', '<span class="tiny muted">From the audit trail</span>') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr><th>#</th><th>When</th><th>Action</th><th>What happened</th><th>Severity</th></tr></thead>
    <tbody>
    <?php foreach ($trail as $t): ?>
      <tr>
        <td class="mono tiny"><?= (int) $t['id'] ?></td>
        <td class="nowrap tiny"><?= e(date_fmt((string) $t['created_at'], true)) ?></td>
        <td class="mono tiny"><?= e($t['action']) ?></td>
        <td class="tiny"><?= e($t['summary']) ?></td>
        <td><?= severity_badge((string) $t['severity']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<div class="sign-off">
  <div>Reviewed by: <?= e($ex['reviewed_by_name'] ?? '________________________') ?></div>
  <div>Date: <?= e($ex['reviewed_at'] !== null ? date_fmt((string) $ex['reviewed_at']) : '________________') ?></div>
</div>

<?php layout_foot(); ?>
