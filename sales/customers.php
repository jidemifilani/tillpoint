<?php
/**
 * Loyalty customers.
 *
 * Points are money: they discharge a liability at the till, so creating and
 * adjusting them is permission-gated and every movement lands in loyalty_ledger
 * with a running balance. There is no path here that writes points_balance
 * directly - loyalty_move() is the only writer, so the ledger always explains
 * the balance.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('customers.view');

if (is_post()) {
    csrf_require();
    require_can('customers.manage');
    try {
        if ((string) post('action') === 'create') {
            $name  = trim((string) post('name'));
            $phone = trim((string) post('phone'));
            if ($name === '') {
                throw new RuntimeException('Enter the customer name.');
            }
            if ($phone !== '' && qv('SELECT id FROM customers WHERE phone = ?', [$phone])) {
                throw new RuntimeException('A customer with that phone number already exists.');
            }
            $card = trim((string) post('loyalty_card'));
            if ($card !== '' && qv('SELECT id FROM customers WHERE loyalty_card = ?', [$card])) {
                throw new RuntimeException('That loyalty card is already issued.');
            }

            $id = db_tx(fn (): int => db_insert('customers', [
                'code'         => next_number('customer'),
                'name'         => $name,
                'phone'        => $phone !== '' ? $phone : null,
                'email'        => (string) post('email') !== '' ? (string) post('email') : null,
                'loyalty_card' => $card !== '' ? $card : null,
                'credit_limit' => post_num('credit_limit'),
            ]));

            audit_log('customer.create', 'customer', $id, 'Customer added: ' . $name, null, null, 'info');
            flash_ok('Customer added.');
            redirect('sales/customer', ['id' => $id]);
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('sales/customers');
    }
}

$search = (string) get('q');
$where  = ['1 = 1'];
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE ? OR phone LIKE ? OR loyalty_card LIKE ? OR code LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

[$limit, $offset, $page] = paging(50);
$total = (int) qv("SELECT COUNT(*) FROM customers WHERE $whereSql", $params, 0);

$rows = qa(
    "SELECT c.*,
            (SELECT COUNT(*) FROM sales s WHERE s.customer_id = c.id
               AND s.status IN ('completed','refunded','partly_refunded')) AS visits,
            (SELECT COALESCE(SUM(s.total),0) FROM sales s WHERE s.customer_id = c.id
               AND s.status IN ('completed','refunded','partly_refunded')) AS lifetime,
            (SELECT MAX(s.sold_at) FROM sales s WHERE s.customer_id = c.id) AS last_visit
       FROM customers c WHERE $whereSql
      ORDER BY lifetime DESC LIMIT $limit OFFSET $offset",
    $params
);

$pointValue = setting_num('loyalty_point_value', (float) LOYALTY_POINT_VALUE);
$liability  = (float) qv('SELECT COALESCE(SUM(points_balance),0) FROM customers', [], 0.0) * $pointValue;
$owed       = (float) qv('SELECT COALESCE(SUM(balance),0) FROM customers', [], 0.0);

layout_head([
    'title'    => 'Customers',
    'subtitle' => number_format($total) . ' on the loyalty scheme',
]);
?>

<div class="tiles">
  <?= tile('Customers', number_format((int) qv('SELECT COUNT(*) FROM customers', [], 0)), 'on file') ?>
  <?= tile('Points outstanding', money($liability),
        'what the scheme owes if everyone redeemed', 'warn') ?>
  <?= tile('Owed on account', money($owed), 'credit sales not yet settled',
        $owed > 0 ? 'warn' : 'mute') ?>
  <?= tile('Earn rate',
        '1 pt / ' . money(setting_num('loyalty_points_per_unit', (float) LOYALTY_POINTS_PER_UNIT)),
        '1 point redeems for ' . money($pointValue), 'info') ?>
</div>

<?php if (can('customers.manage')): ?>
  <?= card_open('Add a customer') ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-grid">
        <label class="field">
          <span class="field__label">Name</span>
          <input type="text" name="name" required maxlength="140">
        </label>
        <label class="field">
          <span class="field__label">Phone</span>
          <input type="tel" name="phone" maxlength="40">
          <span class="field__hint">What the till will search on most often.</span>
        </label>
        <label class="field">
          <span class="field__label">Email</span>
          <input type="email" name="email" maxlength="160">
        </label>
        <label class="field">
          <span class="field__label">Loyalty card number</span>
          <input type="text" name="loyalty_card" maxlength="30">
        </label>
        <label class="field">
          <span class="field__label">Credit limit</span>
          <input type="text" name="credit_limit" class="num-in" value="0.00">
          <span class="field__hint">Leave at zero unless they buy on account.</span>
        </label>
      </div>
      <button class="btn" type="submit">Add customer</button>
    </form>
  <?= card_close() ?>
<?php endif; ?>

<form class="filters" method="get">
  <label class="field" style="flex:1">
    <span class="field__label">Search</span>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, phone, card or code">
  </label>
  <button class="btn" type="submit">Search</button>
  <a class="btn btn--ghost" href="<?= e(base_url('sales/customers')) ?>">Reset</a>
</form>

<?= card_open('Customers') ?>
  <?php if (!$rows): ?>
    <?= empty_state($search !== '' ? 'Nobody matches that search.' : 'No customers yet.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Code</th><th>Name</th><th>Phone</th><th>Card</th>
        <th class="num">Visits</th><th class="num">Lifetime spend</th>
        <th class="num">Points</th><th class="num">Worth</th>
        <th class="num">On account</th><th>Last visit</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr>
          <td class="mono tiny"><?= e($c['code']) ?></td>
          <td><a href="<?= e(base_url('sales/customer', ['id' => (int) $c['id']])) ?>"><?= e($c['name']) ?></a></td>
          <td class="tiny"><?= e($c['phone'] ?? '') ?></td>
          <td class="mono tiny"><?= e($c['loyalty_card'] ?? '') ?></td>
          <td class="num"><?= number_format((int) $c['visits']) ?></td>
          <?= num_cell($c['lifetime']) ?>
          <td class="num"><?= e(number_format((float) $c['points_balance'], 0)) ?></td>
          <?= num_cell((float) $c['points_balance'] * $pointValue) ?>
          <?= num_cell($c['balance']) ?>
          <td class="tiny nowrap"><?= e($c['last_visit'] !== null ? ago((string) $c['last_visit']) : 'never') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= pager($total, $limit, $page) ?>
  <?php endif; ?>
<?= card_close() ?>

<?php layout_foot(); ?>
