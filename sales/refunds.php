<?php
/**
 * The refund queue: pending approvals first, then history.
 *
 * The approve and reject buttons are hidden on a refund the viewer raised
 * themselves, and refund_approve() refuses it anyway. Both, deliberately: the
 * hidden button is courtesy, the server-side refusal is the control.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('refund.request');

$storeId = current_store_id();

if (is_post()) {
    csrf_require();
    require_can('refund.approve');
    $refundId = post_int('refund_id');
    try {
        if ((string) post('action') === 'approve') {
            refund_approve($refundId, (int) $_SESSION['uid']);
            flash_ok('Refund approved and posted. The money and the stock have both moved.');
        } else {
            $note = (string) post('note');
            if (trim($note) === '') {
                throw new PosError('Say why the refund is being rejected - the cashier has to explain it to the customer.');
            }
            refund_reject($refundId, (int) $_SESSION['uid'], $note);
            flash_ok('Refund rejected.');
        }
    } catch (SodViolation $e) {
        flash_err($e->getMessage());
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect('sales/refunds');
}

$pending = qa(
    'SELECT r.*, s.receipt_no, s.sold_at, s.total AS sale_total, s.cashier_id,
            rq.full_name AS requested_by_name, c.name AS customer,
            COALESCE(rc.label, r.reason_code) AS reason_label
       FROM refunds r
       JOIN sales s ON s.id = r.sale_id
       JOIN users rq ON rq.id = r.requested_by
       LEFT JOIN customers c ON c.id = s.customer_id
       LEFT JOIN reason_codes rc ON rc.code = r.reason_code
      WHERE r.store_id = ? AND r.status = \'pending\'
      ORDER BY r.created_at',
    [$storeId]
);

$from = date_or_null(get('from')) ?? date('Y-m-d', strtotime('-30 days'));
$to   = date_or_null(get('to')) ?? date('Y-m-d');

$history = qa(
    'SELECT r.*, s.receipt_no, rq.full_name AS requested_by_name,
            ap.full_name AS approved_by_name, c.name AS customer,
            COALESCE(rc.label, r.reason_code) AS reason_label
       FROM refunds r
       JOIN sales s ON s.id = r.sale_id
       JOIN users rq ON rq.id = r.requested_by
       LEFT JOIN users ap ON ap.id = r.approved_by
       LEFT JOIN customers c ON c.id = s.customer_id
       LEFT JOIN reason_codes rc ON rc.code = r.reason_code
      WHERE r.store_id = ? AND r.status <> \'pending\'
        AND DATE(r.created_at) BETWEEN ? AND ?
      ORDER BY r.created_at DESC LIMIT 200',
    [$storeId, $from, $to]
);

$summary = q1(
    'SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS value,
            SUM(status = \'rejected\') AS rejected,
            SUM(restock = 0) AS not_restocked
       FROM refunds
      WHERE store_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status <> \'pending\'',
    [$storeId, $from, $to]
);
$byReason = qa(
    'SELECT r.reason_code, COALESCE(rc.label, r.reason_code) AS label,
            COUNT(*) AS n, COALESCE(SUM(r.total),0) AS value
       FROM refunds r LEFT JOIN reason_codes rc ON rc.code = r.reason_code
      WHERE r.store_id = ? AND DATE(r.created_at) BETWEEN ? AND ? AND r.status = \'posted\'
      GROUP BY r.reason_code, label ORDER BY value DESC',
    [$storeId, $from, $to]
);

$limit = setting_num('refund_approval_limit', (float) REFUND_APPROVAL_LIMIT);

layout_head([
    'title'    => 'Refunds',
    'subtitle' => count($pending) > 0
        ? count($pending) . ' waiting for approval'
        : 'Nothing waiting for approval',
    'actions'  => can('refund.request')
        ? '<a class="btn" href="' . e(base_url('pos/refund')) . '">Raise a refund</a>' : '',
]);
?>

<!-- ---- Pending approvals ------------------------------------- -->
<?= card_open('Waiting for approval (' . count($pending) . ')') ?>
  <?php if (!$pending): ?>
    <?= empty_state('No refunds are waiting.',
          'Refunds over ' . money($limit) . ', and any raised by staff without approval rights, land here.') ?>
  <?php else: ?>
    <?php foreach ($pending as $r):
        $isMine   = (int) $r['requested_by'] === (int) $_SESSION['uid'];
        $refLines = qa('SELECT rl.*, p.name, p.unit_code FROM refund_lines rl
                         JOIN products p ON p.id = rl.product_id
                        WHERE rl.refund_id = ?', [(int) $r['id']]); ?>
      <div class="exc exc--<?= (float) $r['total'] > $limit * 3 ? 'high' : 'medium' ?>">
        <h3>
          <?= e($r['refund_no']) ?> &mdash; <?= e(money((float) $r['total'])) ?>
          <?= badge(ucfirst((string) $r['method']), 'info') ?>
          <?= (int) $r['restock'] === 1 ? badge('Restock', 'ok') : badge('Write off', 'warn') ?>
        </h3>
        <p>
          Against <a href="<?= e(base_url('sales/view', ['id' => (int) $r['sale_id']])) ?>"><?= e($r['receipt_no']) ?></a>
          (<?= e(money((float) $r['sale_total'])) ?>, sold <?= e(date_fmt($r['sold_at'], true)) ?>)
          <?php if ($r['customer'] !== null): ?> for <?= e($r['customer']) ?><?php endif; ?>
        </p>
        <p><strong><?= e($r['reason_label']) ?></strong>
          <?php if ($r['note']): ?> &mdash; <?= e((string) $r['note']) ?><?php endif; ?></p>
        <ul class="small" style="margin:.2rem 0 .4rem 1rem">
          <?php foreach ($refLines as $l): ?>
            <li><?= e(qty_fmt((float) $l['qty'])) ?> <?= e($l['unit_code']) ?>
              <?= e($l['name']) ?> at <?= e(money((float) $l['unit_price'])) ?>
              = <?= e(money((float) $l['line_total'])) ?></li>
          <?php endforeach; ?>
        </ul>
        <div class="exc__meta">
          <span>Raised by <?= e($r['requested_by_name']) ?></span>
          <span><?= e(ago((string) $r['created_at'])) ?></span>
          <?php if ((int) $r['cashier_id'] === (int) $r['requested_by']): ?>
            <span><?= badge('Refunding own sale', 'warn') ?></span>
          <?php endif; ?>
        </div>

        <?php if (!can('refund.approve')): ?>
          <p class="small muted">Your role cannot approve refunds.</p>
        <?php elseif ($isMine): ?>
          <div class="alert alert--warning mb0" style="margin-top:.5rem">
            You raised this refund, so you cannot approve it. Another authorised user must act on it.
          </div>
        <?php else: ?>
          <div class="btn-row" style="margin-top:.5rem">
            <form method="post" class="inline-form"
                  data-confirm="Approve this refund? <?= e(money((float) $r['total'])) ?> will leave the till and the stock will move.">
              <?= csrf_field() ?>
              <input type="hidden" name="refund_id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="action" value="approve">
              <button class="btn btn--sm" type="submit">Approve and post</button>
            </form>
            <form method="post" class="btn-row" style="gap:.35rem">
              <?= csrf_field() ?>
              <input type="hidden" name="refund_id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="action" value="reject">
              <input type="text" name="note" placeholder="Why it is being rejected"
                     style="max-width:280px" required>
              <button class="btn btn--sm btn--danger" type="submit">Reject</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?= card_close() ?>

<!-- ---- History ----------------------------------------------- -->
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
</form>

<div class="tiles">
  <?= tile('Refunds in period', number_format((int) $summary['n']),
        money((float) $summary['value']) . ' returned') ?>
  <?= tile('Rejected', number_format((int) $summary['rejected']), 'turned down on review', 'mute') ?>
  <?= tile('Written off', number_format((int) $summary['not_restocked']),
        'money back, stock not resaleable',
        (int) $summary['not_restocked'] > 0 ? 'warn' : 'mute') ?>
  <?= tile('Approval limit', money($limit), 'above this needs a supervisor', 'info') ?>
</div>

<div class="grid-2">
  <?= card_open('Why things came back') ?>
    <?php if (!$byReason): ?>
      <?= empty_state('No posted refunds in that period.') ?>
    <?php else:
        $maxVal = max(array_map(fn ($r) => (float) $r['value'], $byReason)) ?: 1.0; ?>
      <table class="data data--tight">
        <thead><tr><th>Reason</th><th class="num">Count</th><th class="num">Value</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($byReason as $r): ?>
          <tr>
            <td><?= e($r['label']) ?></td>
            <td class="num"><?= (int) $r['n'] ?></td>
            <?= num_cell($r['value']) ?>
            <td><div class="bar bar--warn" style="max-width:120px">
              <span style="width:<?= (int) round(((float) $r['value'] / $maxVal) * 100) ?>%"></span>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?= card_close() ?>

  <?= card_open('Refund history') ?>
    <?php if (!$history): ?>
      <?= empty_state('No refunds in that period.') ?>
    <?php else: ?>
      <div class="table-wrap">
      <table class="data data--tight">
        <thead><tr>
          <th>Refund</th><th>Receipt</th><th class="num">Amount</th>
          <th>Raised by</th><th>Approved by</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($history as $r):
            $selfApproved = $r['approved_by'] !== null
                            && (int) $r['approved_by'] === (int) $r['requested_by']
                            && (int) ($r['self_approved'] ?? 0) === 0;
            $tillAuthorised = (int) ($r['self_approved'] ?? 0) === 1; ?>
          <tr class="<?= $selfApproved ? 'row-bad' : '' ?>">
            <td class="mono tiny"><?= e($r['refund_no']) ?></td>
            <td class="mono tiny">
              <a href="<?= e(base_url('sales/view', ['id' => (int) $r['sale_id']])) ?>"><?= e($r['receipt_no']) ?></a>
            </td>
            <?= num_cell($r['total']) ?>
            <td class="tiny"><?= e($r['requested_by_name']) ?></td>
            <td class="tiny">
              <?= e($r['approved_by_name'] ?? '-') ?>
              <?php if ($selfApproved): ?><br><?= badge('Self-approved', 'bad') ?>
              <?php elseif ($tillAuthorised): ?><br><?= badge('Till authorised', 'neutral') ?><?php endif; ?>
            </td>
            <td><?= status_badge((string) $r['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  <?= card_close() ?>
</div>

<?php layout_foot(); ?>
