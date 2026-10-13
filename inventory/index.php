<?php
/**
 * Inventory overview: what we hold, what it is worth, and what is wrong with it.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('inventory.view');

$storeId   = current_store_id();
$valuation = stock_valuation($storeId);
$expiryDays = (int) setting_num('expiry_warn_days', (float) EXPIRY_WARN_DAYS);

$counts = q1(
    'SELECT COUNT(*) AS products,
            SUM(sl.qty_on_hand <= 0) AS out_of_stock,
            SUM(sl.qty_on_hand < -0.0005) AS negative,
            SUM(sl.qty_on_hand > 0 AND sl.qty_on_hand <= p.reorder_level AND p.reorder_level > 0) AS low,
            COALESCE(SUM(sl.qty_reserved),0) AS reserved
       FROM stock_levels sl JOIN products p ON p.id = sl.product_id
      WHERE sl.store_id = ? AND p.is_active = 1',
    [$storeId]
);
$expiring    = expiring_batches($storeId, $expiryDays);
$expiredOnly = array_filter($expiring, fn ($b) => (int) $b['days_left'] < 0);
$discrepancies = stock_ledger_discrepancies($storeId);

$byCategory = qa(
    'SELECT c.id, c.name, COUNT(DISTINCT p.id) AS products,
            COALESCE(SUM(sl.qty_on_hand),0) AS units,
            COALESCE(SUM(sl.qty_on_hand * sl.avg_cost),0) AS cost_value,
            COALESCE(SUM(sl.qty_on_hand * p.sell_price),0) AS retail_value
       FROM categories c
       JOIN products p ON p.category_id = c.id AND p.is_active = 1
       LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
      GROUP BY c.id, c.name
      ORDER BY cost_value DESC',
    [$storeId]
);

$recentMovements = qa(
    'SELECT sm.*, p.name, p.sku, u.full_name AS who
       FROM stock_movements sm
       JOIN products p ON p.id = sm.product_id
       LEFT JOIN users u ON u.id = sm.user_id
      WHERE sm.store_id = ? AND sm.movement_type <> \'sale\'
      ORDER BY sm.id DESC LIMIT 15',
    [$storeId]
);

$openWork = [
    'Purchase orders outstanding' => [
        (int) qv('SELECT COUNT(*) FROM purchase_orders WHERE store_id = ?
                   AND status IN (\'submitted\',\'approved\',\'partly_received\')', [$storeId], 0),
        base_url('inventory/purchase-orders'), 'purchasing.view',
    ],
    'Deliveries not posted to stock' => [
        (int) qv('SELECT COUNT(*) FROM grns WHERE store_id = ? AND status = \'draft\'', [$storeId], 0),
        base_url('inventory/grns'), 'grn.create',
    ],
    'Transfers in transit' => [
        (int) qv('SELECT COUNT(*) FROM stock_transfers WHERE to_store_id = ? AND status = \'dispatched\'', [$storeId], 0),
        base_url('inventory/transfers'), 'transfer.receive',
    ],
    'Counts in progress' => [
        (int) qv('SELECT COUNT(*) FROM stock_counts WHERE store_id = ? AND status IN (\'open\',\'counted\')', [$storeId], 0),
        base_url('inventory/counts'), 'count.enter',
    ],
    'Wastage awaiting approval' => [
        (int) qv('SELECT COUNT(*) FROM wastage WHERE store_id = ? AND status = \'pending\'', [$storeId], 0),
        base_url('inventory/wastage'), 'wastage.log',
    ],
    'Adjustments awaiting approval' => [
        (int) qv('SELECT COUNT(*) FROM stock_adjustments WHERE store_id = ? AND status = \'pending\'', [$storeId], 0),
        base_url('inventory/adjustments'), 'stock.adjust',
    ],
];

layout_head([
    'title'    => 'Inventory',
    'subtitle' => (string) (current_store()['name'] ?? ''),
    'actions'  => can('products.manage')
        ? '<a class="btn" href="' . e(base_url('inventory/product')) . '">New product</a>' : '',
]);
?>

<?php if ($discrepancies): ?>
  <div class="alert alert--error">
    <strong><?= count($discrepancies) ?></strong> product line(s) hold a quantity that does not equal
    the sum of their own stock movements. That should be impossible &mdash; every change goes through
    one write path &mdash; so it means either a defect or a direct database edit, and no stock figure
    can be relied on until it is explained.
    <?php if (can('audit.view')): ?>
      <a href="<?= e(base_url('audit/shrinkage')) ?>">Investigate</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($expiredOnly): ?>
  <div class="alert alert--error">
    <strong><?= count($expiredOnly) ?></strong> batch(es) are past their expiry date and still show
    stock on hand. Pull them off the shelf and write them off.
    <a href="<?= e(base_url('reports/expiring')) ?>">See them</a>
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Stock at cost', money_short($valuation['cost_value']),
        number_format($valuation['lines']) . ' lines holding stock') ?>
  <?= tile('Stock at retail', money_short($valuation['retail_value']),
        pct_fmt($valuation['margin_pct']) . ' margin if sold at full price', 'ok') ?>
  <?= tile('Out of stock', number_format((int) $counts['out_of_stock']),
        'active lines with nothing on hand',
        (int) $counts['out_of_stock'] > 0 ? 'warn' : 'ok') ?>
  <?= tile('At reorder level', number_format((int) $counts['low']),
        'need replenishing',
        (int) $counts['low'] > 0 ? 'warn' : 'ok') ?>
  <?= tile('Negative positions', number_format((int) $counts['negative']),
        'sold before receipt was posted',
        (int) $counts['negative'] > 0 ? 'bad' : 'ok') ?>
  <?= tile('Expiring within ' . $expiryDays . 'd', number_format(count($expiring)),
        count($expiredOnly) . ' already expired',
        count($expiredOnly) > 0 ? 'bad' : (count($expiring) > 0 ? 'warn' : 'ok')) ?>
</div>

<div class="grid-2">
  <?= card_open('Open work') ?>
    <?php $visible = array_filter($openWork, fn ($w) => can($w[2]) && $w[0] > 0);
    if (!$visible): ?>
      <?= empty_state('Nothing outstanding.', 'No open orders, deliveries, counts or approvals.') ?>
    <?php else: ?>
      <table class="data data--tight">
        <tbody>
        <?php foreach ($visible as $label => $w): ?>
          <tr>
            <td><a href="<?= e($w[1]) ?>"><?= e($label) ?></a></td>
            <td class="num"><strong><?= $w[0] ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if ((float) $counts['reserved'] > 0): ?>
      <p class="small muted mt1">
        <?= e(qty_fmt((float) $counts['reserved'])) ?> units are reserved by parked baskets and
        cannot be sold on another till until those baskets are completed or voided.
      </p>
    <?php endif; ?>
  <?= card_close() ?>

  <?= card_open('Where the money is tied up') ?>
    <?php if (!$byCategory): ?>
      <?= empty_state('No stock held.') ?>
    <?php else:
        $maxVal = max(array_map(fn ($c) => (float) $c['cost_value'], $byCategory)) ?: 1.0; ?>
      <div class="table-wrap">
      <table class="data data--tight">
        <thead><tr>
          <th>Category</th><th class="num">Lines</th><th class="num">At cost</th>
          <th class="num">At retail</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($byCategory as $c): ?>
          <tr>
            <td><a href="<?= e(base_url('inventory/stock', ['category_id' => (int) $c['id']])) ?>">
              <?= e($c['name']) ?></a></td>
            <td class="num"><?= number_format((int) $c['products']) ?></td>
            <?= num_cell($c['cost_value']) ?>
            <?= num_cell($c['retail_value']) ?>
            <td><div class="bar" style="max-width:90px">
              <span style="width:<?= (int) round(((float) $c['cost_value'] / $maxVal) * 100) ?>%"></span>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td>Total</td><td></td>
            <td class="num"><?= e(money($valuation['cost_value'])) ?></td>
            <td class="num"><?= e(money($valuation['retail_value'])) ?></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
      </div>
    <?php endif; ?>
  <?= card_close() ?>
</div>

<?= card_open('Recent stock movements (excluding sales)',
      '<a class="btn btn--sm btn--ghost" href="' . e(base_url('inventory/movements')) . '">Full ledger</a>') ?>
  <?php if (!$recentMovements): ?>
    <?= empty_state('No movements recorded.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>When</th><th>Product</th><th>Type</th><th class="num">Qty</th>
        <th class="num">Balance after</th><th>Reference</th><th>By</th>
      </tr></thead>
      <tbody>
      <?php foreach ($recentMovements as $m): ?>
        <tr>
          <td class="nowrap tiny"><?= e(date_fmt((string) $m['created_at'], true)) ?></td>
          <td><?= e($m['name']) ?></td>
          <td class="tiny"><?= e(str_replace('_', ' ', (string) $m['movement_type'])) ?></td>
          <?= num_cell($m['qty_delta'], false, true) ?>
          <td class="num"><?= e(qty_fmt((float) $m['balance_after'])) ?></td>
          <td class="mono tiny"><?= e($m['ref_no'] ?? '-') ?></td>
          <td class="tiny"><?= e($m['who'] ?? 'system') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?php layout_foot(); ?>
