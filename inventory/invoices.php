<?php
/**
 * Supplier invoices.
 *
 * Leads with two controls that are easy to miss: invoices awaiting match/approval,
 * and deliveries so old that no invoice has arrived for them. The second is the
 * accrual exposure - a delivery the books have not accounted for.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('invoice.enter');

$storeId = current_store_id();

$status     = one_of((string) get('status'), ['all', 'draft', 'matched', 'disputed', 'approved', 'paid', 'cancelled'], 'all');
$supplierId = get_int('supplier_id');
$from       = date_or_null(get('from')) ?? date('Y-m-d', strtotime('-60 days'));
$to         = date_or_null(get('to')) ?? date('Y-m-d');

$where  = ['si.store_id = ?', 'si.invoice_date BETWEEN ? AND ?'];
$params = [$storeId, $from, $to];

if ($status !== 'all') {
    $where[]  = 'si.status = ?';
    $params[] = $status;
}
if ($supplierId > 0) {
    $where[]  = 'si.supplier_id = ?';
    $params[] = $supplierId;
}
$whereSql = implode(' AND ', $where);

// Invoices waiting for action: draft (entered, not matched), matched (waiting approval), disputed.
$pending = qa(
    "SELECT si.*, s.name AS supplier_name, u.full_name AS entered_by_name,
             DATEDIFF(CURDATE(), si.invoice_date) AS age
        FROM supplier_invoices si
        JOIN suppliers s ON s.id = si.supplier_id
        JOIN users u ON u.id = si.entered_by
       WHERE si.store_id = ? AND si.status IN ('draft','matched','disputed')
       ORDER BY si.invoice_date, si.id",
    [$storeId]
);

// Overdue unbilled deliveries - a liability nobody has invoiced yet.
$overdueDays    = (int) setting_num('invoice_overdue_days', 45.0);
$overdueBilling = invoice_overdue_unbilled($storeId, $overdueDays);

[$limit, $offset, $page] = paging(50);
$total = (int) qv("SELECT COUNT(*) FROM supplier_invoices si WHERE $whereSql", $params, 0);

$rows = qa(
    "SELECT si.*, s.name AS supplier_name, u.full_name AS entered_by_name,
             au.full_name AS approved_by_name,
             (SELECT COUNT(*) FROM supplier_invoice_lines sil WHERE sil.invoice_id = si.id) AS line_count
        FROM supplier_invoices si
        JOIN suppliers s ON s.id = si.supplier_id
        JOIN users u ON u.id = si.entered_by
        LEFT JOIN users au ON au.id = si.approved_by
       WHERE $whereSql
       ORDER BY si.invoice_date DESC, si.id DESC
       LIMIT $limit OFFSET $offset",
    $params
);

$summary = q1(
    "SELECT COUNT(*) AS n,
            COALESCE(SUM(si.total), 0) AS value,
            COALESCE(SUM(CASE WHEN si.status = 'approved' THEN si.total ELSE 0 END), 0) AS approved_value,
            COALESCE(SUM(CASE WHEN si.status = 'paid' THEN si.total ELSE 0 END), 0) AS paid_value
       FROM supplier_invoices si WHERE $whereSql",
    $params
);

$suppliers = qpairs('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name');

layout_head([
    'title'    => 'Supplier invoices',
    'subtitle' => count($pending) > 0
        ? count($pending) . ' invoice(s) need attention'
        : 'All invoices matched or settled',
    'actions'  => '<a class="btn" href="' . e(base_url('inventory/invoice')) . '">Enter invoice</a>',
]);
?>

<?php if ($overdueBilling): ?>
  <div class="alert alert--error">
    <strong><?= count($overdueBilling) ?></strong>
    delivery(ies) are over <?= $overdueDays ?> days old with no invoice against them.
    Each one is an unrecorded liability.
    <a class="btn btn--sm btn--ghost" href="<?= e(base_url('inventory/invoices', ['status' => 'all', 'overdue' => '1'])) ?>">
      View deliveries
    </a>
  </div>

  <?= card_open('Overdue unbilled deliveries (>' . $overdueDays . ' days)') ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>Delivery</th><th>Supplier</th><th class="num">Date</th>
        <th class="num">Age</th><th class="num">Value</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($overdueBilling as $g): ?>
        <tr class="row-bad">
          <td class="mono tiny"><?= e($g['grn_no']) ?></td>
          <td class="tiny"><?= e($g['supplier_name']) ?></td>
          <td class="num tiny"><?= e(date_fmt((string) $g['received_date'])) ?></td>
          <td class="num"><?= (int) $g['age'] ?>d</td>
          <?= num_cell($g['total']) ?>
          <td class="right">
            <a class="btn btn--sm btn--ghost"
               href="<?= e(base_url('inventory/invoice', ['supplier_id' => (int) $g['supplier_id'], 'grn_id' => (int) $g['id']])) ?>">
              Enter invoice
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?= card_close() ?>
<?php endif; ?>

<?php if ($pending): ?>
  <?= card_open('Invoices needing attention') ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Our ref</th><th>Invoice no.</th><th>Supplier</th>
        <th class="num">Date</th><th class="num">Total</th>
        <th>Status</th><th>Match</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($pending as $si):
          $rowClass = $si['status'] === 'disputed' ? 'row-bad'
              : ($si['status'] === 'draft' ? 'row-warn' : ''); ?>
        <tr class="<?= $rowClass ?>">
          <td class="mono tiny">
            <a href="<?= e(base_url('inventory/invoice', ['id' => (int) $si['id']])) ?>"><?= e($si['our_ref']) ?></a>
          </td>
          <td class="tiny"><?= e($si['invoice_no']) ?></td>
          <td class="tiny"><?= e($si['supplier_name']) ?></td>
          <td class="num tiny"><?= e(date_fmt((string) $si['invoice_date'])) ?></td>
          <?= num_cell($si['total']) ?>
          <td><?= badge(ucfirst((string) $si['status']),
                $si['status'] === 'disputed' ? 'bad' : ($si['status'] === 'draft' ? 'warn' : 'info')) ?></td>
          <td><?php if ($si['match_status'] === 'variance'): ?>
              <?= badge('Variance', 'bad') ?>
            <?php elseif ($si['match_status'] === 'clean'): ?>
              <?= badge('Clean', 'ok') ?>
            <?php else: ?>
              <span class="muted tiny">Not matched</span>
            <?php endif; ?></td>
          <td class="right">
            <a class="btn btn--sm btn--ghost"
               href="<?= e(base_url('inventory/invoice', ['id' => (int) $si['id']])) ?>">Open</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?= card_close() ?>
<?php endif; ?>

<div class="tiles">
  <?= tile('Invoices in period', number_format((int) $summary['n'])) ?>
  <?= tile('Total invoiced', money_short((float) $summary['value'])) ?>
  <?= tile('Approved for payment', money_short((float) $summary['approved_value']), '', 'info') ?>
  <?= tile('Paid', money_short((float) $summary['paid_value']), '', 'ok') ?>
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
      <?= select_options([
          'all'       => 'All statuses',
          'draft'     => 'Draft',
          'matched'   => 'Matched',
          'disputed'  => 'Disputed',
          'approved'  => 'Approved',
          'paid'      => 'Paid',
          'cancelled' => 'Cancelled',
      ], $status) ?>
    </select>
  </label>
  <label class="field">
    <span class="field__label">Supplier</span>
    <select name="supplier_id">
      <option value="">All suppliers</option>
      <?= select_options($suppliers, (string) ($supplierId ?: '')) ?>
    </select>
  </label>
  <button class="btn btn--ghost" type="submit">Filter</button>
  <a class="btn btn--ghost" href="<?= e(base_url('inventory/invoices')) ?>">Reset</a>
</form>

<?= card_open('Invoices') ?>
  <?php if (!$rows): ?>
    <p class="muted small">Nothing in this period matches the filters.</p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Our ref</th><th>Invoice no.</th><th>Supplier</th>
        <th class="num">Invoice date</th><th class="num">Due</th>
        <th class="num">Lines</th><th class="num">Total</th>
        <th>Status</th><th>Match</th><th>Entered by</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $si):
          $overdue = $si['due_date'] !== null
              && in_array($si['status'], ['draft','matched','approved'], true)
              && strtotime((string) $si['due_date']) < time(); ?>
        <tr class="<?= $overdue ? 'row-warn' : '' ?>">
          <td class="mono tiny">
            <a href="<?= e(base_url('inventory/invoice', ['id' => (int) $si['id']])) ?>">
              <?= e($si['our_ref']) ?>
            </a>
          </td>
          <td class="tiny"><?= e($si['invoice_no']) ?></td>
          <td class="tiny"><?= e($si['supplier_name']) ?></td>
          <td class="num tiny"><?= e(date_fmt((string) $si['invoice_date'])) ?></td>
          <td class="num tiny <?= $overdue ? 'bad-text' : '' ?>">
            <?= $si['due_date'] ? e(date_fmt((string) $si['due_date'])) : '<span class="muted">—</span>' ?>
          </td>
          <td class="num"><?= (int) $si['line_count'] ?></td>
          <?= num_cell($si['total']) ?>
          <td><?= badge(ucfirst((string) $si['status']),
                match($si['status']) {
                    'paid'      => 'ok',
                    'approved'  => 'info',
                    'disputed'  => 'bad',
                    'cancelled' => 'muted',
                    default     => 'warn',
                }) ?></td>
          <td><?php if ($si['match_status'] === 'variance'): ?>
              <?= badge('Variance', 'bad') ?>
            <?php elseif ($si['match_status'] === 'clean'): ?>
              <?= badge('Clean', 'ok') ?>
            <?php else: ?>
              <span class="muted tiny">—</span>
            <?php endif; ?></td>
          <td class="tiny"><?= e($si['entered_by_name']) ?></td>
          <td class="right">
            <a class="btn btn--sm btn--ghost"
               href="<?= e(base_url('inventory/invoice', ['id' => (int) $si['id']])) ?>">Open</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= pager($page, $total, $limit) ?>
  <?php endif; ?>
<?= card_close() ?>

<?php layout_foot(); ?>
