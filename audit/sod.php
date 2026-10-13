<?php
/**
 * Segregation of duties.
 *
 * Two different things on one page, because an auditor needs both:
 *
 *   1. the design - which roles hold both sides of a control, so a conflict can
 *      be removed before it is exercised;
 *   2. the practice - every document where one person actually stood on both
 *      sides, which the posting routines refuse but which is worth proving
 *      empty rather than asserting.
 *
 * The second list being empty is the evidence that the first is working. If
 * anything appears in it, either an override was used or there is a defect, and
 * both need explaining.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('audit.view');

$storeId = current_store_id();
$from    = date_or_null(get('from')) ?? date('Y-m-d', strtotime('-90 days'));
$to      = date_or_null(get('to')) ?? date('Y-m-d');

/**
 * The incompatible pairs. Each is "raising" against "approving" the same class
 * of document - the shape every one of these controls takes.
 */
$conflicts = [
    ['Raise a purchase order', 'po.create',    'Approve a purchase order', 'po.approve',
     'A buyer who can approve their own order can buy from themselves at any price.'],
    ['Raise a refund',         'refund.request', 'Approve a refund',       'refund.approve',
     'A cashier who can approve their own refund can take cash out of the drawer at will.'],
    ['Enter counted stock',    'count.enter',  'Approve a count variance', 'count.approve',
     'A counter who signs off their own sheet can write off whatever has already gone.'],
    ['Log wastage',            'wastage.log',  'Approve wastage',          'wastage.approve',
     'Wastage is the easiest route for goods to leave with correct-looking paperwork.'],
    ['Raise a stock adjustment', 'stock.adjust', 'Approve an adjustment',  'stock.adjust.approve',
     'An adjustment changes stock with no physical event, so it can make any shortage vanish.'],
    ['Operate a till',         'pos.operate',  'Reconcile a till variance', 'pos.session.reconcile',
     'A cashier who can clear their own variance is not being counted.'],
    ['Dispatch a transfer',    'transfer.dispatch', 'Receive a transfer',  'transfer.receive',
     'One person at both ends of a branch movement can send nine and book ten.'],
    ['Change a shelf price',   'products.price', 'Review audit exceptions', 'audit.review',
     'Whoever sets prices should not be the one who decides a pricing exception was fine.'],
];

