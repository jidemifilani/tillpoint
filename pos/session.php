<?php
/**
 * One till session: the X-report while it is open, the Z-report once closed,
 * and the close/reconcile actions.
 *
 * The distinction matters. An X-report is a mid-shift read that changes nothing
 * and can be taken as often as you like. Closing is a one-way act: the drawer
 * is counted, the variance is fixed, and the figures stop moving.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$storeId = current_store_id();
$id      = get_int('id');

// No id means "my open shift", which is what a cashier wants from the menu.
if ($id === 0) {
    $mine = session_current();
    if ($mine === null) {
        flash_info('You have no open till session.');
        redirect('pos/sessions');
    }
    $id = (int) $mine['id'];
}

$session = q1(
    'SELECT ts.*, u.full_name AS cashier, u.staff_no, t.code AS terminal_code,
            st.name AS store_name, cb.full_name AS closed_by_name, rb.full_name AS reconciled_by_name
       FROM till_sessions ts
       JOIN users u ON u.id = ts.user_id
       JOIN terminals t ON t.id = ts.terminal_id
       JOIN stores st ON st.id = ts.store_id
       LEFT JOIN users cb ON cb.id = ts.closed_by
       LEFT JOIN users rb ON rb.id = ts.reconciled_by
      WHERE ts.id = ?',
    [$id]
);
if (!$session) {
    http_response_code(404);
    require APP_ROOT . '/404.php';
    exit;
}

$isMine = (int) $session['user_id'] === (int) $_SESSION['uid'];
// A cashier sees their own shifts; anyone who can reconcile or read reports sees
// all of them. Without that split a cashier could read the whole shop's cash.
if (!$isMine && !can_any(['pos.session.reconcile', 'reports.view', 'audit.view'])) {
    deny_403('You can only open your own till sessions.');
}

if (is_post()) {
    csrf_require();
    $action = (string) post('action');
    try {
        if ($action === 'close') {
            require_can('pos.session.close');
            if (!$isMine && !can('pos.session.reconcile')) {
                throw new PosError('You can only close your own till.');
            }
            $result = session_close($id, post_num('declared_cash'), (int) $_SESSION['uid'], (string) post('note'));

            if ($result['within_tolerance']) {
                flash_ok(sprintf('Till closed and balanced. Variance %s, within tolerance.',
                    money((float) $result['variance'])));
            } else {
                flash_warn(sprintf('Till closed %s %s. A manager must sign this off before it is settled.',
                    money(abs((float) $result['variance'])),
                    (float) $result['variance'] < 0 ? 'short' : 'over'));
            }
            redirect('pos/session', ['id' => $id]);
        }

        if ($action === 'reconcile') {
            require_can('pos.session.reconcile');
            session_reconcile($id, (int) $_SESSION['uid'], (string) post('note'));
            flash_ok('Variance signed off.');
            redirect('pos/session', ['id' => $id]);
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('pos/session', ['id' => $id]);
    }
}

$report = session_report($id);
$cash   = $report['cash'];
$isOpen = $session['status'] === 'open';

$movements = qa(
    'SELECT cm.*, u.full_name AS who, a.full_name AS authorised_by_name,
            COALESCE(rc.label, cm.reason_code) AS reason_label
       FROM cash_movements cm
       JOIN users u ON u.id = cm.user_id
       LEFT JOIN users a ON a.id = cm.authorised_by
       LEFT JOIN reason_codes rc ON rc.code = cm.reason_code
      WHERE cm.session_id = ? ORDER BY cm.created_at',
    [$id]
);
$sales = qa(
    'SELECT s.id, s.receipt_no, s.status, s.total, s.discount_total, s.line_count,
            s.sold_at, s.void_reason, c.name AS customer
       FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
      WHERE s.session_id = ? ORDER BY s.id DESC',
    [$id]
);
$refunds = qa(
    'SELECT r.*, s.receipt_no, u.full_name AS requested_by_name
       FROM refunds r JOIN sales s ON s.id = r.sale_id
       JOIN users u ON u.id = r.requested_by
      WHERE r.session_id = ? ORDER BY r.created_at DESC',
    [$id]
);

$tolerance = setting_num('till_variance_tolerance', (float) TILL_VARIANCE_TOLERANCE);

layout_head([
    'title'    => ($isOpen ? 'X-report' : 'Z-report') . ' - ' . $session['session_no'],
    'subtitle' => $session['cashier'] . ' on ' . $session['terminal_code'] . ' - '
                . date_fmt((string) $session['opened_at'], true),
    'actions'  => '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<?php if ($isOpen): ?>
  <div class="alert alert--info">
    This shift is still open, so these figures are a snapshot and will keep moving.
    Nothing here has been committed &mdash; taking an X-report changes nothing.
  </div>
<?php elseif ($session['status'] === 'closed'): ?>
  <div class="alert alert--warning">
    Closed with a variance of <strong><?= e(money((float) $session['variance'])) ?></strong>,
    which is outside the <?= e(money($tolerance)) ?> tolerance. It needs signing off by
    someone other than <?= e($session['cashier']) ?>.
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Transactions', number_format($report['txn_count']),
        qty_fmt($report['item_count']) . ' items') ?>
  <?= tile('Gross takings', money($report['gross_sales']),
        'average basket ' . money($report['avg_basket'])) ?>
  <?= tile('Gross profit', money($report['gross_profit']),
        pct_fmt($report['margin_pct']) . ' margin', 'ok') ?>
  <?= tile('Voids', number_format($report['void_count']),
        money($report['void_value']) . ' abandoned',
        $report['void_count'] > 5 ? 'warn' : 'mute') ?>
  <?= tile('Refunds', number_format($report['refund_count']),
        money($report['refund_value']) . ' returned',
        $report['refund_value'] > 0 ? 'warn' : 'mute') ?>
  <?= tile('Discounts given', money($report['discounts']), 'off the shelf price',
        $report['discounts'] > 0 ? 'warn' : 'mute') ?>
</div>

<div class="grid-2">
  <!-- ---- Cash reconciliation ---------------------------------- -->
  <?= card_open('Cash in the drawer') ?>
    <table class="data data--tight">
      <tbody>
        <tr><td>Opening float</td><?= num_cell($cash['opening_float']) ?></tr>
        <tr><td>Cash sales</td><?= num_cell($cash['cash_sales']) ?></tr>
        <tr><td>Paid in</td><?= num_cell($cash['pay_in']) ?></tr>
        <tr><td>Cash refunds</td><?= num_cell(-(float) $cash['cash_refunds']) ?></tr>
        <tr><td>Paid out</td><?= num_cell(-(float) $cash['pay_out']) ?></tr>
        <tr><td>Dropped to safe</td><?= num_cell(-(float) $cash['drops']) ?></tr>
      </tbody>
      <tfoot>
        <tr><td>Should be in the drawer</td><?= num_cell($cash['expected']) ?></tr>
        <?php if (!$isOpen): ?>
          <tr><td>Counted and declared</td><?= num_cell($session['declared_cash'] ?? 0) ?></tr>
          <tr>
            <td>Variance</td>
            <?= num_cell($session['variance'] ?? 0, true, true) ?>
          </tr>
        <?php endif; ?>
      </tfoot>
    </table>

    <?php if (!$isOpen): ?>
      <dl class="kv mt1">
        <dt>Closed</dt><dd><?= e(date_fmt((string) $session['closed_at'], true)) ?>
          by <?= e($session['closed_by_name'] ?? '-') ?></dd>
        <?php if ($session['reconciled_at'] !== null): ?>
          <dt>Signed off</dt><dd><?= e(date_fmt((string) $session['reconciled_at'], true)) ?>
            by <?= e($session['reconciled_by_name'] ?? '-') ?></dd>
        <?php endif; ?>
        <?php if ($session['note'] !== null): ?>
          <dt>Note</dt><dd><?= e($session['note']) ?></dd>
        <?php endif; ?>
      </dl>
    <?php endif; ?>
  <?= card_close() ?>

  <!-- ---- Payment mix ------------------------------------------ -->
  <?= card_open('How it was paid for') ?>
    <?php if (!$report['by_method']): ?>
      <?= empty_state('No payments taken on this shift.') ?>
    <?php else:
        $paidTotal = array_sum(array_map('floatval', $report['by_method'])) ?: 1.0; ?>
      <table class="data data--tight">
        <thead><tr><th>Method</th><th class="num">Amount</th><th>Share</th></tr></thead>
        <tbody>
        <?php foreach ($report['by_method'] as $m => $amt):
            $pct = ((float) $amt / $paidTotal) * 100; ?>
          <tr>
            <td><?= e(ucfirst((string) $m)) ?></td>
            <?= num_cell($amt) ?>
            <td><div class="bar"><span style="width:<?= (int) round($pct) ?>%"></span></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Total</td><td class="num"><?= e(money($paidTotal)) ?></td><td></td></tr></tfoot>
      </table>
    <?php endif; ?>
  <?= card_close() ?>
</div>

<!-- ---- Close / reconcile ------------------------------------- -->
<?php if ($isOpen && ($isMine || can('pos.session.reconcile')) && can('pos.session.close')): ?>
  <?= card_open('Close this till') ?>
    <p class="small muted">
      Count the drawer and enter what is actually in it. The system already knows what it should
      be &mdash; do not look it up first. A declared figure that matches the expected one to the naira
      every single shift is itself a finding.
    </p>
    <form method="post" data-confirm="Close this till? The figures are fixed once you do.">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="close">
      <div class="form-grid">
        <label class="field">
          <span class="field__label">Cash counted in the drawer</span>
          <input type="text" name="declared_cash" class="num-in" inputmode="decimal" required data-autofocus>
        </label>
        <label class="field">
          <span class="field__label">Note (optional)</span>
          <input type="text" name="note" maxlength="255" placeholder="Anything that explains a difference">
        </label>
      </div>
      <button class="btn btn--lg" type="submit">Count and close the till</button>
    </form>
  <?= card_close() ?>
<?php endif; ?>

<?php if ($session['status'] === 'closed' && can('pos.session.reconcile')): ?>
  <?= card_open('Sign off the variance') ?>
    <?php if ($isMine): ?>
      <div class="alert alert--error">
        This was your own till. Segregation of duties means someone else has to sign off the
        variance &mdash; a cashier who can clear their own shortfall is not being counted.
      </div>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reconcile">
        <label class="field">
          <span class="field__label">What explains the <?= e(money(abs((float) $session['variance']))) ?>
            <?= (float) $session['variance'] < 0 ? 'shortage' : 'overage' ?>?</span>
          <textarea name="note" required
            placeholder="e.g. miscounted change on a 10,000 note, confirmed against the CCTV"></textarea>
          <span class="field__hint">Required. The grounds are kept even if a later reviewer disagrees.</span>
        </label>
        <button class="btn" type="submit">Sign off as reconciled</button>
      </form>
    <?php endif; ?>
  <?= card_close() ?>
<?php endif; ?>

<!-- ---- Cash movements --------------------------------------- -->
<?= card_open('Cash movements (' . count($movements) . ')',
      $isOpen && $isMine && can('pos.cash_movement')
        ? '<a class="btn btn--sm btn--ghost" href="' . e(base_url('pos/cash')) . '">Record one</a>' : '') ?>
  <?php if (!$movements): ?>
    <?= empty_state('No cash movements on this shift.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>When</th><th>Type</th><th class="num">Amount</th><th>Reason</th>
        <th>By</th><th>Authorised by</th><th>Note</th>
      </tr></thead>
      <tbody>
      <?php foreach ($movements as $m): ?>
        <tr>
          <td class="nowrap tiny"><?= e(date('H:i', strtotime((string) $m['created_at']))) ?></td>
          <td><?= e(ucfirst(str_replace('_', ' ', (string) $m['type']))) ?></td>
          <?= num_cell($m['amount']) ?>
          <td class="tiny"><?= e($m['reason_label'] ?? '-') ?></td>
          <td class="tiny"><?= e($m['who']) ?></td>
          <td class="tiny"><?= e($m['authorised_by_name'] ?? '-') ?></td>
          <td class="tiny muted"><?= e($m['note'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<!-- ---- Refunds ---------------------------------------------- -->
<?php if ($refunds): ?>
<?= card_open('Refunds on this shift (' . count($refunds) . ')') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Refund</th><th>Against</th><th class="num">Amount</th><th>Method</th>
      <th>Reason</th><th>Raised by</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($refunds as $r): ?>
      <tr>
        <td class="mono tiny"><?= e($r['refund_no']) ?></td>
        <td class="mono tiny"><?= e($r['receipt_no']) ?></td>
        <?= num_cell($r['total']) ?>
        <td class="tiny"><?= e(ucfirst((string) $r['method'])) ?></td>
        <td class="tiny"><?= e($r['reason_code']) ?></td>
        <td class="tiny"><?= e($r['requested_by_name']) ?></td>
        <td><?= status_badge((string) $r['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<!-- ---- Every transaction ------------------------------------ -->
<?= card_open('Transactions (' . count($sales) . ')') ?>
  <?php if (!$sales): ?>
    <?= empty_state('Nothing rung up on this shift yet.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>Receipt</th><th>Time</th><th class="num">Items</th><th class="num">Discount</th>
        <th class="num">Total</th><th>Customer</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($sales as $s): ?>
        <tr class="<?= $s['status'] === 'voided' ? 'row-warn' : '' ?>">
          <td class="mono tiny">
            <a href="<?= e(base_url('sales/view', ['id' => (int) $s['id']])) ?>"><?= e($s['receipt_no']) ?></a>
          </td>
          <td class="nowrap tiny"><?= e($s['sold_at'] !== null ? date('H:i', strtotime((string) $s['sold_at'])) : '-') ?></td>
          <td class="num"><?= (int) $s['line_count'] ?></td>
          <?= num_cell($s['discount_total']) ?>
          <?= num_cell($s['total']) ?>
          <td class="tiny"><?= e($s['customer'] ?? '') ?></td>
          <td><?= status_badge((string) $s['status']) ?>
            <?php if ($s['void_reason'] !== null): ?>
              <span class="tiny muted"><?= e($s['void_reason']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?php if (!$isOpen): ?>
  <div class="sign-off">
    <div>Cashier: <?= e($session['cashier']) ?> (<?= e($session['staff_no']) ?>)</div>
    <div>Checked by: ________________________</div>
  </div>
<?php endif; ?>

<?php layout_foot(); ?>
