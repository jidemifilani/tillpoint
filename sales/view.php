<?php
/**
 * One sale, in full: lines, payments, the stock it moved, refunds against it,
 * and the audit entries it produced.
 *
 * This is the page an auditor opens when an exception points at a transaction,
 * so it shows the cost and margin of every line and the batches the goods came
 * out of - the things a receipt deliberately does not.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('sales.view');

$id   = get_int('id');
$sale = q1(
    'SELECT s.*, u.full_name AS cashier, u.staff_no, t.code AS till,
            st.name AS store_name, c.name AS customer, c.code AS customer_code,
            c.loyalty_card, vb.full_name AS voided_by_name, ts.session_no
       FROM sales s
       JOIN users u ON u.id = s.cashier_id
       JOIN stores st ON st.id = s.store_id
       LEFT JOIN terminals t ON t.id = s.terminal_id
       LEFT JOIN customers c ON c.id = s.customer_id
       LEFT JOIN users vb ON vb.id = s.voided_by
       LEFT JOIN till_sessions ts ON ts.id = s.session_id
      WHERE s.id = ?',
    [$id]
);
if (!$sale) {
    http_response_code(404);
    require APP_ROOT . '/404.php';
    exit;
}

$lines = qa(
    'SELECT sl.*, p.sku, p.unit_code, p.name AS current_name, pr.name AS promo_name,
            ob.full_name AS overridden_by_name, b.batch_no, b.expiry_date,
            COALESCE(rc.label, sl.discount_reason) AS discount_label
       FROM sale_lines sl
       JOIN products p ON p.id = sl.product_id
       LEFT JOIN promotions pr ON pr.id = sl.promotion_id
       LEFT JOIN users ob ON ob.id = sl.overridden_by
       LEFT JOIN stock_batches b ON b.id = sl.batch_id
       LEFT JOIN reason_codes rc ON rc.code = sl.discount_reason
      WHERE sl.sale_id = ? ORDER BY sl.id',
    [$id]
);
$payments = qa('SELECT * FROM sale_payments WHERE sale_id = ? ORDER BY id', [$id]);
$refunds  = qa(
    'SELECT r.*, rq.full_name AS requested_by_name, ap.full_name AS approved_by_name
       FROM refunds r
       JOIN users rq ON rq.id = r.requested_by
       LEFT JOIN users ap ON ap.id = r.approved_by
      WHERE r.sale_id = ? ORDER BY r.id DESC',
    [$id]
);
$movements = qa(
    'SELECT sm.*, p.name FROM stock_movements sm
       JOIN products p ON p.id = sm.product_id
      WHERE sm.ref_type = \'sale\' AND sm.ref_id = ? ORDER BY sm.id',
    [$id]
);
$trail = can('audit.trail')
    ? qa('SELECT * FROM audit_log WHERE entity = \'sale\' AND entity_id = ? ORDER BY id', [$id])
    : [];
$exceptions = can('audit.view')
    ? qa('SELECT ae.*, ar.name AS rule_name FROM audit_exceptions ae
           JOIN audit_rules ar ON ar.code = ae.rule_code
          WHERE ae.entity = \'sale\' AND ae.entity_id = ?', [$id])
    : [];

$net        = (float) $sale['subtotal'];
$cost       = (float) $sale['cost_total'];
$profit     = $net - $cost;
$marginPct  = $net > 0 ? ($profit / $net) * 100 : 0.0;
$canRefund  = can('refund.request')
              && in_array($sale['status'], ['completed', 'partly_refunded'], true);

$actions = '<a class="btn btn--ghost" href="' . e(base_url('pos/receipt', ['id' => $id])) . '">Receipt</a>';
if ($canRefund) {
    $actions .= '<a class="btn btn--warn" href="' . e(base_url('pos/refund', ['sale_id' => $id])) . '">Refund</a>';
}

layout_head([
    'title'    => 'Receipt ' . $sale['receipt_no'],
    'subtitle' => $sale['store_name'] . ' - ' . date_fmt($sale['sold_at'] ?? $sale['created_at'], true),
    'actions'  => $actions,
]);
?>

<?php if ($sale['status'] === 'voided'): ?>
  <div class="alert alert--warning">
    Voided by <?= e($sale['voided_by_name'] ?? 'unknown') ?> on
    <?= e(date_fmt((string) $sale['voided_at'], true)) ?>
    &mdash; reason <strong><?= e((string) $sale['void_reason']) ?></strong>.
    <?php if ($sale['void_note']): ?><?= e((string) $sale['void_note']) ?><?php endif; ?>
    Nothing was charged and the stock went back on the shelf.
  </div>
<?php elseif ($sale['status'] === 'parked'): ?>
  <div class="alert alert--info">
    This basket has not been paid for. The stock on it is held in reserve and cannot be sold
    on another till until the basket is completed or voided.
  </div>
<?php endif; ?>

<?php foreach ($exceptions as $ex): ?>
  <div class="alert alert--error">
    <strong>Audit exception:</strong> <?= e($ex['rule_name']) ?> &mdash; <?= e($ex['summary']) ?>
    <a href="<?= e(base_url('audit/exception', ['id' => (int) $ex['id']])) ?>">Review</a>
  </div>
<?php endforeach; ?>

<div class="tiles">
  <?= tile('Total charged', money((float) $sale['total']),
        (int) $sale['line_count'] . ' line(s)') ?>
  <?= tile('Net of VAT', money($net), 'VAT ' . money((float) $sale['vat_total'])) ?>
  <?= tile('Cost of goods', money($cost), 'at the time of sale', 'mute') ?>
  <?= tile('Gross profit', money($profit), pct_fmt($marginPct) . ' margin',
        $marginPct < 0 ? 'bad' : ($marginPct < 10 ? 'warn' : 'ok')) ?>
  <?php if ((float) $sale['discount_total'] > 0): ?>
    <?= tile('Discount given', money((float) $sale['discount_total']), 'off shelf price', 'warn') ?>
  <?php endif; ?>
  <?php if ((float) $sale['refunded_total'] > 0): ?>
    <?= tile('Refunded', money((float) $sale['refunded_total']), 'back to the customer', 'bad') ?>
  <?php endif; ?>
</div>

<div class="grid-2">
  <?= card_open('Transaction') ?>
    <dl class="kv">
      <dt>Receipt</dt><dd class="mono"><?= e($sale['receipt_no']) ?></dd>
      <dt>Status</dt><dd><?= status_badge((string) $sale['status']) ?></dd>
      <dt>Trading day</dt><dd><?= e(date_fmt((string) $sale['business_date'])) ?></dd>
      <dt>Rung up</dt><dd><?= e(date_fmt($sale['sold_at'], true)) ?></dd>
      <dt>Cashier</dt><dd><?= e($sale['cashier']) ?> <span class="tiny muted"><?= e($sale['staff_no']) ?></span></dd>
      <dt>Till</dt><dd class="mono"><?= e($sale['till'] ?? '-') ?></dd>
      <?php if ($sale['session_no'] !== null): ?>
        <dt>Shift</dt><dd class="mono">
          <a href="<?= e(base_url('pos/session', ['id' => (int) $sale['session_id']])) ?>">
            <?= e($sale['session_no']) ?></a></dd>
      <?php endif; ?>
      <?php if ($sale['customer'] !== null): ?>
        <dt>Customer</dt><dd>
          <a href="<?= e(base_url('sales/customer', ['id' => (int) $sale['customer_id']])) ?>">
            <?= e($sale['customer']) ?></a>
          <?php if ($sale['loyalty_card']): ?>
            <span class="tiny muted"><?= e((string) $sale['loyalty_card']) ?></span>
          <?php endif; ?>
        </dd>
      <?php endif; ?>
      <?php if ((float) $sale['points_earned'] > 0 || (float) $sale['points_redeemed'] > 0): ?>
        <dt>Loyalty</dt><dd>
          <?= e(number_format((float) $sale['points_earned'], 0)) ?> earned,
          <?= e(number_format((float) $sale['points_redeemed'], 0)) ?> redeemed
        </dd>
      <?php endif; ?>
      <?php if ($sale['note']): ?>
        <dt>Note</dt><dd><?= e((string) $sale['note']) ?></dd>
      <?php endif; ?>
    </dl>
  <?= card_close() ?>

  <?= card_open('Payment') ?>
    <?php if (!$payments): ?>
      <?= empty_state('No payment taken.',
            $sale['status'] === 'parked' ? 'This basket has not been paid for.' : '') ?>
    <?php else: ?>
      <table class="data data--tight">
        <thead><tr><th>Method</th><th class="num">Applied</th><th class="num">Tendered</th><th>Reference</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td><?= e(ucfirst((string) $p['method'])) ?></td>
            <?= num_cell($p['amount']) ?>
            <td class="num"><?= e($p['tendered'] !== null ? money((float) $p['tendered']) : '-') ?></td>
            <td class="mono tiny"><?= e($p['reference'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td>Paid</td><td class="num"><?= e(money((float) $sale['paid_total'])) ?></td>
              <td></td><td></td></tr>
          <?php if ((float) $sale['change_given'] > 0): ?>
            <tr><td>Change given</td><td class="num"><?= e(money((float) $sale['change_given'])) ?></td>
                <td></td><td></td></tr>
          <?php endif; ?>
        </tfoot>
      </table>
    <?php endif; ?>
  <?= card_close() ?>
</div>

<?= card_open('Lines') ?>
  <div class="table-wrap">
  <table class="data">
    <thead><tr>
      <th>SKU</th><th>Sold as</th><th class="num">Qty</th><th class="num">List</th>
      <th class="num">Charged</th><th class="num">Discount</th><th class="num">Line total</th>
      <th class="num">Unit cost</th><th class="num">Margin</th><th>Notes</th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $l):
        $lineNet    = (float) $l['line_total'] - (float) $l['vat_amount'];
        $lineCost   = (float) $l['qty'] * (float) $l['unit_cost'];
        $lineMargin = $lineNet > 0 ? (($lineNet - $lineCost) / $lineNet) * 100 : 0.0; ?>
      <tr class="<?= $lineMargin < 0 ? 'row-bad' : ((int) $l['price_overridden'] === 1 ? 'row-warn' : '') ?>">
        <td class="mono tiny"><?= e($l['sku']) ?></td>
        <td>
          <?= e($l['description']) ?>
          <?php if ($l['description'] !== $l['current_name']): ?>
            <br><span class="tiny muted">now called <?= e($l['current_name']) ?></span>
          <?php endif; ?>
        </td>
        <td class="num"><?= e(qty_fmt((float) $l['qty'])) ?>
          <span class="tiny muted"><?= e($l['unit_code']) ?></span>
          <?php if ((float) $l['qty_refunded'] > 0): ?>
            <br><span class="tiny bad-text">-<?= e(qty_fmt((float) $l['qty_refunded'])) ?> refunded</span>
          <?php endif; ?>
        </td>
        <?= num_cell($l['list_price']) ?>
        <?= num_cell($l['unit_price']) ?>
        <?= num_cell($l['line_discount']) ?>
        <?= num_cell($l['line_total']) ?>
        <?= num_cell($l['unit_cost']) ?>
        <td class="num <?= $lineMargin < 0 ? 'neg' : '' ?>"><?= e(pct_fmt($lineMargin)) ?></td>
        <td class="tiny">
          <?php if ($l['promo_name'] !== null): ?>
            <span class="ok-text"><?= e($l['promo_name']) ?></span><br>
          <?php endif; ?>
          <?php if ((int) $l['price_overridden'] === 1): ?>
            <span class="warn-text">Override by <?= e($l['overridden_by_name'] ?? 'unknown') ?></span><br>
          <?php endif; ?>
          <?php if ($l['discount_label'] !== null): ?>
            <?= e((string) $l['discount_label']) ?><br>
          <?php endif; ?>
          <?php if ($l['batch_no'] !== null): ?>
            <span class="muted">Batch <?= e((string) $l['batch_no']) ?>
              <?php if ($l['expiry_date'] !== null): ?>
                exp <?= e(date_fmt((string) $l['expiry_date'])) ?>
              <?php endif; ?>
            </span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="6">Totals</td>
        <td class="num"><?= e(money((float) $sale['total'])) ?></td>
        <td class="num"><?= e(money($cost)) ?></td>
        <td class="num"><?= e(pct_fmt($marginPct)) ?></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
  </div>
<?= card_close() ?>

<?php if ($refunds): ?>
<?= card_open('Refunds against this receipt') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Refund</th><th>Raised</th><th>By</th><th>Approved by</th>
      <th class="num">Amount</th><th>Method</th><th>Reason</th><th>Restocked</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($refunds as $r): ?>
      <tr>
        <td class="mono tiny"><?= e($r['refund_no']) ?></td>
        <td class="nowrap tiny"><?= e(date_fmt((string) $r['created_at'], true)) ?></td>
        <td class="tiny"><?= e($r['requested_by_name']) ?></td>
        <td class="tiny">
          <?= e($r['approved_by_name'] ?? '-') ?>
          <?php if ($r['approved_by'] !== null && (int) $r['approved_by'] === (int) $r['requested_by']): ?>
            <?php if ((int) ($r['self_approved'] ?? 0) === 1): ?>
              <br><?= badge('Till authorised', 'neutral') ?>
            <?php else: ?>
              <br><?= badge('Self-approved', 'bad') ?>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <?= num_cell($r['total']) ?>
        <td class="tiny"><?= e(ucfirst((string) $r['method'])) ?></td>
        <td class="tiny"><?= e($r['reason_code']) ?></td>
        <td><?= (int) $r['restock'] === 1 ? badge('Yes', 'ok') : badge('No', 'warn') ?></td>
        <td><?= status_badge((string) $r['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?php if ($movements && can('inventory.view')): ?>
<?= card_open('Stock this sale moved') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Product</th><th>Type</th><th class="num">Qty</th><th class="num">Unit cost</th>
      <th class="num">Balance after</th><th>Batch</th><th>When</th>
    </tr></thead>
    <tbody>
    <?php foreach ($movements as $m): ?>
      <tr>
        <td><?= e($m['name']) ?></td>
        <td class="tiny"><?= e(str_replace('_', ' ', (string) $m['movement_type'])) ?></td>
        <?= num_cell($m['qty_delta'], false, true) ?>
        <?= num_cell($m['unit_cost']) ?>
        <td class="num"><?= e(qty_fmt((float) $m['balance_after'])) ?></td>
        <td class="mono tiny"><?= e($m['batch_id'] !== null ? '#' . $m['batch_id'] : '-') ?></td>
        <td class="nowrap tiny"><?= e(date_fmt((string) $m['created_at'], true)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?php if ($trail): ?>
<?= card_open('Audit trail for this sale', '<span class="tiny muted">Hash-chained, append-only</span>') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr><th>#</th><th>When</th><th>Who</th><th>Action</th><th>What happened</th><th>Severity</th></tr></thead>
    <tbody>
    <?php foreach ($trail as $t): ?>
      <tr>
        <td class="mono tiny"><?= (int) $t['id'] ?></td>
        <td class="nowrap tiny"><?= e(date_fmt((string) $t['created_at'], true)) ?></td>
        <td class="tiny"><?= e($t['user_label'] ?? 'system') ?></td>
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

<?php layout_foot(); ?>