$roles = qa('SELECT r.*,
                    (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.is_active = 1) AS holders
               FROM roles r ORDER BY r.name');

$rolePerms = [];
foreach (qa('SELECT role_id, permission_code FROM role_permissions') as $rp) {
    $rolePerms[(int) $rp['role_id']][(string) $rp['permission_code']] = true;
}

/* ---- Practice: cases where one person did both ----------------- */
// Each query looks for a document whose approver is its originator. These should
// all come back empty, because the posting routines throw on it. They are run
// anyway - a control you have not tested is a control you are hoping for.
$breaches = [];

// Every column is qualified with the table alias. `users` also has created_at
// and status columns, so an unqualified reference is ambiguous once joined.
foreach ([
    ['Purchase orders', 'purchase_orders', 'po_no', 'created_by', 'approved_by',
     't.order_date', 'inventory/purchase-order', "t.status <> 'cancelled'"],
    // self_approved = 0 is the point: a refund the raiser posted at the till
    // under the limit needed no second person, so it is not a breach of a
    // control that never engaged. One that queued for approval and was then
    // approved by its own raiser is.
    ['Refunds', 'refunds', 'refund_no', 'requested_by', 'approved_by',
     'DATE(t.created_at)', 'sales/refunds', "t.status = 'posted' AND t.self_approved = 0"],
    ['Stock counts', 'stock_counts', 'count_no', 'counted_by', 'approved_by',
     'DATE(t.posted_at)', 'inventory/count', "t.status = 'posted'"],
    ['Wastage', 'wastage', 'waste_no', 'logged_by', 'approved_by',
     'DATE(t.created_at)', 'inventory/wastage', "t.status = 'posted'"],
    ['Adjustments', 'stock_adjustments', 'adj_no', 'created_by', 'approved_by',
     'DATE(t.created_at)', 'inventory/adjustment', "t.status = 'posted'"],
] as [$label, $table, $noCol, $byCol, $approveCol, $dateCol, $urlPath, $extra]) {
    $storeFilter = $table === 'refunds' || $table === 'wastage' || $table === 'stock_adjustments'
                   || $table === 'stock_counts' || $table === 'purchase_orders'
        ? 'AND t.store_id = ?' : '';
    $params = [$from, $to];
    if ($storeFilter !== '') {
        $params[] = $storeId;
    }
    $rows = qa(
        "SELECT t.id, t.$noCol AS doc_no, t.$byCol AS actor, u.full_name AS actor_name,
                $dateCol AS doc_date
           FROM $table t JOIN users u ON u.id = t.$byCol
          WHERE t.$approveCol IS NOT NULL AND t.$approveCol = t.$byCol
            AND $extra AND $dateCol BETWEEN ? AND ? $storeFilter
          ORDER BY t.id DESC LIMIT 50",
        $params
    );
    if ($rows) {
        $breaches[$label] = ['rows' => $rows, 'url' => $urlPath];
    }
}

// Till sessions reconciled by the cashier whose drawer it was.
$selfReconciled = qa(
    'SELECT ts.id, ts.session_no AS doc_no, ts.variance, u.full_name AS actor_name,
            DATE(ts.opened_at) AS doc_date
       FROM till_sessions ts JOIN users u ON u.id = ts.user_id
      WHERE ts.store_id = ? AND ts.reconciled_by = ts.user_id
        AND ts.status = \'reconciled\'
        AND ABS(COALESCE(ts.variance, 0)) > ?
        AND DATE(ts.opened_at) BETWEEN ? AND ?
      ORDER BY ABS(ts.variance) DESC LIMIT 50',
    [$storeId, setting_num('till_variance_tolerance', (float) TILL_VARIANCE_TOLERANCE), $from, $to]
);

// Transfers dispatched and received by the same person.
$selfTransfers = qa(
    'SELECT t.id, t.transfer_no AS doc_no, u.full_name AS actor_name,
            DATE(t.dispatched_at) AS doc_date
       FROM stock_transfers t JOIN users u ON u.id = t.dispatched_by
      WHERE t.dispatched_by = t.received_by AND t.status = \'received\'
        AND DATE(t.dispatched_at) BETWEEN ? AND ?
      ORDER BY t.id DESC LIMIT 50',
    [$from, $to]
);

$sodExceptions = qa(
    'SELECT ae.*, u.full_name AS subject_name FROM audit_exceptions ae
       LEFT JOIN users u ON u.id = ae.subject_user_id
      WHERE ae.rule_code = \'SOD_SELF_APPROVAL\' ORDER BY ae.detected_at DESC LIMIT 25'
);

$totalBreaches = array_sum(array_map(fn ($b) => count($b['rows']), $breaches))
                 + count($selfReconciled) + count($selfTransfers);

layout_head([
    'title'    => 'Segregation of duties',
    'subtitle' => $totalBreaches === 0
        ? 'No case found where one person stood on both sides of a control'
        : $totalBreaches . ' case(s) need explaining',
    'actions'  => '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<?php if ($totalBreaches === 0): ?>
  <div class="verdict verdict--ok">
    <h2>No duty-segregation breaches found</h2>
    <p>
      Across <?= e(date_fmt($from)) ?> to <?= e(date_fmt($to)) ?>, every approved document was
      approved by someone other than the person who raised it, and every till variance outside
      tolerance was signed off by someone other than the cashier.
    </p>
  </div>
<?php else: ?>
  <div class="verdict verdict--broken">
    <h2><?= $totalBreaches ?> case(s) where one person stood on both sides</h2>
    <p>
      The posting routines refuse self-approval, so each of these was either permitted by an
      explicit override or indicates a defect. Both need explaining.
    </p>
  </div>
<?php endif; ?>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">From</span>
    <input type="date" name="from" value="<?= e($from) ?>">
  </label>
  <label class="field">
    <span class="field__label">To</span>
    <input type="date" name="to" value="<?= e($to) ?>">
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('audit/sod')) ?>">Last 90 days</a>
</form>

<!-- ================= Design ================= -->
<?= card_open('Which roles hold both sides of a control') ?>
  <p class="small muted">
    A role holding both halves of a pair below can, in principle, complete that control alone. The
    application still refuses it for a specific document &mdash; the originator can never be the
    approver, whatever permissions they hold &mdash; so a conflict here means the role could
    approve <em>someone else's</em> document of a kind it also raises. That is usually legitimate
    for a manager and rarely so for anyone else.
  </p>

  <div class="table-wrap">
  <table class="data">
    <thead><tr>
      <th>Control</th><th>Raising</th><th>Approving</th>
      <?php foreach ($roles as $r): ?>
        <th class="num" title="<?= e((string) $r['description']) ?>">
          <?= e($r['name']) ?><br><span class="tiny muted"><?= (int) $r['holders'] ?> staff</span>
        </th>
      <?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($conflicts as [$raiseLabel, $raisePerm, $approveLabel, $approvePerm, $why]): ?>
      <tr>
        <td>
          <?= e($raiseLabel) ?> / <?= e($approveLabel) ?>
          <br><span class="tiny muted"><?= e($why) ?></span>
        </td>
        <td class="mono tiny"><?= e($raisePerm) ?></td>
        <td class="mono tiny"><?= e($approvePerm) ?></td>
        <?php foreach ($roles as $r):
            $rid     = (int) $r['id'];
            $hasBoth = isset($rolePerms[$rid][$raisePerm]) && isset($rolePerms[$rid][$approvePerm]);
            $hasOne  = isset($rolePerms[$rid][$raisePerm]) || isset($rolePerms[$rid][$approvePerm]); ?>
          <td class="num center">
            <?php if ($hasBoth): ?>
              <?= badge('Both', $r['code'] === 'admin' ? 'warn' : 'bad') ?>
            <?php elseif ($hasOne): ?>
              <span class="tiny muted"><?= isset($rolePerms[$rid][$raisePerm]) ? 'raise' : 'approve' ?></span>
            <?php else: ?>
              <span class="tiny muted">&mdash;</span>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <p class="small muted mt1">
    The System Administrator role holds everything by definition, which is why it is marked amber
    rather than red: it is a break-glass account, and the control over it is that there are very
    few of them and everything they do is in the trail. The Internal Auditor role deliberately
    holds <strong>no</strong> permission that creates or approves a transaction &mdash; an auditor
    who can post stock is not an auditor.
  </p>
<?= card_close() ?>

<!-- ================= Practice ================= -->
<?= card_open('Cases where one person did both') ?>
  <?php if ($totalBreaches === 0): ?>
    <?= empty_state('None found in the selected period.',
          'This list being empty is what makes the table above meaningful.') ?>
  <?php else: ?>
    <?php foreach ($breaches as $label => $b): ?>
      <h3 class="mb1"><?= e($label) ?> (<?= count($b['rows']) ?>)</h3>
      <div class="table-wrap mb1">
      <table class="data data--tight">
        <thead><tr><th>Document</th><th>Date</th><th>Raised and approved by</th></tr></thead>
        <tbody>
        <?php foreach ($b['rows'] as $row): ?>
          <tr class="row-bad">
            <td class="mono tiny">
              <a href="<?= e(base_url($b['url'], ['id' => (int) $row['id']])) ?>">
                <?= e((string) $row['doc_no']) ?></a>
            </td>
            <td class="nowrap tiny"><?= e(date_fmt((string) $row['doc_date'])) ?></td>
            <td><?= e((string) $row['actor_name']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endforeach; ?>

    <?php if ($selfReconciled): ?>
      <h3 class="mb1">Till variances signed off by the cashier (<?= count($selfReconciled) ?>)</h3>
      <div class="table-wrap mb1">
      <table class="data data--tight">
        <thead><tr><th>Session</th><th>Date</th><th>Cashier</th><th class="num">Variance</th></tr></thead>
        <tbody>
        <?php foreach ($selfReconciled as $s): ?>
          <tr class="row-bad">
            <td class="mono tiny">
              <a href="<?= e(base_url('pos/session', ['id' => (int) $s['id']])) ?>">
                <?= e((string) $s['doc_no']) ?></a>
            </td>
            <td class="nowrap tiny"><?= e(date_fmt((string) $s['doc_date'])) ?></td>
            <td><?= e((string) $s['actor_name']) ?></td>
            <?= num_cell($s['variance'], true, true) ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>

    <?php if ($selfTransfers): ?>
      <h3 class="mb1">Transfers dispatched and received by the same person (<?= count($selfTransfers) ?>)</h3>
      <div class="table-wrap">
      <table class="data data--tight">
        <thead><tr><th>Transfer</th><th>Date</th><th>Person</th></tr></thead>
        <tbody>
        <?php foreach ($selfTransfers as $t): ?>
          <tr class="row-bad">
            <td class="mono tiny">
              <a href="<?= e(base_url('inventory/transfer', ['id' => (int) $t['id']])) ?>">
                <?= e((string) $t['doc_no']) ?></a>
            </td>
            <td class="nowrap tiny"><?= e(date_fmt((string) $t['doc_date'])) ?></td>
            <td><?= e((string) $t['actor_name']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>
<?= card_close() ?>

<?php if ($sodExceptions): ?>
<?= card_open('Self-approval overrides recorded') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr><th>When</th><th>Who</th><th>What</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($sodExceptions as $ex): ?>
      <tr class="row-bad">
        <td class="nowrap tiny"><?= e(date_fmt((string) $ex['detected_at'], true)) ?></td>
        <td><?= e($ex['subject_name'] ?? '-') ?></td>
        <td>
          <a href="<?= e(base_url('audit/exception', ['id' => (int) $ex['id']])) ?>">
            <?= e($ex['summary']) ?></a>
        </td>
        <td><?= status_badge((string) $ex['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="small muted mt1">
    A sole-manager store with nobody else holding the permission would otherwise deadlock, so the
    application allows a self-approval under an explicit override &mdash; and records it here at
    critical severity rather than letting it pass silently. Allowing it quietly would hide the
    breach; refusing it outright would stop the shop trading.
  </p>
<?= card_close() ?>
<?php endif; ?>

<div class="sign-off">
  <div>Reviewed by: ________________________</div>
  <div>Date: ________________________</div>
</div>

<?php layout_foot(); ?>
