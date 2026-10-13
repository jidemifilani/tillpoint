<?php
/**
 * VAT summary.
 *
 * Output VAT on sales less input VAT on purchases, which is the figure that gets
 * remitted. Shelf prices are VAT-inclusive, so output VAT is derived out of the
 * price rather than added to it - and refunds reduce output VAT, so they are
 * netted off rather than shown as a separate liability.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('reports.financial');

$storeId = current_store_id();
$from    = date_or_null(get('from')) ?? date('Y-m-01');
$to      = date_or_null(get('to')) ?? date('Y-m-t');

$vat = report_vat_summary($storeId, $from, $to);

// Sales broken down by the rate actually charged on the line, because a basket
// can mix rates and a single blended figure cannot be reconciled to a return.
$byRate = qa(
    "SELECT sl.vat_rate,
            COALESCE(SUM(sl.line_total),0) AS gross,
            COALESCE(SUM(sl.line_total - sl.vat_amount),0) AS net,
            COALESCE(SUM(sl.vat_amount),0) AS vat,
            SUM(sl.qty) AS units
       FROM sale_lines sl JOIN sales s ON s.id = sl.sale_id
      WHERE s.store_id = ? AND s.business_date BETWEEN ? AND ?
        AND s.status IN ('completed','refunded','partly_refunded')
      GROUP BY sl.vat_rate ORDER BY sl.vat_rate DESC",
    [$storeId, $from, $to]
);

$monthly = qa(
    "SELECT DATE_FORMAT(business_date, '%Y-%m') AS period,
            COALESCE(SUM(subtotal),0) AS net,
            COALESCE(SUM(vat_total),0) AS vat,
            COALESCE(SUM(total),0) AS gross
       FROM sales
      WHERE store_id = ? AND status IN ('completed','refunded','partly_refunded')
        AND business_date >= (CURDATE() - INTERVAL 12 MONTH)
      GROUP BY period ORDER BY period DESC",
    [$storeId]
);

$purchases = qa(
    "SELECT g.grn_no, g.received_date, s.name AS supplier, g.supplier_invoice_no,
            g.subtotal, g.vat_total, g.total
       FROM grns g JOIN suppliers s ON s.id = g.supplier_id
      WHERE g.store_id = ? AND g.received_date BETWEEN ? AND ? AND g.status = 'posted'
      ORDER BY g.received_date DESC, g.id DESC LIMIT 200",
    [$storeId, $from, $to]
);

layout_head([
    'title'    => 'VAT summary',
    'subtitle' => date_fmt($from) . ' to ' . date_fmt($to) . ' - '
                . (string) (current_store()['name'] ?? ''),
    'actions'  => '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

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
  <a class="btn btn--ghost" href="<?= e(base_url('reports/vat')) ?>">This month</a>
  <a class="btn btn--ghost" href="<?= e(base_url('reports/vat', [
      'from' => date('Y-m-01', strtotime('-1 month')),
      'to'   => date('Y-m-t', strtotime('-1 month')),
  ])) ?>">Last month</a>
</form>

<div class="tiles">
  <?= tile('Net sales', money_short($vat['sales_net']), 'VAT-exclusive turnover') ?>
  <?= tile('Output VAT', money($vat['output_vat']),
        'charged to customers, less refunds', 'info') ?>
  <?= tile('Net purchases', money_short($vat['purchases_net']), 'goods received, VAT-exclusive') ?>
  <?= tile('Input VAT', money($vat['input_vat']), 'paid to suppliers, reclaimable', 'ok') ?>
  <?= tile('VAT payable', money($vat['payable']),
        $vat['payable'] >= 0 ? 'due to the authority' : 'reclaimable',
        $vat['payable'] >= 0 ? 'warn' : 'ok') ?>
  <?= tile('Refund VAT credited', money($vat['refund_vat']),
        'already netted off output VAT', 'mute') ?>
</div>

<?= card_open('The return') ?>
  <table class="data">
    <tbody>
      <tr>
        <td>Output VAT &mdash; charged on sales</td>
        <?= num_cell($vat['output_vat'] + $vat['refund_vat']) ?>
      </tr>
      <tr>
        <td>Less VAT on refunds given back to customers</td>
        <?= num_cell(-$vat['refund_vat']) ?>
      </tr>
      <tr>
        <td><strong>Net output VAT</strong></td>
        <?= num_cell($vat['output_vat']) ?>
      </tr>
      <tr>
        <td>Input VAT &mdash; paid on goods received</td>
        <?= num_cell(-$vat['input_vat']) ?>
      </tr>
    </tbody>
    <tfoot>
      <tr>
        <td><strong><?= $vat['payable'] >= 0 ? 'VAT payable' : 'VAT reclaimable' ?></strong></td>
        <td class="num"><strong><?= e(money(abs($vat['payable']))) ?></strong></td>
      </tr>
    </tfoot>
  </table>
  <p class="small muted mt1">
    Output VAT is derived out of the VAT-inclusive shelf price at the rate held on each product,
    line by line &mdash; not applied to a blended total &mdash; so this reconciles to the sum of
    the receipts. Input VAT comes from posted goods receipts only: a delivery recorded but not
    posted is not yet a purchase.
  </p>
<?= card_close() ?>

<div class="grid-2">
  <?= card_open('Sales by VAT rate') ?>
    <?php if (!$byRate): ?>
      <?= empty_state('No sales in that period.') ?>
    <?php else: ?>
      <table class="data data--tight">
        <thead><tr>
          <th class="num">Rate</th><th class="num">Units</th><th class="num">Net</th>
          <th class="num">VAT</th><th class="num">Gross</th>
        </tr></thead>
        <tbody>
        <?php foreach ($byRate as $r): ?>
          <tr>
            <td class="num"><?= e(pct_fmt((float) $r['vat_rate'] * 100, 2)) ?></td>
            <td class="num"><?= e(qty_fmt((float) $r['units'])) ?></td>
            <?= num_cell($r['net']) ?>
            <?= num_cell($r['vat']) ?>
            <?= num_cell($r['gross']) ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?= card_close() ?>

  <?= card_open('Last 12 months') ?>
    <?php if (!$monthly): ?>
      <?= empty_state('No sales history.') ?>
    <?php else: ?>
      <table class="data data--tight">
        <thead><tr><th>Month</th><th class="num">Net</th><th class="num">VAT</th>
                   <th class="num">Gross</th></tr></thead>
        <tbody>
        <?php foreach ($monthly as $m): ?>
          <tr>
            <td class="nowrap"><?= e(date('M Y', strtotime($m['period'] . '-01'))) ?></td>
            <?= num_cell($m['net']) ?>
            <?= num_cell($m['vat']) ?>
            <?= num_cell($m['gross']) ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?= card_close() ?>
</div>

<?= card_open('Purchases in the period (input VAT)') ?>
  <?php if (!$purchases): ?>
    <?= empty_state('No posted goods receipts in that period.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>Receipt</th><th>Date</th><th>Supplier</th><th>Their invoice</th>
        <th class="num">Net</th><th class="num">VAT</th><th class="num">Total</th>
      </tr></thead>
      <tbody>
      <?php foreach ($purchases as $p): ?>
        <tr>
          <td class="mono tiny"><?= e($p['grn_no']) ?></td>
          <td class="nowrap tiny"><?= e(date_fmt((string) $p['received_date'])) ?></td>
          <td class="tiny"><?= e($p['supplier']) ?></td>
          <td class="mono tiny"><?= e($p['supplier_invoice_no'] ?? '-') ?></td>
          <?= num_cell($p['subtotal']) ?>
          <?= num_cell($p['vat_total']) ?>
          <?= num_cell($p['total']) ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4"><strong>Total</strong></td>
          <td class="num"><?= e(money($vat['purchases_net'])) ?></td>
          <td class="num"><?= e(money($vat['input_vat'])) ?></td>
          <td class="num"><?= e(money($vat['purchases_net'] + $vat['input_vat'])) ?></td>
        </tr>
      </tfoot>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<div class="sign-off">
  <div>Prepared by: <?= e((string) (current_user()['full_name'] ?? '')) ?>
    on <?= e(date('d M Y, H:i')) ?></div>
  <div>Approved by: ________________________</div>
</div>

<?php layout_foot(); ?>
