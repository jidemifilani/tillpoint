<?php
/**
 * Sales register: every transaction, filterable, with CSV out.
 *
 * Voided and parked sales are shown alongside completed ones rather than hidden.
 * A register that only lists successful sales is useless for audit, because the
 * voids are the interesting part.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('sales.view');

$storeId = current_store_id();
$from    = date_or_null(get('from')) ?? date('Y-m-d');
$to      = date_or_null(get('to')) ?? date('Y-m-d');
$status  = one_of((string) get('status'), ['all', 'completed', 'voided', 'parked', 'refunded', 'partly_refunded'], 'all');
$cashier = get_int('cashier_id');
$search  = (string) get('q');
$minTotal = num(get('min'), 0.0);

$where  = ['s.store_id = ?', 's.business_date BETWEEN ? AND ?'];
$params = [$storeId, $from, $to];

if ($status !== 'all') {
    $where[]  = 's.status = ?';
    $params[] = $status;
}
if ($cashier > 0) {
    $where[]  = 's.cashier_id = ?';
    $params[] = $cashier;
}
if ($search !== '') {
    $where[]  = '(s.receipt_no LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($minTotal > 0) {
    $where[]  = 's.total >= ?';
    $params[] = $minTotal;
}
$whereSql = implode(' AND ', $where);

$baseSql = "FROM sales s
            LEFT JOIN customers c ON c.id = s.customer_id
            JOIN users u ON u.id = s.cashier_id
            LEFT JOIN terminals t ON t.id = s.terminal_id
           WHERE $whereSql";

if ((string) get('export') === 'csv') {
    require_can('reports.view');
    $rows = qa("SELECT s.receipt_no, s.business_date, s.sold_at, u.full_name AS cashier,
                       t.code AS till, s.status, s.line_count, s.subtotal, s.vat_total,
                       s.discount_total, s.total, s.cost_total, s.refunded_total,
                       c.name AS customer $baseSql ORDER BY s.id DESC", $params);
    audit_log('sales.export', 'sale', null,
        sprintf('Exported %d sales rows for %s to %s', count($rows), $from, $to), null, null, 'notice');
    send_csv('sales-' . $from . '-to-' . $to . '.csv', [
        'Receipt', 'Business date', 'Sold at', 'Cashier', 'Till', 'Status', 'Lines',
        'Net', 'VAT', 'Discount', 'Total', 'Cost', 'Refunded', 'Customer',
    ], $rows);
}

[$limit, $offset, $page] = paging(50);
$total = (int) qv("SELECT COUNT(*) $baseSql", $params, 0);

$rows = qa(
    "SELECT s.*, u.full_name AS cashier, t.code AS till, c.name AS customer,
            (SELECT GROUP_CONCAT(DISTINCT sp.method ORDER BY sp.method) FROM sale_payments sp
              WHERE sp.sale_id = s.id) AS methods
     $baseSql ORDER BY s.id DESC LIMIT $limit OFFSET $offset",
    $params
);

$summary = q1(
    "SELECT COUNT(*) AS txn,
            COALESCE(SUM(CASE WHEN s.status IN ('completed','refunded','partly_refunded') THEN s.total ELSE 0 END),0) AS takings,
            COALESCE(SUM(CASE WHEN s.status IN ('completed','refunded','partly_refunded') THEN s.subtotal - s.cost_total ELSE 0 END),0) AS profit,
            COALESCE(SUM(s.discount_total),0) AS discounts,
            SUM(s.status = 'voided') AS voids,
            COALESCE(SUM(s.refunded_total),0) AS refunded
     $baseSql",
    $params
);

$cashiers = qpairs(
    'SELECT DISTINCT u.id, u.full_name FROM sales s JOIN users u ON u.id = s.cashier_id
      WHERE s.store_id = ? ORDER BY u.full_name', [$storeId]
);

layout_head([
    'title'    => 'Sales register',
    'subtitle' => date_fmt($from) . ' to ' . date_fmt($to),
    'actions'  => can('reports.view')
        ? '<a class="btn btn--ghost" href="' . e(url_with(['export' => 'csv'])) . '">Export CSV</a>' : '',
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
  <label class="field">
    <span class="field__label">Status</span>
    <select name="status">
      <?= select_options([
          'all'             => 'All',
          'completed'       => 'Completed',
          'voided'          => 'Voided',
          'parked'          => 'Parked',
          'refunded'        => 'Fully refunded',
          'partly_refunded' => 'Partly refunded',
      ], $status) ?>
    </select>
  </label>
  <label class="field">
    <span class="field__label">Cashier</span>
    <select name="cashier_id"><?= select_options($cashiers, $cashier, true, 'Everyone') ?></select>
  </label>
  <label class="field">
    <span class="field__label">Receipt or customer</span>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="RC-...">
  </label>
  <label class="field">
    <span class="field__label">Total at least</span>
    <input type="text" name="min" value="<?= e($minTotal > 0 ? (string) $minTotal : '') ?>" class="num-in">
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('sales/')) ?>">Reset</a>
</form>

<div class="tiles">
  <?= tile('Transactions', number_format((int) $summary['txn']), 'matching the filter') ?>
  <?= tile('Takings', money_short((float) $summary['takings']), 'completed sales only') ?>
  <?= tile('Gross profit', money_short((float) $summary['profit']),
        (float) $summary['takings'] > 0
          ? pct_fmt(((float) $summary['profit'] / (float) $summary['takings']) * 100) . ' of takings' : '', 'ok') ?>
  <?= tile('Discounts', money((float) $summary['discounts']), 'given off shelf price',
        (float) $summary['discounts'] > 0 ? 'warn' : 'mute') ?>
  <?= tile('Voided', number_format((int) $summary['voids']), 'baskets abandoned',
        (int) $summary['voids'] > 0 ? 'warn' : 'mute') ?>
  <?= tile('Refunded', money((float) $summary['refunded']), 'returned to customers',
        (float) $summary['refunded'] > 0 ? 'warn' : 'mute') ?>
</div>

<?= card_open('Transactions') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No transactions match those filters.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Receipt</th><th>Time</th><th>Cashier</th><th>Till</th>
        <th class="num">Items</th><th class="num">Discount</th><th class="num">Total</th>
        <th>Paid by</th><th>Customer</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['status'] === 'voided' ? 'row-warn' : '' ?>">
          <td class="mono tiny">
            <a href="<?= e(base_url('sales/view', ['id' => (int) $r['id']])) ?>"><?= e($r['receipt_no']) ?></a>
          </td>
          <td class="nowrap tiny">
            <?= e($r['sold_at'] !== null ? date('d M H:i', strtotime((string) $r['sold_at'])) : '-') ?>
          </td>
          <td class="tiny"><?= e($r['cashier']) ?></td>
          <td class="mono tiny"><?= e($r['till'] ?? '-') ?></td>
          <td class="num"><?= (int) $r['line_count'] ?></td>
          <?= num_cell($r['discount_total']) ?>
          <?= num_cell($r['total']) ?>
          <td class="tiny"><?= e($r['methods'] !== null ? str_replace(',', ', ', (string) $r['methods']) : '-') ?></td>
          <td class="tiny"><?= e($r['customer'] ?? '') ?></td>
          <td class="nowrap">
            <?= status_badge((string) $r['status']) ?>
            <?php if ((float) $r['refunded_total'] > 0): ?>
              <br><span class="tiny muted">-<?= e(money((float) $r['refunded_total'])) ?></span>
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

<?php layout_foot(); ?>
