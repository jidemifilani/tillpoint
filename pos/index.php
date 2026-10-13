<?php
/**
 * The till.
 *
 * Runs without the sidebar (bare layout) because a cashier at a queue needs the
 * basket and the total, not a menu. Every action is an ordinary form POST and
 * the server does all the arithmetic - the till has to work when the browser is
 * old, the screen is a touch panel, and JavaScript has failed.
 *
 * The basket is a parked sale in the database rather than a session array, so a
 * crashed browser or a swapped-out terminal does not lose a half-rung basket,
 * and the stock it is holding is visible to the other tills.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('pos.operate');

$storeId = current_store_id();
$session = session_current();
$saleId  = (int) ($_SESSION['pos_sale_id'] ?? 0);

/* ------------------------------------------------------------------ */
/* Actions                                                            */
/* ------------------------------------------------------------------ */
if (is_post()) {
    csrf_require();
    $action = (string) post('action');

    try {
        /* ---- Open the till ---------------------------------------- */
        if ($action === 'open_session') {
            require_can('pos.session.open');
            $opened = session_open($storeId, post_int('terminal_id'), (int) $_SESSION['uid'], post_num('opening_float'));
            flash_ok('Till ' . $opened['session_no'] . ' is open. Good luck out there.');
            redirect('pos/');
        }

        if ($session === null) {
            throw new PosError('Open your till before serving a customer.');
        }

        /* ---- Scan or key in an item ------------------------------- */
        if ($action === 'scan') {
            $code = (string) post('code');
            $qty  = post_num('qty', 1.0);

            $product = pos_find_product($code, $storeId);
            if ($product === null) {
                throw new PosError('No product matches "' . $code . '". Check the code, or search by name.');
            }
            if ($saleId === 0) {
                $saleId = sale_open((int) $session['id']);
                $_SESSION['pos_sale_id'] = $saleId;
            }

            // A case barcode stands for several units, so the scan adds that many.
            $qty = $qty * (float) ($product['qty_per_scan'] ?? 1.0);

            $opts = [];
            $overridePrice = (string) post('override_price');
            $discount      = post_num('discount');

            if ($overridePrice !== '' || $discount > 0) {
                // An override or an over-limit discount needs a supervisor at the
                // terminal. The PIN is checked here, against the specific
                // permission being exercised, before the line is priced.
                $permission = $overridePrice !== '' ? 'pos.price_override' : 'pos.discount';
                [$ok, $message, $supervisorId] = pin_authorise(
                    (string) post('supervisor_staff'), (string) ($_POST['supervisor_pin'] ?? ''), $permission
                );
                if (!$ok) {
                    throw new PosError($message);
                }
                $opts['overridden_by'] = $supervisorId;
                if ($overridePrice !== '') {
                    $opts['unit_price'] = num($overridePrice);
                }
                if ($discount > 0) {
                    $opts['discount']        = $discount;
                    $opts['discount_reason'] = (string) post('discount_reason');
                }
                flash_info($message);
            }

            $added = sale_add_line($saleId, (int) $product['id'], $qty, $opts);

            $msg = qty_fmt($qty) . ' x ' . $product['name'] . ' - ' . money((float) $added['line_total']);
            if ($added['promo'] !== null) {
                $msg .= ' (' . $added['promo'] . ')';
            }
            flash_ok($msg);

            if ((int) $product['age_restricted'] === 1) {
                flash_warn('Age-restricted item: check the customer is old enough before completing the sale.');
            }
            redirect('pos/');
        }

        /* ---- Basket housekeeping ---------------------------------- */
        if ($action === 'remove') {
            sale_remove_line($saleId, post_int('line_id'));
            flash_ok('Line removed.');
            redirect('pos/');
        }

        if ($action === 'void') {
            // A cashier may abandon a basket they are holding; voiding needs the
            // permission, and without it a supervisor PIN at the terminal.
            $voidBy = (int) $_SESSION['uid'];
            if (!can('pos.void')) {
                [$ok, $message, $supervisorId] = pin_authorise(
                    (string) post('supervisor_staff'), (string) ($_POST['supervisor_pin'] ?? ''), 'pos.void'
                );
                if (!$ok) {
                    throw new PosError($message);
                }
                $voidBy = $supervisorId;
            }
            sale_void($saleId, (string) post('reason_code'), (string) post('note'), $voidBy);
            unset($_SESSION['pos_sale_id']);
            flash_warn('Basket voided. The stock it was holding is back on the shelf.');
            redirect('pos/');
        }

        if ($action === 'park') {
            unset($_SESSION['pos_sale_id']);
            flash_info('Basket parked. Pick it up again from the parked list.');
            redirect('pos/');
        }

        if ($action === 'resume') {
            $resume = (int) post_int('sale_id');
            $sale   = q1('SELECT * FROM sales WHERE id = ? AND status = \'parked\' AND store_id = ?',
                [$resume, $storeId]);
            if (!$sale) {
                throw new PosError('That basket is no longer parked.');
            }
            $_SESSION['pos_sale_id'] = $resume;
            flash_ok('Basket ' . $sale['receipt_no'] . ' resumed.');
            redirect('pos/');
        }

        if ($action === 'customer') {
            $term = (string) post('customer_term');
            if ($saleId === 0) {
                throw new PosError('Scan something first, then add the customer.');
            }
            if ($term === '') {
                q('UPDATE sales SET customer_id = NULL WHERE id = ?', [$saleId]);
                flash_info('Customer removed from the basket.');
            } else {
                $c = q1('SELECT * FROM customers WHERE is_active = 1
                          AND (loyalty_card = ? OR phone = ? OR code = ?) LIMIT 1',
                        [$term, $term, $term]);
                if (!$c) {
                    throw new PosError('No loyalty account matches "' . $term . '".');
                }
                q('UPDATE sales SET customer_id = ? WHERE id = ?', [(int) $c['id'], $saleId]);
                flash_ok($c['name'] . ' added - ' . number_format((float) $c['points_balance'], 0)
                    . ' points, worth ' . money((float) $c['points_balance']
                        * setting_num('loyalty_point_value', (float) LOYALTY_POINT_VALUE)) . '.');
            }
            redirect('pos/');
        }

        /* ---- Take payment ---------------------------------------- */
        if ($action === 'pay') {
            $payments = [];
            foreach (['cash', 'card', 'transfer', 'mobile', 'voucher', 'points', 'credit'] as $m) {
                $amount = post_num('pay_' . $m);
                if ($amount <= 0) {
                    continue;
                }
                $payments[] = [
                    'method'    => $m,
                    'amount'    => $amount,
                    'tendered'  => $m === 'cash' ? post_num('tendered_cash', $amount) : null,
                    'reference' => (string) post('ref_' . $m),
                ];
            }
            $result = sale_complete($saleId, $payments, ['note' => (string) post('note')]);
            unset($_SESSION['pos_sale_id']);

            flash_ok(sprintf('Receipt %s complete - %s taken%s.%s',
                $result['receipt_no'], money((float) $result['total']),
                (float) $result['change'] > 0 ? ', change ' . money((float) $result['change']) : '',
                (float) $result['points'] > 0 ? ' ' . (int) $result['points'] . ' points earned.' : ''));

            redirect('pos/receipt', ['id' => $result['sale_id'], 'print' => 1]);
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('pos/');
    }
}

/* ------------------------------------------------------------------ */
/* No session: offer to open one                                      */
/* ------------------------------------------------------------------ */
if ($session === null) {
    $terminals = qa('SELECT * FROM terminals WHERE store_id = ? AND is_active = 1
                       AND id NOT IN (SELECT terminal_id FROM till_sessions WHERE status = \'open\')
                      ORDER BY code', [$storeId]);
    $busy = qa('SELECT t.code, u.full_name, ts.session_no FROM till_sessions ts
                  JOIN terminals t ON t.id = ts.terminal_id
                  JOIN users u ON u.id = ts.user_id
                 WHERE ts.store_id = ? AND ts.status = \'open\'', [$storeId]);

    layout_head(['title' => 'Open your till', 'subtitle' => (string) (current_store()['name'] ?? ''), 'bare' => true]);
    ?>
    <div style="max-width:520px;margin:0 auto">
      <?= card_open('Start a shift') ?>
        <?php if (!$terminals): ?>
          <?= empty_state('Every till in this store is already open.',
                'A till can only be open under one cashier at a time, so cash stays attributable.') ?>
        <?php else: ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="open_session">
            <label class="field">
              <span class="field__label">Till</span>
              <select name="terminal_id" required>
                <?= select_options(array_column($terminals, 'name', 'id'), '') ?>
              </select>
            </label>
            <label class="field">
              <span class="field__label">Opening float</span>
              <input type="text" name="opening_float" class="num-in" value="20000.00" inputmode="decimal" data-autofocus>
              <span class="field__hint">Count the drawer before you start. This is what you will be measured against.</span>
            </label>
            <button class="btn btn--lg btn--block" type="submit">Open the till</button>
          </form>
        <?php endif; ?>
      <?= card_close() ?>

      <?php if ($busy): ?>
        <?= card_open('Tills currently open') ?>
          <table class="data data--tight">
            <tbody>
            <?php foreach ($busy as $b): ?>
              <tr><td class="mono"><?= e($b['code']) ?></td><td><?= e($b['full_name']) ?></td>
                  <td class="mono tiny"><?= e($b['session_no']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?= card_close() ?>
      <?php endif; ?>
    </div>
    <?php
    layout_foot();
    exit;
}

/* ------------------------------------------------------------------ */
/* The till screen                                                    */
/* ------------------------------------------------------------------ */
$sale  = $saleId > 0 ? q1('SELECT * FROM sales WHERE id = ? AND status = \'parked\'', [$saleId]) : null;
if ($saleId > 0 && $sale === null) {
    // The basket was completed or voided in another tab.
    unset($_SESSION['pos_sale_id']);
    $saleId = 0;
}
$lines = $saleId > 0
    ? qa('SELECT sl.*, p.unit_code, p.age_restricted, pr.name AS promo_name
            FROM sale_lines sl
            JOIN products p ON p.id = sl.product_id
            LEFT JOIN promotions pr ON pr.id = sl.promotion_id
           WHERE sl.sale_id = ? ORDER BY sl.id', [$saleId])
    : [];

$customer = $sale && $sale['customer_id'] !== null
    ? q1('SELECT * FROM customers WHERE id = ?', [(int) $sale['customer_id']])
    : null;

$parked = qa(
    'SELECT s.id, s.receipt_no, s.total, s.line_count, s.created_at, c.name AS customer
       FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
      WHERE s.store_id = ? AND s.status = \'parked\' AND s.id <> ?
        AND s.line_count > 0
      ORDER BY s.created_at DESC LIMIT 10',
    [$storeId, $saleId]
);

$voidReasons     = qpairs('SELECT code, label FROM reason_codes WHERE applies_to = \'void\' AND is_active = 1 ORDER BY label');
$discountReasons = qpairs('SELECT code, label FROM reason_codes WHERE applies_to = \'discount\' AND is_active = 1 ORDER BY label');
$total           = $sale ? (float) $sale['total'] : 0.0;
$pointValue      = setting_num('loyalty_point_value', (float) LOYALTY_POINT_VALUE);

layout_head([
    'title'    => 'Till ' . $session['terminal_code'],
    'subtitle' => $session['session_no'] . ' - ' . current_user()['full_name'],
    'bare'     => true,
    'wide'     => true,
    'actions'  => '<a class="btn btn--ghost" href="' . e(base_url('pos/session')) . '">My shift</a>'
                . '<a class="btn btn--ghost" href="' . e(base_url('pos/cash')) . '">Cash drawer</a>',
]);
?>

<div class="pos">
  <!-- ================= Basket ================= -->
  <div>
    <?= card_open($sale ? 'Basket ' . $sale['receipt_no'] : 'New basket',
          $sale && $lines
            ? '<form method="post" class="inline-form"><input type="hidden" name="action" value="park">'
              . csrf_field() . '<button class="btn btn--sm btn--ghost" type="submit">Park</button></form>'
            : '') ?>

      <form method="post" class="pos__scan">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="scan">
        <input type="text" name="code" placeholder="Scan a barcode, or type a name or SKU"
               autocomplete="off" data-autofocus data-lookup required>
        <input type="text" name="qty" value="1" class="num-in" style="max-width:88px"
               inputmode="decimal" aria-label="Quantity">
        <button class="btn" type="submit">Add</button>
      </form>
      <div class="lookup" id="lookup-results"
           data-url="<?= e(base_url('api/product-lookup')) ?>"></div>

      <?php if (can_any(['pos.price_override', 'pos.discount']) || true): ?>
        <!-- Overrides sit behind a disclosure: they are the exception, and a
             cashier should have to go looking for them. -->
        <details class="mb1">
          <summary class="small muted" style="cursor:pointer">Price override or discount (needs a supervisor)</summary>
          <form method="post" class="mt1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="scan">
            <div class="form-grid form-grid--3">
              <label class="field">
                <span class="field__label">Barcode or SKU</span>
                <input type="text" name="code" required autocomplete="off">
              </label>
              <label class="field">
                <span class="field__label">Quantity</span>
                <input type="text" name="qty" value="1" class="num-in" inputmode="decimal">
              </label>
              <label class="field">
                <span class="field__label">New unit price</span>
                <input type="text" name="override_price" class="num-in" inputmode="decimal" placeholder="leave blank">
              </label>
              <label class="field">
                <span class="field__label">Line discount</span>
                <input type="text" name="discount" class="num-in" inputmode="decimal" placeholder="0.00">
              </label>
              <label class="field">
                <span class="field__label">Reason</span>
                <select name="discount_reason"><?= select_options($discountReasons, '', true, '-- reason --') ?></select>
              </label>
              <label class="field">
                <span class="field__label">Supervisor staff no.</span>
                <input type="text" name="supervisor_staff" autocomplete="off">
              </label>
              <label class="field">
                <span class="field__label">Supervisor PIN</span>
                <input type="password" name="supervisor_pin" inputmode="numeric"
                       maxlength="<?= PIN_LENGTH ?>" autocomplete="off">
              </label>
            </div>
            <p class="tiny muted">
              The override is limited to
              <?= e(pct_fmt(setting_num('price_override_max_pct', (float) PRICE_OVERRIDE_MAX_PCT))) ?>
              away from the shelf price. Whose PIN authorised it is recorded on the line.
            </p>
            <button class="btn btn--warn" type="submit">Add with override</button>
          </form>
        </details>
      <?php endif; ?>

      <?php if (!$lines): ?>
        <?= empty_state('The basket is empty.', 'Scan the first item to begin.') ?>
      <?php else: ?>
        <div class="table-wrap pos__basket">
        <table class="data">
          <thead><tr>
            <th>Item</th><th class="num">Qty</th><th class="num">Unit</th>
            <th class="num">Discount</th><th class="num">Line</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($lines as $l): ?>
            <tr>
              <td>
                <span class="desc"><?= e($l['description']) ?></span>
                <?php if ($l['promo_name'] !== null): ?>
                  <span class="promo"><?= e($l['promo_name']) ?></span>
                <?php endif; ?>
                <?php if ((int) $l['price_overridden'] === 1): ?>
                  <span class="over">Price overridden from <?= e(money((float) $l['list_price'])) ?></span>
                <?php endif; ?>
                <?php if ((int) $l['age_restricted'] === 1): ?>
                  <span class="over">Age restricted - check ID</span>
                <?php endif; ?>
              </td>
              <td class="num"><?= e(qty_fmt((float) $l['qty'])) ?>
                <span class="tiny muted"><?= e($l['unit_code']) ?></span></td>
              <?= num_cell($l['unit_price']) ?>
              <?= num_cell($l['line_discount']) ?>
              <?= num_cell($l['line_total']) ?>
              <td class="right">
                <form method="post" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="remove">
                  <input type="hidden" name="line_id" value="<?= (int) $l['id'] ?>">
                  <button class="btn btn--sm btn--ghost" type="submit" aria-label="Remove line">&times;</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    <?= card_close() ?>

    <!-- ---- Parked baskets ---------------------------------------- -->
    <?php if ($parked): ?>
      <?= card_open('Parked baskets (' . count($parked) . ')') ?>
        <table class="data data--tight">
          <tbody>
          <?php foreach ($parked as $p): ?>
            <tr>
              <td class="mono"><?= e($p['receipt_no']) ?></td>
              <td><?= e($p['customer'] ?? 'No customer') ?></td>
              <td class="num"><?= (int) $p['line_count'] ?> items</td>
              <?= num_cell($p['total']) ?>
              <td class="tiny muted"><?= e(ago((string) $p['created_at'])) ?></td>
              <td class="right">
                <form method="post" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="resume">
                  <input type="hidden" name="sale_id" value="<?= (int) $p['id'] ?>">
                  <button class="btn btn--sm btn--ghost" type="submit">Resume</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="tiny muted mt1">
          A parked basket holds its stock in reserve, so no other till can sell it. Clear them
          before closing your shift.
        </p>
      <?= card_close() ?>
    <?php endif; ?>
  </div>

  <!-- ================= Totals and payment ================= -->
  <div class="pos__pay">
    <div class="total-box">
      <dl>
        <dt>Items</dt><dd><?= e(qty_fmt(array_sum(array_map(fn ($l) => (float) $l['qty'], $lines)))) ?></dd>
        <dt>Net of VAT</dt><dd><?= e(money($sale ? (float) $sale['subtotal'] : 0)) ?></dd>
        <dt>VAT</dt><dd><?= e(money($sale ? (float) $sale['vat_total'] : 0)) ?></dd>
        <?php if ($sale && (float) $sale['discount_total'] > 0): ?>
          <dt>Discount given</dt><dd>-<?= e(money((float) $sale['discount_total'])) ?></dd>
        <?php endif; ?>
        <div class="grand" style="display:contents">
          <dt>To pay</dt><dd><?= e(money($total)) ?></dd>
        </div>
        <div class="change" style="display:contents">
          <dt>Change</dt><dd id="change-due">0.00</dd>
        </div>
      </dl>
    </div>

    <!-- ---- Loyalty customer ----------------------------------- -->
    <?= card_open($customer ? 'Customer' : 'Loyalty card') ?>
      <?php if ($customer): ?>
        <dl class="kv">
          <dt>Name</dt><dd><?= e($customer['name']) ?></dd>
          <dt>Points</dt><dd><?= e(number_format((float) $customer['points_balance'], 0)) ?>
            (<?= e(money((float) $customer['points_balance'] * $pointValue)) ?>)</dd>
          <?php if ((float) $customer['credit_limit'] > 0): ?>
            <dt>Account</dt><dd><?= e(money((float) $customer['balance'])) ?> of
              <?= e(money((float) $customer['credit_limit'])) ?> used</dd>
          <?php endif; ?>
        </dl>
        <form method="post" class="mt1">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="customer">
          <input type="hidden" name="customer_term" value="">
          <button class="btn btn--sm btn--ghost" type="submit">Remove customer</button>
        </form>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="customer">
          <div class="pos__scan">
            <input type="text" name="customer_term" placeholder="Card, phone or customer code" autocomplete="off">
            <button class="btn btn--ghost" type="submit">Add</button>
          </div>
        </form>
      <?php endif; ?>
    <?= card_close() ?>

    <!-- ---- Payment -------------------------------------------- -->
    <?php if ($lines): ?>
      <?= card_open('Take payment') ?>
        <form method="post" data-pay-form data-total="<?= e(number_format($total, 2, '.', '')) ?>"
              data-confirm="Complete this sale for <?= e(money($total)) ?>?">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="pay">

          <label class="field">
            <span class="field__label">Cash tendered</span>
            <input type="text" name="tendered_cash" class="num-in" inputmode="decimal"
                   id="tendered-cash" placeholder="0.00">
          </label>
          <label class="field">
            <span class="field__label">Cash towards the sale</span>
            <input type="text" name="pay_cash" class="num-in" inputmode="decimal"
                   id="pay-cash" data-amount placeholder="0.00">
            <span class="field__hint">Leave the tendered box higher to give change.</span>
          </label>

          <div class="quick">
            <button type="button" data-exact="#pay-cash">Exact cash</button>
            <?php foreach ([1000, 500, 200, 100] as $note): ?>
              <button type="button" data-note="<?= $note ?>" data-note-target="#tendered-cash">
                +<?= number_format($note) ?>
              </button>
            <?php endforeach; ?>
          </div>

          <details>
            <summary class="small muted" style="cursor:pointer">Card, transfer, voucher, points or account</summary>
            <div class="mt1">
              <div class="form-grid">
                <label class="field">
                  <span class="field__label">Card</span>
                  <input type="text" name="pay_card" class="num-in" data-amount inputmode="decimal" placeholder="0.00">
                </label>
                <label class="field">
                  <span class="field__label">Auth code</span>
                  <input type="text" name="ref_card" autocomplete="off">
                </label>
                <label class="field">
                  <span class="field__label">Bank transfer</span>
                  <input type="text" name="pay_transfer" class="num-in" data-amount inputmode="decimal" placeholder="0.00">
                </label>
                <label class="field">
                  <span class="field__label">Transfer reference</span>
                  <input type="text" name="ref_transfer" autocomplete="off">
                </label>
                <label class="field">
                  <span class="field__label">Mobile money</span>
                  <input type="text" name="pay_mobile" class="num-in" data-amount inputmode="decimal" placeholder="0.00">
                </label>
                <label class="field">
                  <span class="field__label">Mobile reference</span>
                  <input type="text" name="ref_mobile" autocomplete="off">
                </label>
                <label class="field">
                  <span class="field__label">Voucher</span>
                  <input type="text" name="pay_voucher" class="num-in" data-amount inputmode="decimal" placeholder="0.00">
                </label>
                <label class="field">
                  <span class="field__label">Voucher code</span>
                  <input type="text" name="ref_voucher" autocomplete="off">
                </label>
                <?php if ($customer): ?>
                  <label class="field">
                    <span class="field__label">Loyalty points</span>
                    <input type="text" name="pay_points" class="num-in" data-amount inputmode="decimal" placeholder="0.00">
                    <span class="field__hint">Up to <?= e(money((float) $customer['points_balance'] * $pointValue)) ?></span>
                  </label>
                  <?php if ((float) $customer['credit_limit'] > 0): ?>
                    <label class="field">
                      <span class="field__label">On account</span>
                      <input type="text" name="pay_credit" class="num-in" data-amount inputmode="decimal" placeholder="0.00">
                      <span class="field__hint">
                        <?= e(money((float) $customer['credit_limit'] - (float) $customer['balance'])) ?> available
                      </span>
                    </label>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          </details>

          <label class="field mt1">
            <span class="field__label">Note on the receipt (optional)</span>
            <input type="text" name="note" maxlength="255">
          </label>

          <button class="btn btn--lg btn--block" type="submit">
            Complete sale &mdash; <?= e(money($total)) ?>
          </button>
        </form>
      <?= card_close() ?>

      <!-- ---- Void ------------------------------------------------ -->
      <?= card_open('Void this basket') ?>
        <form method="post"
              data-confirm="Void this basket? Nothing will be charged and the stock goes back on the shelf.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="void">
          <label class="field">
            <span class="field__label">Reason</span>
            <select name="reason_code" required><?= select_options($voidReasons, '', true, '-- reason --') ?></select>
          </label>
          <label class="field">
            <span class="field__label">Note</span>
            <input type="text" name="note" maxlength="255">
          </label>
          <?php if (!can('pos.void')): ?>
            <div class="form-grid">
              <label class="field">
                <span class="field__label">Supervisor staff no.</span>
                <input type="text" name="supervisor_staff" autocomplete="off" required>
              </label>
              <label class="field">
                <span class="field__label">Supervisor PIN</span>
                <input type="password" name="supervisor_pin" inputmode="numeric"
                       maxlength="<?= PIN_LENGTH ?>" autocomplete="off" required>
              </label>
            </div>
            <p class="tiny muted">Your role does not permit voids, so a supervisor must authorise this one.</p>
          <?php endif; ?>
          <button class="btn btn--danger btn--block" type="submit">Void basket</button>
        </form>
      <?= card_close() ?>
    <?php endif; ?>
  </div>
</div>

<?php layout_foot(); ?>
