<?php
/**
 * One customer: their purchase history, loyalty ledger and account balance.
 *
 * A manual points adjustment is possible but needs a reason and goes through
 * loyalty_move() like every other movement, so the ledger still adds up to the
 * balance. Issuing points by hand is the obvious way to steal from a loyalty
 * scheme, which is why it is permissioned and logged at notice level.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('customers.view');

$id = get_int('id');
$c  = q1('SELECT * FROM customers WHERE id = ?', [$id]);
if (!$c) {
    http_response_code(404);
    require APP_ROOT . '/404.php';
    exit;
}

if (is_post()) {
    csrf_require();
    require_can('customers.manage');
    try {
        $action = (string) post('action');

        if ($action === 'update') {
            $before = $c;
            $card   = trim((string) post('loyalty_card'));
            if ($card !== '' && (int) qv('SELECT COUNT(*) FROM customers WHERE loyalty_card = ? AND id <> ?',
                    [$card, $id], 0) > 0) {
                throw new RuntimeException('That loyalty card is issued to someone else.');
            }
            $data = [
                'name'         => (string) post('name'),
                'phone'        => (string) post('phone') !== '' ? (string) post('phone') : null,
                'email'        => (string) post('email') !== '' ? (string) post('email') : null,
                'loyalty_card' => $card !== '' ? $card : null,
                'credit_limit' => post_num('credit_limit'),
                'is_active'    => post('is_active') === '1' ? 1 : 0,
            ];
            db_update('customers', $data, 'id = :id', ['id' => $id]);
            audit_log('customer.update', 'customer', $id,
                'Customer updated: ' . $data['name'], $before, $data, 'info');
            flash_ok('Customer updated.');
        }

        if ($action === 'points') {
            $delta  = post_num('points_delta');
            $reason = trim((string) post('reason'));
            if (abs($delta) < 0.005) {
                throw new RuntimeException('Enter a number of points to add or take away.');
            }
            if ($reason === '') {
                throw new RuntimeException('A manual points adjustment needs a reason.');
            }
            db_tx(fn () => loyalty_move($id, $delta, 'adjust', null, $reason));
            audit_log('loyalty.adjust', 'customer', $id,
                sprintf('%s points adjusted by %s: %s', $c['name'],
                    number_format($delta, 2), $reason),
                null, ['delta' => $delta], 'notice');
            flash_ok('Points adjusted.');
        }

        if ($action === 'settle') {
            $amount = post_num('amount');
            if ($amount <= 0) {
                throw new RuntimeException('Enter the amount being paid.');
            }
            $owing = (float) qv('SELECT balance FROM customers WHERE id = ?', [$id], 0.0);
            if ($amount > $owing + 0.005) {
                throw new RuntimeException('That is more than the ' . money($owing) . ' outstanding.');
            }
            db_tx(function () use ($id, $amount): void {
                q('UPDATE customers SET balance = GREATEST(0, balance - ?) WHERE id = ?', [$amount, $id]);
            });
            audit_log('customer.settle', 'customer', $id,
                sprintf('%s paid %s off their account', $c['name'], money($amount)),
                ['balance' => $owing], ['balance' => $owing - $amount], 'notice');
            flash_ok(money($amount) . ' settled against the account.');
        }
        redirect('sales/customer', ['id' => $id]);
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('sales/customer', ['id' => $id]);
    }
}

$sales = qa(
    'SELECT s.*, st.name AS store_name, u.full_name AS cashier
       FROM sales s JOIN stores st ON st.id = s.store_id
       JOIN users u ON u.id = s.cashier_id
      WHERE s.customer_id = ? AND s.status <> \'parked\'
      ORDER BY s.id DESC LIMIT 60',
    [$id]
);
$ledger = qa(
    'SELECT ll.*, s.receipt_no, u.full_name AS who
       FROM loyalty_ledger ll
       LEFT JOIN sales s ON s.id = ll.sale_id
       LEFT JOIN users u ON u.id = ll.user_id
      WHERE ll.customer_id = ? ORDER BY ll.id DESC LIMIT 60',
    [$id]
);
$stats = q1(
    'SELECT COUNT(*) AS visits, COALESCE(SUM(total),0) AS lifetime,
            COALESCE(AVG(total),0) AS avg_basket, MIN(sold_at) AS first_visit,
            MAX(sold_at) AS last_visit, COALESCE(SUM(refunded_total),0) AS refunded
       FROM sales WHERE customer_id = ? AND status IN (\'completed\',\'refunded\',\'partly_refunded\')',
    [$id]
);
$favourites = qa(
    'SELECT p.name, SUM(sl.qty) AS units, COALESCE(SUM(sl.line_total),0) AS spend
       FROM sale_lines sl JOIN sales s ON s.id = sl.sale_id
       JOIN products p ON p.id = sl.product_id
      WHERE s.customer_id = ? AND s.status IN (\'completed\',\'refunded\',\'partly_refunded\')
      GROUP BY p.id, p.name ORDER BY spend DESC LIMIT 10',
    [$id]
);

$pointValue = setting_num('loyalty_point_value', (float) LOYALTY_POINT_VALUE);

layout_head([
    'title'    => (string) $c['name'],
    'subtitle' => $c['code'] . ($c['loyalty_card'] !== null ? ' - card ' . $c['loyalty_card'] : ''),
    'actions'  => '<a class="btn btn--ghost" href="' . e(base_url('sales/customers')) . '">All customers</a>',
]);
?>

<?php if ((int) $c['is_active'] === 0): ?>
  <div class="alert alert--warning">This account is inactive. The till will not find it.</div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Lifetime spend', money_short((float) $stats['lifetime']),
        number_format((int) $stats['visits']) . ' visits') ?>
  <?= tile('Average basket', money((float) $stats['avg_basket']),
        'across all visits', 'info') ?>
  <?= tile('Points', number_format((float) $c['points_balance'], 0),
        'worth ' . money((float) $c['points_balance'] * $pointValue), 'ok') ?>
  <?= tile('On account', money((float) $c['balance']),
        'limit ' . money((float) $c['credit_limit']),
        (float) $c['balance'] > 0 ? 'warn' : 'mute') ?>
  <?= tile('Refunded', money((float) $stats['refunded']), 'returned over time',
        (float) $stats['refunded'] > 0 ? 'warn' : 'mute') ?>
  <?= tile('Customer since', date_fmt((string) $c['joined_at']),
        $stats['last_visit'] !== null ? 'last in ' . ago((string) $stats['last_visit']) : 'never shopped', 'mute') ?>
</div>

<div class="grid-2">
  <?php if (can('customers.manage')): ?>
    <?= card_open('Details') ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <div class="form-grid">
          <label class="field">
            <span class="field__label">Name</span>
            <input type="text" name="name" required value="<?= e((string) $c['name']) ?>">
          </label>
          <label class="field">
            <span class="field__label">Phone</span>
            <input type="tel" name="phone" value="<?= e((string) ($c['phone'] ?? '')) ?>">
          </label>
          <label class="field">
            <span class="field__label">Email</span>
            <input type="email" name="email" value="<?= e((string) ($c['email'] ?? '')) ?>">
          </label>
          <label class="field">
            <span class="field__label">Loyalty card</span>
            <input type="text" name="loyalty_card" value="<?= e((string) ($c['loyalty_card'] ?? '')) ?>">
          </label>
          <label class="field">
            <span class="field__label">Credit limit</span>
            <input type="text" name="credit_limit" class="num-in"
                   value="<?= e(number_format((float) $c['credit_limit'], 2, '.', '')) ?>">
          </label>
        </div>
        <div class="check">
          <input type="checkbox" name="is_active" value="1" id="cust-active"
                 <?= (int) $c['is_active'] === 1 ? 'checked' : '' ?>>
          <label for="cust-active">Active</label>
        </div>
        <button class="btn" type="submit">Save</button>
      </form>
    <?= card_close() ?>

    <?= card_open('Adjust points by hand') ?>
      <p class="small muted">
        Points are a liability the shop has to honour, so a manual adjustment needs a reason and
        is recorded against your name. Use a negative number to take points away.
      </p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="points">
        <div class="form-grid">
          <label class="field">
            <span class="field__label">Points</span>
            <input type="text" name="points_delta" class="num-in" required placeholder="e.g. 50 or -50">
          </label>
          <label class="field">
            <span class="field__label">Reason</span>
            <input type="text" name="reason" required maxlength="160"
                   placeholder="Goodwill after a complaint">
          </label>
        </div>
        <button class="btn btn--warn" type="submit">Adjust points</button>
      </form>

      <?php if ((float) $c['balance'] > 0): ?>
        <hr style="border:0;border-top:1px solid var(--line);margin:1rem 0">
        <h3>Settle the account</h3>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="settle">
          <label class="field">
            <span class="field__label">Amount paid (of <?= e(money((float) $c['balance'])) ?>)</span>
            <input type="text" name="amount" class="num-in" required>
          </label>
          <button class="btn" type="submit">Record payment</button>
        </form>
      <?php endif; ?>
    <?= card_close() ?>
  <?php endif; ?>

  <?= card_open('What they buy most') ?>
    <?php if (!$favourites): ?>
      <?= empty_state('Nothing bought yet.') ?>
    <?php else: ?>
      <table class="data data--tight">
        <thead><tr><th>Product</th><th class="num">Units</th><th class="num">Spend</th></tr></thead>
        <tbody>
        <?php foreach ($favourites as $f): ?>
          <tr>
            <td><?= e($f['name']) ?></td>
            <td class="num"><?= e(qty_fmt((float) $f['units'])) ?></td>
            <?= num_cell($f['spend']) ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?= card_close() ?>
</div>

<?= card_open('Loyalty ledger') ?>
  <?php if (!$ledger): ?>
    <?= empty_state('No points movements yet.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>When</th><th>Kind</th><th class="num">Points</th><th class="num">Balance after</th>
        <th>Receipt</th><th>By</th><th>Note</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ledger as $l): ?>
        <tr>
          <td class="nowrap tiny"><?= e(date_fmt((string) $l['created_at'], true)) ?></td>
          <td><?= badge(ucfirst((string) $l['kind']),
                $l['kind'] === 'earn' ? 'ok' : ($l['kind'] === 'redeem' ? 'info' : 'warn')) ?></td>
          <td class="num <?= (float) $l['points_delta'] < 0 ? 'neg' : '' ?>">
            <?= e(((float) $l['points_delta'] > 0 ? '+' : '') . number_format((float) $l['points_delta'], 0)) ?>
          </td>
          <td class="num"><?= e(number_format((float) $l['balance_after'], 0)) ?></td>
          <td class="mono tiny">
            <?php if ($l['receipt_no'] !== null): ?>
              <a href="<?= e(base_url('sales/view', ['id' => (int) $l['sale_id']])) ?>"><?= e($l['receipt_no']) ?></a>
            <?php endif; ?>
          </td>
          <td class="tiny"><?= e($l['who'] ?? 'system') ?></td>
          <td class="tiny muted"><?= e($l['note'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?= card_open('Purchase history') ?>
  <?php if (!$sales): ?>
    <?= empty_state('No purchases recorded.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>Receipt</th><th>When</th><th>Store</th><th>Cashier</th>
        <th class="num">Items</th><th class="num">Total</th><th class="num">Points</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($sales as $s): ?>
        <tr>
          <td class="mono tiny">
            <a href="<?= e(base_url('sales/view', ['id' => (int) $s['id']])) ?>"><?= e($s['receipt_no']) ?></a>
          </td>
          <td class="nowrap tiny"><?= e(date_fmt($s['sold_at'], true)) ?></td>
          <td class="tiny"><?= e($s['store_name']) ?></td>
          <td class="tiny"><?= e($s['cashier']) ?></td>
          <td class="num"><?= (int) $s['line_count'] ?></td>
          <?= num_cell($s['total']) ?>
          <td class="num tiny">
            +<?= e(number_format((float) $s['points_earned'], 0)) ?>
            <?php if ((float) $s['points_redeemed'] > 0): ?>
              / -<?= e(number_format((float) $s['points_redeemed'], 0)) ?>
            <?php endif; ?>
          </td>
          <td><?= status_badge((string) $s['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?php layout_foot(); ?>
