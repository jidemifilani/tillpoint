<?php
/**
 * Goods received notes.
 *
 * A draft GRN is a delivery recorded at the door but not yet in stock. Those are
 * dangerous if they sit: the shop is selling goods the system does not know it
 * has, which is how negative positions appear. The list leads with them for
 * that reason, and DRAFT_GRN_STALE raises an exception after a few days.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('grn.create');

$storeId = current_store_id();

if (is_post()) {
    csrf_require();
    try {
        if ((string) post('action') === 'post') {
            require_can('grn.post');
            grn_post(post_int('grn_id'), (int) $_SESSION['uid']);
            flash_ok('Delivery posted to stock. The goods are now sellable and the cost is in the average.');
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect('inventory/grns');
}

$status     = one_of((string) get('status'), ['all', 'draft', 'posted', 'cancelled'], 'all');
$supplierId = get_int('supplier_id');
$from       = date_or_null(get('from')) ?? date('Y-m-d', strtotime('-30 days'));
$to         = date_or_null(get('to')) ?? date('Y-m-d');

$where  = ['g.store_id = ?', 'g.received_date BETWEEN ? AND ?'];
$params = [$storeId, $from, $to];

if ($status !== 'all') {
    $where[]  = 'g.status = ?';
    $params[] = $status;
}
if ($supplierId > 0) {
    $where[]  = 'g.supplier_id = ?';
    $params[] = $supplierId;
}
$whereSql = implode(' AND ', $where);

// Drafts are shown whatever the date filter says: an unposted delivery from last
// month is exactly the thing this page exists to surface, and hiding it behind a
// date range would defeat the purpose.
$drafts = qa(
    'SELECT g.*, s.name AS supplier_name, u.full_name AS received_by_name,
            po.po_no, DATEDIFF(CURDATE(), g.received_date) AS age,
            (SELECT COUNT(*) FROM grn_lines gl WHERE gl.grn_id = g.id) AS line_count
       FROM grns g
       JOIN suppliers s ON s.id = g.supplier_id
       JOIN users u ON u.id = g.received_by
       LEFT JOIN purchase_orders po ON po.id = g.po_id
      WHERE g.store_id = ? AND g.status = \'draft\'
      ORDER BY g.received_date',
    [$storeId]
);

[$limit, $offset, $page] = paging(50);
$total = (int) qv("SELECT COUNT(*) FROM grns g WHERE $whereSql", $params, 0);

$rows = qa(
    "SELECT g.*, s.name AS supplier_name, u.full_name AS received_by_name,
            pb.full_name AS posted_by_name, po.po_no,
            (SELECT COUNT(*) FROM grn_lines gl WHERE gl.grn_id = g.id) AS line_count,
            (SELECT COALESCE(SUM(gl.qty),0) FROM grn_lines gl WHERE gl.grn_id = g.id) AS units
       FROM grns g
       JOIN suppliers s ON s.id = g.supplier_id
       JOIN users u ON u.id = g.received_by
       LEFT JOIN users pb ON pb.id = g.posted_by
       LEFT JOIN purchase_orders po ON po.id = g.po_id
      WHERE $whereSql
      ORDER BY g.received_date DESC, g.id DESC
      LIMIT $limit OFFSET $offset",
    $params
);

$summary = q1(
    "SELECT COUNT(*) AS n, COALESCE(SUM(g.total),0) AS value,
            COALESCE(SUM(g.vat_total),0) AS vat
       FROM grns g WHERE $whereSql AND g.status = 'posted'",
    $params
);
$suppliers  = qpairs('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name');
$staleDays  = (int) audit_rule_threshold('DRAFT_GRN_STALE', 3.0);

layout_head([
    'title'    => 'Goods received',
    'subtitle' => count($drafts) > 0
        ? count($drafts) . ' delivery(ies) not yet posted to stock'
        : 'Everything received has been posted',
    'actions'  => '<a class="btn" href="' . e(base_url('inventory/grn')) . '">Record a delivery</a>',
]);
?>

<?php if ($drafts): ?>
  <?php $stale = array_filter($drafts, fn ($d) => (int) $d['age'] >= $staleDays); ?>
  <div class="alert alert--<?= $stale ? 'error' : 'warning' ?>">
    <strong><?= count($drafts) ?></strong> delivery(ies) are recorded but not posted to stock.
    Until they are posted the shop is selling goods the system does not know it has, which shows
    up as negative positions.
    <?php if ($stale): ?>
      <strong><?= count($stale) ?></strong> have been sitting for <?= $staleDays ?>+ days.
    <?php endif; ?>
  </div>

  <?= card_open('Not yet posted to stock') ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Receipt</th><th>Supplier</th><th>Order</th><th>Received</th>
        <th class="num">Age</th><th class="num">Lines</th><th class="num">Total</th>
        <th>Received by</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($drafts as $g): ?>
        <tr class="<?= (int) $g['age'] >= $staleDays ? 'row-bad' : 'row-warn' ?>">
          <td class="mono tiny">
            <a href="<?= e(base_url('inventory/grn', ['id' => (int) $g['id']])) ?>"><?= e($g['grn_no']) ?></a>
          </td>
          <td class="tiny"><?= e($g['supplier_name']) ?></td>
          <td class="mono tiny"><?= e($g['po_no'] ?? 'direct') ?></td>
          <td class="nowrap tiny"><?= e(date_fmt((string) $g['received_date'])) ?></td>
          <td class="num"><?= (int) $g['age'] ?>d</td>
          <td class="num"><?= (int) $g['line_count'] ?></td>
          <?= num_cell($g['total']) ?>
          <td class="tiny"><?= e($g['received_by_name']) ?></td>
          <td class="right">
            <?php if (can('grn.post') && (int) $g['line_count'] > 0): ?>
              <form method="post" class="inline-form"
                    data-confirm="Post <?= e($g['grn_no']) ?> to stock? This moves the quantities and the average cost.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="post">
                <input type="hidden" name="grn_id" value="<?= (int) $g['id'] ?>">
                <button class="btn btn--sm" type="submit">Post to stock</button>
              </form>
            <?php else: ?>
              <a class="btn btn--sm btn--ghost"
                 href="<?= e(base_url('inventory/grn', ['id' => (int) $g['id']])) ?>">Open</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?= card_close() ?>
<?php endif; ?>

<div class="tiles">
  <?= tile('Deliveries posted', number_format((int) $summary['n']), 'in the selected period') ?>
  <?= tile('Goods in', money_short((float) $summary['value']), 'including VAT') ?>
  <?= tile('Input VAT', money((float) $summary['vat']), 'reclaimable', 'info') ?>
  <?= tile('Awaiting posting', number_format(count($drafts)), 'not in stock yet',
        $drafts ? 'bad' : 'ok') ?>
</div>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">From</span>
    <input type="date" name="from" value="<?= e($from) ?>">
  </label>
  <label class="field">
    <span class="field__label">To</span>
    <input type="date" name="to" value="<?= e($to) ?>">
  </label>
  <label class="field">
    <span class="field__label">Status</span>
    <select name="status">
      <?= select_options(['all' => 'All', 'draft' => 'Draft', 'posted' => 'Posted',
                          'cancelled' => 'Cancelled'], $status) ?>
    </select>
  </label>
  <label class="field">
    <span class="field__label">Supplier</span>
    <select name="supplier_id"><?= select_options($suppliers, $supplierId, true, 'All') ?></select>
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('inventory/grns')) ?>">Reset</a>
</form>

<?= card_open('Delivery history') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No deliveries in that period.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Receipt</th><th>Supplier</th><th>Order</th><th>Invoice</th><th>Received</th>
        <th class="num">Lines</th><th class="num">Units</th><th class="num">Total</th>
        <th>Posted by</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $g): ?>
        <tr class="<?= $g['status'] === 'draft' ? 'row-warn' : '' ?>">
          <td class="mono tiny">
            <a href="<?= e(base_url('inventory/grn', ['id' => (int) $g['id']])) ?>"><?= e($g['grn_no']) ?></a>
          </td>
          <td class="tiny"><?= e($g['supplier_name']) ?></td>
          <td class="mono tiny">
            <?php if ($g['po_no'] !== null): ?>
              <a href="<?= e(base_url('inventory/purchase-order', ['id' => (int) $g['po_id']])) ?>">
                <?= e((string) $g['po_no']) ?></a>
            <?php else: ?>
              <span class="muted">direct</span>
            <?php endif; ?>
          </td>
          <td class="mono tiny"><?= e($g['supplier_invoice_no'] ?? '-') ?></td>
          <td class="nowrap tiny"><?= e(date_fmt((string) $g['received_date'])) ?></td>
          <td class="num"><?= (int) $g['line_count'] ?></td>
          <td class="num"><?= e(qty_fmt((float) $g['units'])) ?></td>
          <?= num_cell($g['total']) ?>
          <td class="tiny"><?= e($g['posted_by_name'] ?? '-') ?></td>
          <td><?= status_badge((string) $g['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= pager($total, $limit, $page) ?>
  <?php endif; ?>
<?= card_close() ?>

<?php layout_foot(); ?>
