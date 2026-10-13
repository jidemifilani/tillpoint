<?php
/**
 * Stock valuation.
 *
 * At weighted-average cost, which is the figure that belongs in the accounts,
 * alongside retail value and the margin the holding implies if it all sold at
 * shelf price. The reconciliation check at the bottom is part of the report
 * rather than hidden in the audit module: a valuation whose positions do not
 * match their own ledger is not a valuation anyone should sign.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('reports.financial');

$storeId  = current_store_id();
$allStores = (string) get('all_stores') === '1' && count(my_stores()) > 1;

$valuation = stock_valuation($allStores ? null : $storeId);

$byCategory = qa(
    'SELECT c.id, c.name, COUNT(DISTINCT p.id) AS line_rows,
            COALESCE(SUM(sl.qty_on_hand),0) AS units,
            COALESCE(SUM(sl.qty_on_hand * sl.avg_cost),0) AS cost_value,
            COALESCE(SUM(sl.qty_on_hand * p.sell_price),0) AS retail_value
       FROM stock_levels sl
       JOIN products p ON p.id = sl.product_id
       JOIN categories c ON c.id = p.category_id
      WHERE sl.qty_on_hand <> 0 ' . ($allStores ? '' : 'AND sl.store_id = ?') . '
      GROUP BY c.id, c.name ORDER BY cost_value DESC',
    $allStores ? [] : [$storeId]
);

$byStore = count(my_stores()) > 1 ? qa(
    'SELECT st.id, st.name, st.code, COUNT(DISTINCT sl.product_id) AS line_rows,
            COALESCE(SUM(sl.qty_on_hand * sl.avg_cost),0) AS cost_value,
            COALESCE(SUM(sl.qty_on_hand * p.sell_price),0) AS retail_value
       FROM stock_levels sl
       JOIN stores st ON st.id = sl.store_id
       JOIN products p ON p.id = sl.product_id
      WHERE sl.qty_on_hand <> 0
      GROUP BY st.id, st.name, st.code ORDER BY cost_value DESC'
) : [];

$topValue = qa(
    'SELECT p.id, p.sku, p.name, c.name AS category, p.unit_code,
            sl.qty_on_hand, sl.avg_cost, p.sell_price,
            sl.qty_on_hand * sl.avg_cost AS cost_value,
            sl.qty_on_hand * p.sell_price AS retail_value,
            sl.last_counted_at
       FROM stock_levels sl
       JOIN products p ON p.id = sl.product_id
       JOIN categories c ON c.id = p.category_id
      WHERE sl.qty_on_hand > 0 ' . ($allStores ? '' : 'AND sl.store_id = ?') . '
      ORDER BY cost_value DESC LIMIT 40',
    $allStores ? [] : [$storeId]
);

$negatives = qa(
    'SELECT p.sku, p.name, sl.qty_on_hand, sl.avg_cost,
            sl.qty_on_hand * sl.avg_cost AS cost_value, st.name AS store_name
       FROM stock_levels sl
       JOIN products p ON p.id = sl.product_id
       JOIN stores st ON st.id = sl.store_id
      WHERE sl.qty_on_hand < -0.0005 ' . ($allStores ? '' : 'AND sl.store_id = ?') . '
      ORDER BY sl.qty_on_hand ASC',
    $allStores ? [] : [$storeId]
);

$discrepancies = stock_ledger_discrepancies($allStores ? null : $storeId);

$expiringValue = (float) qv(
    'SELECT COALESCE(SUM(b.qty_on_hand * b.unit_cost),0) FROM stock_batches b
      WHERE b.qty_on_hand > 0 AND b.expiry_date IS NOT NULL
        AND b.expiry_date <= (CURDATE() + INTERVAL ? DAY) '
        . ($allStores ? '' : 'AND b.store_id = ?'),
    $allStores
        ? [(int) setting_num('expiry_warn_days', (float) EXPIRY_WARN_DAYS)]
        : [(int) setting_num('expiry_warn_days', (float) EXPIRY_WARN_DAYS), $storeId],
    0.0
);

if ((string) get('export') === 'csv') {
    $rows = qa(
        'SELECT p.sku, p.name, c.name AS category, st.name AS store, p.unit_code,
                sl.qty_on_hand, sl.avg_cost, sl.qty_on_hand * sl.avg_cost AS cost_value,
                p.sell_price, sl.qty_on_hand * p.sell_price AS retail_value, sl.last_counted_at
           FROM stock_levels sl
           JOIN products p ON p.id = sl.product_id
           JOIN categories c ON c.id = p.category_id
           JOIN stores st ON st.id = sl.store_id
          WHERE sl.qty_on_hand <> 0 ' . ($allStores ? '' : 'AND sl.store_id = ?') . '
          ORDER BY c.name, p.name',
        $allStores ? [] : [$storeId]
    );
    audit_log('reports.valuation_export', 'stock', null,
        'Exported stock valuation: ' . count($rows) . ' lines', null, null, 'notice');
    send_csv('stock-valuation-' . date('Y-m-d') . '.csv', [
        'SKU', 'Product', 'Category', 'Store', 'Unit', 'On hand', 'Avg cost',
        'Value at cost', 'Shelf price', 'Value at retail', 'Last counted',
    ], $rows);
}

layout_head([
    'title'    => 'Stock valuation',
    'subtitle' => ($allStores ? 'All stores' : (string) (current_store()['name'] ?? ''))
                . ' as at ' . date('d M Y, H:i'),
    'actions'  => '<a class="btn btn--ghost" href="' . e(url_with(['export' => 'csv'])) . '">Export CSV</a>'
                . '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<?php if ($discrepancies): ?>
  <div class="verdict verdict--broken">
    <h2>Do not sign this valuation yet</h2>
    <p>
      <?= count($discrepancies) ?> product line(s) hold a quantity that does not equal the sum of
      their own stock movements. The valuation below is computed from those positions, so it is
      wrong by an unknown amount until the difference is explained.
      <a href="<?= e(base_url('audit/shrinkage')) ?>">Investigate</a>
    </p>
  </div>
<?php endif; ?>

<?php if (count(my_stores()) > 1): ?>
  <form class="filters" method="get">
    <label class="check" style="margin-bottom:0">
      <input type="checkbox" name="all_stores" value="1" <?= $allStores ? 'checked' : '' ?>
             onchange="this.form.submit()">
      Include every store
    </label>
  </form>
<?php endif; ?>

<div class="tiles">
  <?= tile('Value at cost', money_short($valuation['cost_value']),
        number_format($valuation['lines']) . ' lines holding stock') ?>
  <?= tile('Value at retail', money_short($valuation['retail_value']),
        'if every unit sold at shelf price', 'info') ?>
  <?= tile('Embedded margin', money_short($valuation['margin_value']),
        pct_fmt($valuation['margin_pct']) . ' of retail value', 'ok') ?>
  <?= tile('Units held', qty_fmt($valuation['units']), 'across all lines', 'mute') ?>
  <?= tile('At risk from expiry', money($expiringValue),
        'within ' . (int) setting_num('expiry_warn_days', (float) EXPIRY_WARN_DAYS) . ' days',
        $expiringValue > 0 ? 'warn' : 'ok') ?>
  <?= tile('Negative positions', number_format(count($negatives)),
        'cannot be valued honestly',
        count($negatives) > 0 ? 'bad' : 'ok') ?>
</div>

<?php if ($byStore): ?>
<?= card_open('By store') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Store</th><th class="num">Lines</th><th class="num">At cost</th>
      <th class="num">At retail</th><th class="num">Embedded margin</th>
    </tr></thead>
    <tbody>
    <?php foreach ($byStore as $s):
        $cost   = (float) $s['cost_value'];
        $retail = (float) $s['retail_value']; ?>
      <tr>
        <td><?= e($s['name']) ?> <span class="tiny muted mono"><?= e($s['code']) ?></span></td>
        <td class="num"><?= number_format((int) $s['line_rows']) ?></td>
        <?= num_cell($cost) ?>
        <?= num_cell($retail) ?>
        <td class="num"><?= e($retail > 0 ? pct_fmt((($retail - $cost) / $retail) * 100) : '-') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?= card_open('By category') ?>
  <?php if (!$byCategory): ?>
    <?= empty_state('No stock held.') ?>
  <?php else:
      $maxVal = max(array_map(fn ($c) => (float) $c['cost_value'], $byCategory)) ?: 1.0; ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Category</th><th class="num">Lines</th><th class="num">Units</th>
        <th class="num">At cost</th><th class="num">At retail</th>
        <th class="num">Margin</th><th class="num">Share of stock</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($byCategory as $c):
          $cost   = (float) $c['cost_value'];
          $retail = (float) $c['retail_value'];
          $share  = $valuation['cost_value'] > 0 ? ($cost / $valuation['cost_value']) * 100 : 0; ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td class="num"><?= number_format((int) $c['line_rows']) ?></td>
          <td class="num"><?= e(qty_fmt((float) $c['units'])) ?></td>
          <?= num_cell($cost) ?>
          <?= num_cell($retail) ?>
          <td class="num"><?= e($retail > 0 ? pct_fmt((($retail - $cost) / $retail) * 100) : '-') ?></td>
          <td class="num"><?= e(pct_fmt($share)) ?></td>
          <td><div class="bar" style="max-width:80px">
            <span style="width:<?= (int) round(($cost / $maxVal) * 100) ?>%"></span>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td><strong>Total</strong></td>
          <td class="num"><?= number_format($valuation['lines']) ?></td>
          <td class="num"><?= e(qty_fmt($valuation['units'])) ?></td>
          <td class="num"><strong><?= e(money($valuation['cost_value'])) ?></strong></td>
          <td class="num"><?= e(money($valuation['retail_value'])) ?></td>
          <td class="num"><?= e(pct_fmt($valuation['margin_pct'])) ?></td>
          <td class="num">100.0%</td><td></td>
        </tr>
      </tfoot>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?php if ($negatives): ?>
<?= card_open('Negative positions (' . count($negatives) . ')') ?>
  <div class="alert alert--warning">
    A negative quantity cannot be valued honestly &mdash; the shop cannot hold less than nothing.
    Each of these is almost always an unposted delivery. Find it before signing the valuation;
    the figures above include these lines at a negative value.
  </div>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr><th>SKU</th><th>Product</th><th>Store</th>
               <th class="num">On hand</th><th class="num">Value</th></tr></thead>
    <tbody>
    <?php foreach ($negatives as $n): ?>
      <tr class="row-bad">
        <td class="mono tiny"><?= e($n['sku']) ?></td>
        <td><?= e($n['name']) ?></td>
        <td class="tiny"><?= e($n['store_name']) ?></td>
        <?= num_cell($n['qty_on_hand'], false, true) ?>
        <?= num_cell($n['cost_value'], true, true) ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?= card_open('Where the money sits - top 40 lines by value') ?>
  <?php if (!$topValue): ?>
    <?= empty_state('No stock held.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>SKU</th><th>Product</th><th>Category</th>
        <th class="num">On hand</th><th class="num">Avg cost</th>
        <th class="num">At cost</th><th class="num">At retail</th><th>Last counted</th>
      </tr></thead>
      <tbody>
      <?php
      $staleDays = (int) audit_rule_threshold('STALE_COUNT', 90.0);
      foreach ($topValue as $p):
          $stale = $p['last_counted_at'] === null
                   || strtotime((string) $p['last_counted_at']) < strtotime("-$staleDays days"); ?>
        <tr class="<?= $stale ? 'row-warn' : '' ?>">
          <td class="mono tiny"><?= e($p['sku']) ?></td>
          <td>
            <a href="<?= e(base_url('inventory/product', ['id' => (int) $p['id']])) ?>">
              <?= e($p['name']) ?></a>
          </td>
          <td class="tiny"><?= e($p['category']) ?></td>
          <td class="num"><?= e(qty_fmt((float) $p['qty_on_hand'])) ?>
            <span class="tiny muted"><?= e($p['unit_code']) ?></span></td>
          <?= num_cell($p['avg_cost']) ?>
          <?= num_cell($p['cost_value']) ?>
          <?= num_cell($p['retail_value']) ?>
          <td class="tiny nowrap">
            <?= e($p['last_counted_at'] !== null ? ago((string) $p['last_counted_at']) : 'never') ?>
            <?php if ($stale): ?><br><?= badge('Verify', 'warn') ?><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="small muted mt1">
      Lines marked "verify" hold significant value and have not been physically counted in
      <?= $staleDays ?> days. A valuation is only as good as the last time someone checked the
      shelf matched the system.
    </p>
  <?php endif; ?>
<?= card_close() ?>

<?= card_open('Reconciliation') ?>
  <?php if (!$discrepancies): ?>
    <p class="small">
      <strong class="ok-text">Every position reconciles against its own movement history.</strong>
      For all <?= number_format($valuation['lines']) ?> lines holding stock, the quantity on hand
      equals the sum of every receipt, sale, transfer, write-off and count variance ever recorded
      against it. That is the check that makes this valuation signable.
    </p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr><th>Product</th><th>Store</th><th class="num">Position says</th>
                 <th class="num">Ledger says</th><th class="num">Difference</th></tr></thead>
      <tbody>
      <?php foreach ($discrepancies as $d): ?>
        <tr class="row-bad">
          <td><?= e($d['name']) ?> <span class="tiny muted mono"><?= e($d['sku']) ?></span></td>
          <td class="tiny"><?= e($d['store_name']) ?></td>
          <td class="num"><?= e(qty_fmt((float) $d['qty_on_hand'])) ?></td>
          <td class="num"><?= e(qty_fmt((float) $d['ledger_qty'])) ?></td>
          <?= num_cell($d['difference'], false, true) ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
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
