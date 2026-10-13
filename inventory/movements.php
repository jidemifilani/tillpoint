<?php
/**
 * The stock ledger.
 *
 * Append-only: there is no edit and no delete on this page, because there is no
 * edit or delete anywhere in the codebase. A correction is another movement the
 * other way, which is why a product's history can be read as an explanation
 * rather than a current state.
 *
 * balance_after is shown because it lets a reader verify the chain by eye - each
 * row's balance should be the previous one plus this row's quantity.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('inventory.view');

$storeId   = current_store_id();
$productId = get_int('product_id');
$type      = (string) get('type');
$from      = date_or_null(get('from')) ?? date('Y-m-d', strtotime('-14 days'));
$to        = date_or_null(get('to')) ?? date('Y-m-d');
$search    = (string) get('q');
$userId    = get_int('user_id');

$types = ['receipt', 'sale', 'refund_in', 'transfer_out', 'transfer_in',
          'adjustment', 'wastage', 'count_variance', 'opening'];

$where  = ['sm.store_id = ?', 'DATE(sm.created_at) BETWEEN ? AND ?'];
$params = [$storeId, $from, $to];

if ($productId > 0) {
    $where[]  = 'sm.product_id = ?';
    $params[] = $productId;
}
if (in_array($type, $types, true)) {
    $where[]  = 'sm.movement_type = ?';
    $params[] = $type;
}
if ($userId > 0) {
    $where[]  = 'sm.user_id = ?';
    $params[] = $userId;
}
if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR sm.ref_no LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);
$fromSql  = 'FROM stock_movements sm JOIN products p ON p.id = sm.product_id';

if ((string) get('export') === 'csv') {
    require_can('reports.view');
    $rows = qa("SELECT sm.id, sm.created_at, p.sku, p.name, sm.movement_type, sm.qty_delta,
                       sm.unit_cost, sm.value_delta, sm.balance_after, sm.ref_type, sm.ref_no,
                       sm.reason_code, sm.note, u.full_name AS who
                $fromSql LEFT JOIN users u ON u.id = sm.user_id
                WHERE $whereSql ORDER BY sm.id", $params);
    audit_log('ledger.export', 'stock', null,
        sprintf('Exported %d ledger rows for %s to %s', count($rows), $from, $to), null, null, 'notice');
    send_csv('stock-ledger-' . $from . '-to-' . $to . '.csv', [
        'Movement ID', 'When', 'SKU', 'Product', 'Type', 'Quantity', 'Unit cost',
        'Value', 'Balance after', 'Ref type', 'Reference', 'Reason', 'Note', 'By',
    ], $rows);
}

[$limit, $offset, $page] = paging(100);
$total = (int) qv("SELECT COUNT(*) $fromSql WHERE $whereSql", $params, 0);

$rows = qa(
    "SELECT sm.*, p.sku, p.name, p.unit_code, u.full_name AS who,
            COALESCE(rc.label, sm.reason_code) AS reason_label
     $fromSql
     LEFT JOIN users u ON u.id = sm.user_id
     LEFT JOIN reason_codes rc ON rc.code = sm.reason_code
     WHERE $whereSql
     ORDER BY sm.id DESC LIMIT $limit OFFSET $offset",
    $params
);

$byType = qa(
    "SELECT sm.movement_type, COUNT(*) AS movements,
            COALESCE(SUM(sm.qty_delta),0) AS units,
            COALESCE(SUM(sm.value_delta),0) AS value
     $fromSql WHERE $whereSql
     GROUP BY sm.movement_type ORDER BY ABS(SUM(sm.value_delta)) DESC",
    $params
);

$users = qpairs('SELECT DISTINCT u.id, u.full_name FROM stock_movements sm
                   JOIN users u ON u.id = sm.user_id
                  WHERE sm.store_id = ? ORDER BY u.full_name', [$storeId]);
$product = $productId > 0 ? q1('SELECT name, sku FROM products WHERE id = ?', [$productId]) : null;

layout_head([
    'title'    => 'Stock ledger',
    'subtitle' => $product !== null
        ? $product['name'] . ' (' . $product['sku'] . ')'
        : number_format($total) . ' movements, ' . date_fmt($from) . ' to ' . date_fmt($to),
    'actions'  => can('reports.view')
        ? '<a class="btn btn--ghost" href="' . e(url_with(['export' => 'csv'])) . '">Export CSV</a>' : '',
]);
?>

<form class="filters" method="get">
  <?php if ($productId > 0): ?>
    <input type="hidden" name="product_id" value="<?= $productId ?>">
  <?php endif; ?>
  <label class="field">
    <span class="field__label">From</span>
    <input type="date" name="from" value="<?= e($from) ?>">
  </label>
  <label class="field">
    <span class="field__label">To</span>
    <input type="date" name="to" value="<?= e($to) ?>">
  </label>
  <label class="field">
    <span class="field__label">Type</span>
    <select name="type">
      <?= select_options(array_combine($types,
            array_map(fn ($t) => ucfirst(str_replace('_', ' ', $t)), $types)),
            $type, true, 'All types') ?>
    </select>
  </label>
  <label class="field">
    <span class="field__label">By</span>
    <select name="user_id"><?= select_options($users, $userId, true, 'Anyone') ?></select>
  </label>
  <label class="field">
    <span class="field__label">Product or reference</span>
    <input type="search" name="q" value="<?= e($search) ?>">
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('inventory/movements')) ?>">Reset</a>
</form>

<?php if ($byType): ?>
<?= card_open('What moved, by cause') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Type</th><th class="num">Movements</th><th class="num">Net units</th>
      <th class="num">Net value</th><th>What it means</th>
    </tr></thead>
    <tbody>
    <?php
    $meaning = [
        'receipt'        => 'Goods in from a supplier - the only thing that moves average cost',
        'sale'           => 'Sold at a till - expected outflow',
        'refund_in'      => 'Customer return put back on the shelf',
        'transfer_out'   => 'Dispatched to another store',
        'transfer_in'    => 'Received from another store',
        'adjustment'     => 'Changed with no physical event - the most scrutinised type',
        'wastage'        => 'Written off with a reason code - accounted loss',
        'count_variance' => 'Difference found at a stock count - unexplained loss or gain',
        'opening'        => 'Opening balance at go-live',
    ];
    foreach ($byType as $t): ?>
      <tr>
        <td><?= e(ucfirst(str_replace('_', ' ', (string) $t['movement_type']))) ?></td>
        <td class="num"><?= number_format((int) $t['movements']) ?></td>
        <?= num_cell($t['units'], false, true) ?>
        <?= num_cell($t['value'], true, true) ?>
        <td class="tiny muted"><?= e($meaning[(string) $t['movement_type']] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?= card_open('Movements', '<span class="tiny muted">Append-only. A correction is another row, never an edit.</span>') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No movements in that period.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>#</th><th>When</th><th>Product</th><th>Type</th>
        <th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Value</th>
        <th class="num">Balance after</th><th>Reference</th><th>Reason</th><th>By</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $m):
          $isLoss = in_array($m['movement_type'], ['wastage', 'adjustment', 'count_variance'], true)
                    && (float) $m['qty_delta'] < 0; ?>
        <tr class="<?= $isLoss ? 'row-warn' : '' ?>">
          <td class="mono tiny"><?= (int) $m['id'] ?></td>
          <td class="nowrap tiny"><?= e(date_fmt((string) $m['created_at'], true)) ?></td>
          <td>
            <a href="<?= e(base_url('inventory/movements', ['product_id' => (int) $m['product_id']])) ?>">
              <?= e($m['name']) ?></a>
            <br><span class="tiny muted mono"><?= e($m['sku']) ?></span>
          </td>
          <td class="tiny"><?= e(str_replace('_', ' ', (string) $m['movement_type'])) ?></td>
          <?= num_cell($m['qty_delta'], false, true) ?>
          <?= num_cell($m['unit_cost']) ?>
          <?= num_cell($m['value_delta'], true, true) ?>
          <td class="num <?= (float) $m['balance_after'] < 0 ? 'neg' : '' ?>">
            <?= e(qty_fmt((float) $m['balance_after'])) ?>
          </td>
          <td class="mono tiny"><?= e($m['ref_no'] ?? '-') ?></td>
          <td class="tiny"><?= e($m['reason_label'] ?? '') ?></td>
          <td class="tiny"><?= e($m['who'] ?? 'system') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= pager($total, $limit, $page) ?>
  <?php endif; ?>
<?= card_close() ?>

<?php layout_foot(); ?>
