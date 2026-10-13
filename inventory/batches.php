<?php
/**
 * Batch and expiry tracking.
 *
 * The point of tracking batches is traceability: when a supplier issues a
 * recall, this page answers "which deliveries, and how much is still on the
 * shelf". The expiry columns are a side benefit, not the purpose.
 *
 * Writing off from here goes through wastage_log(), so an expired batch leaves
 * stock with a reason code and an audit entry rather than being edited away.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('inventory.view');

$storeId   = current_store_id();
$productId = get_int('product_id');
$view      = one_of((string) get('view'), ['open', 'expiring', 'expired', 'all', 'depleted'], 'open');
$search    = (string) get('q');
$warnDays  = (int) setting_num('expiry_warn_days', (float) EXPIRY_WARN_DAYS);

if (is_post()) {
    csrf_require();
    require_can('wastage.log');
    try {
        $batchId = post_int('batch_id');
        $batch   = q1('SELECT b.*, p.name FROM stock_batches b
                        JOIN products p ON p.id = b.product_id
                       WHERE b.id = ? AND b.store_id = ?', [$batchId, $storeId]);
        if (!$batch) {
            throw new StockError('That batch is not in this store.');
        }
        $qty = post_num('qty');
        if ($qty <= 0 || $qty > (float) $batch['qty_on_hand'] + 0.0005) {
            throw new StockError('Enter a quantity between 0 and ' . qty_fmt((float) $batch['qty_on_hand']) . '.');
        }

        $result = wastage_log([
            'product_id'  => (int) $batch['product_id'],
            'store_id'    => $storeId,
            'batch_id'    => $batchId,
            'qty'         => $qty,
            'user_id'     => (int) $_SESSION['uid'],
            'reason_code' => (string) post('reason_code'),
            'note'        => 'Batch ' . $batch['batch_no'] . ': ' . (string) post('note'),
        ]);

        if ($result['posted']) {
            flash_ok(sprintf('%s written off from batch %s - %s at cost (%s).',
                qty_fmt($qty), $batch['batch_no'], money((float) $result['value']), $result['waste_no']));
        } else {
            flash_warn(sprintf('Write-off %s raised for %s and is waiting for a manager - '
                . 'the stock stays on the books until it is approved.',
                $result['waste_no'], money((float) $result['value'])));
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect(url_with([]));
}

$where  = ['b.store_id = ?'];
$params = [$storeId];

if ($productId > 0) {
    $where[]  = 'b.product_id = ?';
    $params[] = $productId;
}
if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR b.batch_no LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
switch ($view) {
    case 'open':
        $where[] = 'b.qty_on_hand > 0';
        break;
    case 'expiring':
        $where[]  = 'b.qty_on_hand > 0 AND b.expiry_date IS NOT NULL
                     AND b.expiry_date BETWEEN CURDATE() AND (CURDATE() + INTERVAL ? DAY)';
        $params[] = $warnDays;
        break;
    case 'expired':
        $where[] = 'b.qty_on_hand > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE()';
        break;
    case 'depleted':
        $where[] = 'b.qty_on_hand <= 0';
        break;
}
$whereSql = implode(' AND ', $where);

// The delivery join is only needed for the detail rows, so the count query uses
// the narrower FROM. Both share the same WHERE, which is the part that has to
// agree - a count over a different row set than the page would mis-page.
$fromSql = 'FROM stock_batches b JOIN products p ON p.id = b.product_id';
$joinSql = 'LEFT JOIN grns g ON g.id = b.grn_id
            LEFT JOIN suppliers s ON s.id = g.supplier_id';

[$limit, $offset, $page] = paging(80);
$total = (int) qv("SELECT COUNT(*) $fromSql WHERE $whereSql", $params, 0);

$rows = qa(
    "SELECT b.*, p.sku, p.name, p.unit_code, p.sell_price,
            DATEDIFF(b.expiry_date, CURDATE()) AS days_left,
            b.qty_on_hand * b.unit_cost AS value_at_risk,
            g.grn_no, g.supplier_invoice_no, s.name AS supplier_name
     $fromSql $joinSql
     WHERE $whereSql
     ORDER BY (b.expiry_date IS NULL), b.expiry_date ASC, b.id DESC
     LIMIT $limit OFFSET $offset",
    $params
);

$summary = q1(
    "SELECT COUNT(*) AS batches,
            COALESCE(SUM(b.qty_on_hand),0) AS units,
            COALESCE(SUM(b.qty_on_hand * b.unit_cost),0) AS value,
            SUM(b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE() AND b.qty_on_hand > 0) AS expired,
            COALESCE(SUM(CASE WHEN b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE()
                              AND b.qty_on_hand > 0
                         THEN b.qty_on_hand * b.unit_cost ELSE 0 END),0) AS expired_value
     $fromSql WHERE $whereSql",
    $params
);

$wasteReasons = qpairs('SELECT code, label FROM reason_codes
                         WHERE applies_to = \'wastage\' AND is_active = 1 ORDER BY label');
$product = $productId > 0 ? q1('SELECT name, sku FROM products WHERE id = ?', [$productId]) : null;

layout_head([
    'title'    => 'Batches and expiry',
    'subtitle' => $product !== null
        ? $product['name'] . ' (' . $product['sku'] . ')'
        : (string) (current_store()['name'] ?? '') . ' - ' . number_format($total) . ' batches',
]);
?>

<?php if ((int) $summary['expired'] > 0): ?>
  <div class="alert alert--error">
    <strong><?= number_format((int) $summary['expired']) ?></strong> batch(es) are past their expiry
    date with stock still on hand, worth <?= e(money((float) $summary['expired_value'])) ?> at cost.
    That is a food-safety exposure before it is a financial one &mdash; clear the shelf and write
    them off below.
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Batches', number_format((int) $summary['batches']), 'matching the filter') ?>
  <?= tile('Units held', qty_fmt((float) $summary['units']), 'in those batches') ?>
  <?= tile('Value at cost', money((float) $summary['value']), 'in those batches') ?>
  <?= tile('Expired value', money((float) $summary['expired_value']),
        number_format((int) $summary['expired']) . ' batch(es) past date',
        (float) $summary['expired_value'] > 0 ? 'bad' : 'ok') ?>
</div>

<form class="filters" method="get">
  <?php if ($productId > 0): ?>
    <input type="hidden" name="product_id" value="<?= $productId ?>">
  <?php endif; ?>
  <label class="field" style="flex:1;min-width:180px">
    <span class="field__label">Search</span>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Product or batch number">
  </label>
  <label class="field">
    <span class="field__label">Show</span>
    <select name="view">
      <?= select_options([
          'open'     => 'Open batches',
          'expiring' => 'Expiring within ' . $warnDays . ' days',
          'expired'  => 'Already expired',
          'depleted' => 'Used up',
          'all'      => 'Everything',
      ], $view) ?>
    </select>
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('inventory/batches')) ?>">Reset</a>
</form>

<?= card_open('Batches', '<button class="btn btn--sm btn--ghost" data-print>Print</button>') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No batches match.',
          'Only products with batch tracking switched on create batches.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Batch</th><th>Product</th><th>Expires</th><th class="num">Days</th>
        <th class="num">Received</th><th class="num">On hand</th>
        <th class="num">Unit cost</th><th class="num">At risk</th>
        <th>Delivery</th>
        <?= can('wastage.log') ? '<th>Write off</th>' : '' ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $b):
          $left    = $b['days_left'] !== null ? (int) $b['days_left'] : null;
          $onHand  = (float) $b['qty_on_hand'];
          $expired = $left !== null && $left < 0 && $onHand > 0; ?>
        <tr class="<?= $expired ? 'row-bad' : ($left !== null && $left <= 7 && $onHand > 0 ? 'row-warn' : '') ?>">
          <td class="mono tiny"><?= e($b['batch_no']) ?></td>
          <td>
            <a href="<?= e(base_url('inventory/product', ['id' => (int) $b['product_id']])) ?>">
              <?= e($b['name']) ?></a>
            <br><span class="tiny muted mono"><?= e($b['sku']) ?></span>
          </td>
          <td class="nowrap tiny">
            <?= e($b['expiry_date'] !== null ? date_fmt((string) $b['expiry_date']) : 'no date') ?>
          </td>
          <td class="num <?= $expired ? 'neg' : '' ?>">
            <?= e($left !== null ? ($left < 0 ? $left . 'd ago' : (string) $left) : '-') ?>
          </td>
          <td class="num"><?= e(qty_fmt((float) $b['qty_received'])) ?></td>
          <td class="num"><strong><?= e(qty_fmt($onHand)) ?></strong>
            <span class="tiny muted"><?= e($b['unit_code']) ?></span></td>
          <?= num_cell($b['unit_cost']) ?>
          <?= num_cell($b['value_at_risk']) ?>
          <td class="tiny">
            <?php if ($b['grn_no'] !== null): ?>
              <a href="<?= e(base_url('inventory/grn', ['id' => (int) $b['grn_id']])) ?>">
                <?= e((string) $b['grn_no']) ?></a>
              <?php if ($b['supplier_name'] !== null): ?>
                <br><span class="muted"><?= e((string) $b['supplier_name']) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted">-</span>
            <?php endif; ?>
          </td>
          <?php if (can('wastage.log')): ?>
            <td>
              <?php if ($onHand > 0): ?>
                <form method="post" class="btn-row" style="gap:.25rem"
                      data-confirm="Write off stock from batch <?= e($b['batch_no']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="batch_id" value="<?= (int) $b['id'] ?>">
                  <input type="text" name="qty" class="num-in" style="max-width:62px"
                         placeholder="qty" required inputmode="decimal">
                  <select name="reason_code" required style="max-width:120px">
                    <?= select_options($wasteReasons, $expired ? 'EXPIRED' : '', true, 'reason') ?>
                  </select>
                  <input type="hidden" name="note" value="Written off from the batch list">
                  <button class="btn btn--sm btn--danger" type="submit">Waste</button>
                </form>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= pager($total, $limit, $page) ?>
  <?php endif; ?>
<?= card_close() ?>

<p class="small muted">
  Batches are consumed first-expiry-first-out, so the one nearest its date is always sold next.
  A write-off here goes through the ordinary wastage route: above
  <?= e(money(setting_num('wastage_approval_limit', (float) WASTAGE_APPROVAL_LIMIT))) ?> at cost
  it waits for a manager who is not the person who raised it.
</p>

<?php layout_foot(); ?>
