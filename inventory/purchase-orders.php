<?php
/**
 * Purchase orders list, with approval in place.
 *
 * The approve button does not appear on an order the viewer raised. po_approve()
 * refuses it regardless - a buyer who can approve their own order can buy from
 * themselves, which is the oldest fraud in purchasing.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('purchasing.view');

$storeId = current_store_id();

if (is_post()) {
    csrf_require();
    $poId = post_int('po_id');
    try {
        switch ((string) post('action')) {
            case 'submit':
                require_can('po.create');
                po_submit($poId, (int) $_SESSION['uid']);
                flash_ok('Order submitted for approval.');
                break;

            case 'approve':
                require_can('po.approve');
                po_approve($poId, (int) $_SESSION['uid']);
                flash_ok('Order approved. It can now be sent to the supplier and received against.');
                break;

            case 'cancel':
                require_can('po.create');
                $reason = trim((string) post('reason'));
                if ($reason === '') {
                    throw new StockError('Say why the order is being cancelled.');
                }
                po_cancel($poId, (int) $_SESSION['uid'], $reason);
                flash_ok('Order cancelled.');
                break;
        }
    } catch (SodViolation $e) {
        flash_err($e->getMessage());
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect('inventory/purchase-orders');
}

$status     = one_of((string) get('status'), ['open', 'all', 'draft', 'submitted', 'approved',
                                              'partly_received', 'received', 'cancelled'], 'open');
$supplierId = get_int('supplier_id');

$where  = ['po.store_id = ?'];
$params = [$storeId];

if ($status === 'open') {
    $where[] = "po.status IN ('draft','submitted','approved','partly_received')";
} elseif ($status !== 'all') {
    $where[]  = 'po.status = ?';
    $params[] = $status;
}
if ($supplierId > 0) {
    $where[]  = 'po.supplier_id = ?';
    $params[] = $supplierId;
}
$whereSql = implode(' AND ', $where);

[$limit, $offset, $page] = paging(50);
$total = (int) qv("SELECT COUNT(*) FROM purchase_orders po WHERE $whereSql", $params, 0);

$rows = qa(
    "SELECT po.*, s.name AS supplier_name, cb.full_name AS created_by_name,
            ab.full_name AS approved_by_name,
            (SELECT COUNT(*) FROM purchase_order_lines pol WHERE pol.po_id = po.id) AS line_count,
            (SELECT COALESCE(SUM(pol.qty_ordered),0) FROM purchase_order_lines pol WHERE pol.po_id = po.id) AS qty_ordered,
            (SELECT COALESCE(SUM(pol.qty_received),0) FROM purchase_order_lines pol WHERE pol.po_id = po.id) AS qty_received
       FROM purchase_orders po
       JOIN suppliers s ON s.id = po.supplier_id
       JOIN users cb ON cb.id = po.created_by
       LEFT JOIN users ab ON ab.id = po.approved_by
      WHERE $whereSql
      ORDER BY FIELD(po.status,'submitted','draft','approved','partly_received','received','cancelled'),
               po.order_date DESC
      LIMIT $limit OFFSET $offset",
    $params
);

$summary = q1(
    "SELECT COUNT(*) AS n, COALESCE(SUM(po.total),0) AS value,
            SUM(po.status = 'submitted') AS awaiting,
            SUM(po.status IN ('approved','partly_received')) AS outstanding
       FROM purchase_orders po WHERE $whereSql",
    $params
);
$suppliers = qpairs('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name');

layout_head([
    'title'    => 'Purchase orders',
    'subtitle' => number_format((int) $summary['n']) . ' orders, '
                . money((float) $summary['value']) . ' committed',
    'actions'  => can('po.create')
        ? '<a class="btn" href="' . e(base_url('inventory/purchase-order')) . '">New order</a>'
          . '<a class="btn btn--ghost" href="' . e(base_url('inventory/reorder')) . '">Replenishment</a>' : '',
]);
?>

<div class="tiles">
  <?= tile('Orders', number_format((int) $summary['n']), 'matching the filter') ?>
  <?= tile('Committed value', money_short((float) $summary['value']), 'including VAT') ?>
  <?= tile('Awaiting approval', number_format((int) $summary['awaiting']), 'submitted, not approved',
        (int) $summary['awaiting'] > 0 ? 'warn' : 'ok') ?>
  <?= tile('Goods outstanding', number_format((int) $summary['outstanding']),
        'approved but not fully received', 'info') ?>
</div>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">Status</span>
    <select name="status">
      <?= select_options([
          'open'            => 'Open orders',
          'all'             => 'All',
          'draft'           => 'Draft',
          'submitted'       => 'Awaiting approval',
          'approved'        => 'Approved',
          'partly_received' => 'Partly received',
          'received'        => 'Fully received',
          'cancelled'       => 'Cancelled',
      ], $status) ?>
    </select>
  </label>
  <label class="field">
    <span class="field__label">Supplier</span>
    <select name="supplier_id"><?= select_options($suppliers, $supplierId, true, 'All') ?></select>
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('inventory/purchase-orders')) ?>">Reset</a>
</form>

<?= card_open('Orders') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No orders match.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Order</th><th>Supplier</th><th>Ordered</th><th>Expected</th>
        <th class="num">Lines</th><th class="num">Received</th><th class="num">Total</th>
        <th>Raised by</th><th>Approved by</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $po):
          $isMine  = (int) $po['created_by'] === (int) $_SESSION['uid'];
          $ordered = (float) $po['qty_ordered'];
          $recvd   = (float) $po['qty_received'];
          $pct     = $ordered > 0 ? ($recvd / $ordered) * 100 : 0;
          $overdue = $po['expected_date'] !== null
                     && strtotime((string) $po['expected_date']) < strtotime(date('Y-m-d'))
                     && in_array($po['status'], ['approved', 'partly_received'], true); ?>
        <tr class="<?= $po['status'] === 'submitted' ? 'row-warn' : ($overdue ? 'row-bad' : '') ?>">
          <td class="mono tiny">
            <a href="<?= e(base_url('inventory/purchase-order', ['id' => (int) $po['id']])) ?>">
              <?= e($po['po_no']) ?></a>
          </td>
          <td class="tiny"><?= e($po['supplier_name']) ?></td>
          <td class="nowrap tiny"><?= e(date_fmt((string) $po['order_date'])) ?></td>
          <td class="nowrap tiny">
            <?= e($po['expected_date'] !== null ? date_fmt((string) $po['expected_date']) : '-') ?>
            <?php if ($overdue): ?><br><?= badge('Overdue', 'bad') ?><?php endif; ?>
          </td>
          <td class="num"><?= (int) $po['line_count'] ?></td>
          <td class="num">
            <?= e(qty_fmt($recvd)) ?> / <?= e(qty_fmt($ordered)) ?>
            <?php if ($ordered > 0): ?>
              <div class="bar" style="max-width:70px;margin-top:2px">
                <span style="width:<?= (int) round(min(100, $pct)) ?>%"></span>
              </div>
            <?php endif; ?>
          </td>
          <?= num_cell($po['total']) ?>
          <td class="tiny"><?= e($po['created_by_name']) ?></td>
          <td class="tiny"><?= e($po['approved_by_name'] ?? '-') ?></td>
          <td><?= status_badge((string) $po['status']) ?></td>
          <td class="right nowrap">
            <?php if ($po['status'] === 'draft' && can('po.create')): ?>
              <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="submit">
                <input type="hidden" name="po_id" value="<?= (int) $po['id'] ?>">
                <button class="btn btn--sm" type="submit">Submit</button>
              </form>
            <?php elseif ($po['status'] === 'submitted' && can('po.approve')): ?>
              <?php if ($isMine): ?>
                <span class="tiny bad-text" title="Segregation of duties">You raised it</span>
              <?php else: ?>
                <form method="post" class="inline-form"
                      data-confirm="Approve order <?= e($po['po_no']) ?> for <?= e(money((float) $po['total'])) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="po_id" value="<?= (int) $po['id'] ?>">
                  <button class="btn btn--sm" type="submit">Approve</button>
                </form>
              <?php endif; ?>
            <?php elseif (in_array($po['status'], ['approved', 'partly_received'], true) && can('grn.create')): ?>
              <a class="btn btn--sm btn--ghost"
                 href="<?= e(base_url('inventory/grn', ['po_id' => (int) $po['id']])) ?>">Receive</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= pager($total, $limit, $page) ?>
  <?php endif; ?>
<?= card_close() ?>

<p class="small muted">
  An order must be approved by someone other than the person who raised it. The approve button is
  hidden on your own orders as a courtesy; the refusal is enforced on the server either way.
</p>

<?php layout_foot(); ?>
