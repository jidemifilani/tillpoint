<?php
/**
 * A printable receipt, sized for an 80mm thermal roll (see print.css).
 *
 * VAT is shown as a derived figure with the rate stated, because the shelf price
 * is VAT-inclusive - a customer checking the arithmetic should be able to.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('sales.view');

$id   = get_int('id');
$sale = q1(
    'SELECT s.*, st.name AS store_name, st.address, st.phone, st.tin,
            u.full_name AS cashier, t.code AS terminal_code, c.name AS customer_name,
            c.loyalty_card, c.points_balance
       FROM sales s
       JOIN stores st ON st.id = s.store_id
       JOIN users u ON u.id = s.cashier_id
       LEFT JOIN terminals t ON t.id = s.terminal_id
       LEFT JOIN customers c ON c.id = s.customer_id
      WHERE s.id = ?',
    [$id]
);
if (!$sale) {
    http_response_code(404);
    require APP_ROOT . '/404.php';
    exit;
}

$lines    = qa('SELECT sl.*, p.unit_code FROM sale_lines sl
                 JOIN products p ON p.id = sl.product_id
                WHERE sl.sale_id = ? ORDER BY sl.id', [$id]);
$payments = qa('SELECT * FROM sale_payments WHERE sale_id = ? ORDER BY id', [$id]);
$refunds  = qa('SELECT * FROM refunds WHERE sale_id = ? AND status = \'posted\' ORDER BY id', [$id]);
$printNow = get_int('print') === 1;

// Group VAT by rate: a basket can mix rates, and a single "VAT" line would not
// let anyone reconstruct the figure.
$vatByRate = [];
foreach ($lines as $l) {
    $rate = (string) rtrim(rtrim(number_format((float) $l['vat_rate'] * 100, 2), '0'), '.');
    $vatByRate[$rate] = ($vatByRate[$rate] ?? 0) + (float) $l['vat_amount'];
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt <?= e($sale['receipt_no']) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/print.css')) ?>" media="print">
</head>
<body class="receipt-page" <?= $printNow ? 'data-print-now' : '' ?>>

<div class="no-print" style="padding:1rem;text-align:center">
  <a class="btn" href="<?= e(base_url('pos/')) ?>">Back to the till</a>
  <button class="btn btn--ghost" data-print>Print again</button>
  <a class="btn btn--ghost" href="<?= e(base_url('sales/view', ['id' => $id])) ?>">Full detail</a>
</div>

<div class="receipt">
  <h2><?= e(strtoupper((string) $sale['store_name'])) ?></h2>
  <p class="ctr" style="margin:.2rem 0">
    <?= e((string) $sale['address']) ?><br>
    <?php if ($sale['phone']): ?>Tel <?= e((string) $sale['phone']) ?><br><?php endif; ?>
    <?php if ($sale['tin']): ?>TIN <?= e((string) $sale['tin']) ?><?php endif; ?>
  </p>
  <hr>

  <table>
    <tr><td>Receipt</td><td class="r"><?= e($sale['receipt_no']) ?></td></tr>
    <tr><td>Date</td><td class="r"><?= e(date_fmt($sale['sold_at'] ?? $sale['created_at'], true)) ?></td></tr>
    <tr><td>Cashier</td><td class="r"><?= e($sale['cashier']) ?></td></tr>
    <?php if ($sale['terminal_code']): ?>
      <tr><td>Till</td><td class="r"><?= e($sale['terminal_code']) ?></td></tr>
    <?php endif; ?>
  </table>
  <hr>

  <table>
    <?php foreach ($lines as $l): ?>
      <tr>
        <td colspan="2"><?= e($l['description']) ?></td>
      </tr>
      <tr>
        <td>
          &nbsp;&nbsp;<?= e(qty_fmt((float) $l['qty'])) ?>
          <?= e(strtolower((string) $l['unit_code'])) ?>
          &times; <?= e(money((float) $l['unit_price'], false)) ?>
          <?php if ((int) $l['price_overridden'] === 1): ?>*<?php endif; ?>
        </td>
        <td class="r"><?= e(money((float) $l['line_total'], false)) ?></td>
      </tr>
      <?php if ((float) $l['line_discount'] > 0): ?>
        <tr>
          <td>&nbsp;&nbsp;discount</td>
          <td class="r">-<?= e(money((float) $l['line_discount'], false)) ?></td>
        </tr>
      <?php endif; ?>
    <?php endforeach; ?>
  </table>
  <hr>

  <table>
    <tr><td><?= count($lines) ?> item<?= count($lines) === 1 ? '' : 's' ?>, net</td>
        <td class="r"><?= e(money((float) $sale['subtotal'], false)) ?></td></tr>
    <?php foreach ($vatByRate as $rate => $amount): ?>
      <tr><td>VAT at <?= e($rate) ?>%</td><td class="r"><?= e(money((float) $amount, false)) ?></td></tr>
    <?php endforeach; ?>
    <?php if ((float) $sale['discount_total'] > 0): ?>
      <tr><td>You saved</td><td class="r"><?= e(money((float) $sale['discount_total'], false)) ?></td></tr>
    <?php endif; ?>
    <tr class="big"><td>TOTAL</td><td class="r"><?= e(money((float) $sale['total'], false)) ?></td></tr>
  </table>
  <hr>

  <table>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td><?= e(ucfirst((string) $p['method'])) ?>
          <?php if ($p['reference']): ?><span style="font-size:.9em"> <?= e((string) $p['reference']) ?></span><?php endif; ?>
        </td>
        <td class="r">
          <?= e(money($p['method'] === 'cash' && $p['tendered'] !== null
                ? (float) $p['tendered'] : (float) $p['amount'], false)) ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ((float) $sale['change_given'] > 0): ?>
      <tr><td>Change</td><td class="r"><?= e(money((float) $sale['change_given'], false)) ?></td></tr>
    <?php endif; ?>
  </table>

  <?php if ($sale['customer_name'] !== null): ?>
    <hr>
    <table>
      <tr><td colspan="2"><?= e((string) $sale['customer_name']) ?>
        <?php if ($sale['loyalty_card']): ?>(<?= e((string) $sale['loyalty_card']) ?>)<?php endif; ?></td></tr>
      <?php if ((float) $sale['points_redeemed'] > 0): ?>
        <tr><td>Points redeemed</td><td class="r"><?= e(number_format((float) $sale['points_redeemed'], 0)) ?></td></tr>
      <?php endif; ?>
      <?php if ((float) $sale['points_earned'] > 0): ?>
        <tr><td>Points earned</td><td class="r"><?= e(number_format((float) $sale['points_earned'], 0)) ?></td></tr>
      <?php endif; ?>
      <tr><td>Points balance</td><td class="r"><?= e(number_format((float) $sale['points_balance'], 0)) ?></td></tr>
    </table>
  <?php endif; ?>

  <?php if ($refunds): ?>
    <hr>
    <p style="margin:.2rem 0"><strong>REFUNDED AGAINST THIS RECEIPT</strong></p>
    <table>
      <?php foreach ($refunds as $r): ?>
        <tr><td><?= e($r['refund_no']) ?> <?= e(date_fmt((string) $r['posted_at'])) ?></td>
            <td class="r">-<?= e(money((float) $r['total'], false)) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php $hasOverride = false;
  foreach ($lines as $l) {
      if ((int) $l['price_overridden'] === 1) { $hasOverride = true; break; }
  }
  if ($hasOverride): ?>
    <p style="font-size:.9em;margin:.3rem 0">* price adjusted at the till</p>
  <?php endif; ?>

  <hr>
  <p class="ctr" style="margin:.3rem 0">
    <?= e((string) setting('receipt_footer', RECEIPT_FOOTER)) ?>
  </p>
  <p class="ctr" style="margin:.3rem 0;font-size:.9em">
    <?= e($sale['receipt_no']) ?><br>
    <?= e(SITE_NAME) ?>
  </p>
</div>

<script src="<?= e(asset_url('js/app.js')) ?>"></script>
</body>
</html>
