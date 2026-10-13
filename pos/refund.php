<?php
/**
 * Raise a refund against a receipt.
 *
 * Always against an original sale, never free-standing: a refund with no
 * receipt behind it is the simplest way cash leaves a till. The per-line
 * "already refunded" figure is enforced in refund_create(), so this page can
 * show what is still refundable without being the thing that guarantees it.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('refund.request');

$storeId = current_store_id();
$saleId  = get_int('sale_id');
$session = session_current();

/* ---- Find the receipt ------------------------------------------- */
if ($saleId === 0 && (string) get('q') !== '') {
    $found = q1('SELECT id FROM sales WHERE receipt_no = ? AND store_id = ?',
        [trim((string) get('q')), $storeId]);
    if ($found) {
        redirect('pos/refund', ['sale_id' => (int) $found['id']]);
    }
    flash_err('No receipt in this store matches "' . get('q') . '".');
}

if (is_post()) {
    csrf_require();
    try {
        $items = [];
        foreach ((array) ($_POST['qty'] ?? []) as $lineId => $qty) {
            $q = num($qty, 0.0);
            if ($q > 0) {
                $items[(int) $lineId] = $q;
            }
        }
        $result = refund_create(
            post_int('sale_id'),
            $items,
            (string) post('reason_code'),
            (string) post('note'),
            post('restock') === '1',
            (string) post('method'),
            (int) $_SESSION['uid'],
            $session !== null ? (int) $session['id'] : null
        );

        if ($result['posted']) {
            flash_ok(sprintf('Refund %s posted. %s returned to the customer.',
                $result['refund_no'], money((float) $result['total'])));
        } else {
            flash_warn(sprintf('Refund %s raised for %s and is waiting for approval. '
                . 'No money has left the till yet.',
                $result['refund_no'], money((float) $result['total'])));
        }
        redirect('sales/refunds');
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('pos/refund', $saleId > 0 ? ['sale_id' => $saleId] : []);
    }
}

$sale  = null;
$lines = [];
if ($saleId > 0) {
    $sale = q1(
        'SELECT s.*, u.full_name AS cashier, c.name AS customer, st.name AS store_name
           FROM sales s
           JOIN users u ON u.id = s.cashier_id
           JOIN stores st ON st.id = s.store_id
           LEFT JOIN customers c ON c.id = s.customer_id
          WHERE s.id = ? AND s.store_id = ?',
        [$saleId, $storeId]
    );
    if ($sale) {
        $lines = qa(
            'SELECT sl.*, p.unit_code, p.name AS current_name
               FROM sale_lines sl JOIN products p ON p.id = sl.product_id
              WHERE sl.sale_id = ? ORDER BY sl.id',
            [$saleId]
        );
    }
}

$reasons = qpairs('SELECT code, label FROM reason_codes WHERE applies_to = \'refund\' AND is_active = 1 ORDER BY label');
$limit   = setting_num('refund_approval_limit', (float) REFUND_APPROVAL_LIMIT);

layout_head([
    'title'    => 'Refund',
    'subtitle' => $sale !== null ? 'Against receipt ' . $sale['receipt_no'] : 'Find the original receipt',
]);
?>

<?php if ($sale === null): ?>
  <?= card_open('Which receipt?') ?>
    <form method="get">
      <label class="field">
        <span class="field__label">Receipt number</span>
        <input type="text" name="q" value="<?= e((string) get('q')) ?>"
               placeholder="RC-IKJ-202610-000123" data-autofocus required>
        <span class="field__hint">It is printed at the top and bottom of the customer's receipt.</span>
      </label>
      <button class="btn" type="submit">Find receipt</button>
      <a class="btn btn--ghost" href="<?= e(base_url('sales/')) ?>">Browse the register</a>
    </form>
  <?= card_close() ?>

<?php elseif (!in_array($sale['status'], ['completed', 'partly_refunded'], true)): ?>
  <div class="alert alert--error">
    Receipt <?= e($sale['receipt_no']) ?> is <strong><?= e($sale['status']) ?></strong>
    and cannot be refunded.
    <?php if ($sale['status'] === 'voided'): ?>
      It was voided before payment, so no money changed hands.
    <?php elseif ($sale['status'] === 'refunded'): ?>
      Everything on it has already been refunded.
    <?php endif; ?>
  </div>
  <a class="btn" href="<?= e(base_url('sales/view', ['id' => $saleId])) ?>">See the receipt</a>

<?php else: ?>
  <?php if ($session === null): ?>
    <div class="alert alert--warning">
      You have no till open. A cash refund needs a drawer to come out of &mdash; either open your
      till first, or refund to card or transfer.
    </div>
  <?php endif; ?>

  <?php if ((int) $sale['cashier_id'] === (int) $_SESSION['uid']): ?>
    <div class="alert alert--info">
      You rang up this sale. Refunding your own transaction is allowed, because on a one-till
      shift there is nobody else, but it is recorded and appears in the exception queue for review.
    </div>
  <?php endif; ?>

  <div class="tiles">
    <?= tile('Original total', money((float) $sale['total']),
          date_fmt($sale['sold_at'], true)) ?>
    <?= tile('Already refunded', money((float) $sale['refunded_total']),
          (float) $sale['refunded_total'] > 0 ? 'on earlier refunds' : 'nothing yet',
          (float) $sale['refunded_total'] > 0 ? 'warn' : 'mute') ?>
    <?= tile('Still refundable',
          money((float) $sale['total'] - (float) $sale['refunded_total']), 'at most') ?>
    <?= tile('Sold by', (string) $sale['cashier'],
          $sale['customer'] !== null ? 'to ' . $sale['customer'] : 'no customer recorded', 'mute') ?>
  </div>

  <form method="post" data-confirm="Raise this refund?">
    <?= csrf_field() ?>
    <input type="hidden" name="sale_id" value="<?= $saleId ?>">

    <?= card_open('What is coming back?') ?>
      <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th>Item</th><th class="num">Sold</th><th class="num">Already back</th>
          <th class="num">Refundable</th><th class="num">Unit price</th><th class="num">Refund qty</th>
        </tr></thead>
        <tbody>
        <?php foreach ($lines as $l):
            $refundable = (float) $l['qty'] - (float) $l['qty_refunded'];
            $unitPrice  = (float) $l['qty'] > 0
                ? money_round((float) $l['line_total'] / (float) $l['qty']) : 0.0; ?>
          <tr class="<?= $refundable <= 0 ? 'row-warn' : '' ?>">
            <td>
              <?= e($l['description']) ?>
              <span class="tiny muted"><?= e($l['unit_code']) ?></span>
            </td>
            <td class="num"><?= e(qty_fmt((float) $l['qty'])) ?></td>
            <td class="num"><?= e(qty_fmt((float) $l['qty_refunded'])) ?></td>
            <td class="num"><strong><?= e(qty_fmt($refundable)) ?></strong></td>
            <td class="num"><?= e(money($unitPrice)) ?>
              <br><span class="tiny muted">as charged</span></td>
            <td class="num">
              <?php if ($refundable > 0): ?>
                <input type="text" name="qty[<?= (int) $l['id'] ?>]" class="num-in"
                       style="max-width:90px" inputmode="decimal" placeholder="0"
                       value="">
              <?php else: ?>
                <span class="muted">-</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <p class="small muted mt1">
        Items are refunded at the price actually charged, including any promotion that applied
        at the time &mdash; not at today's shelf price. Leave a quantity blank to keep that line.
      </p>
    <?= card_close() ?>

    <div class="grid-2">
      <?= card_open('Why, and how') ?>
        <label class="field">
          <span class="field__label">Reason</span>
          <select name="reason_code" required><?= select_options($reasons, '', true, '-- reason --') ?></select>
        </label>
        <label class="field">
          <span class="field__label">Repayment method</span>
          <select name="method" required>
            <option value="cash">Cash from the drawer</option>
            <option value="card">Back to card</option>
            <option value="transfer">Bank transfer</option>
            <option value="mobile">Mobile money</option>
            <?php if ($sale['customer'] !== null): ?>
              <option value="credit">Credit the customer's account</option>
            <?php endif; ?>
          </select>
        </label>
        <label class="field">
          <span class="field__label">Note</span>
          <textarea name="note" placeholder="What the customer said, and what you saw"></textarea>
        </label>
      <?= card_close() ?>

      <?= card_open('Does the stock go back on the shelf?') ?>
        <div class="check">
          <input type="radio" name="restock" value="1" id="restock-yes" checked>
          <label for="restock-yes">
            <strong>Yes, put it back</strong><br>
            <span class="small muted">
              The goods are saleable. They return to the batch they came from, so expiry
              tracking stays correct.
            </span>
          </label>
        </div>
        <div class="check">
          <input type="radio" name="restock" value="0" id="restock-no">
          <label for="restock-no">
            <strong>No, write it off</strong><br>
            <span class="small muted">
              Spoiled, damaged or opened. The customer gets their money, the stock stays out,
              and the loss shows in the shrinkage report rather than vanishing.
            </span>
          </label>
        </div>
      <?= card_close() ?>
    </div>

    <?= card_open('Authorisation') ?>
      <p class="small">
        Refunds over <strong><?= e(money($limit)) ?></strong> wait for a supervisor's approval, and
        so does any refund raised by someone without the approve permission.
        <?php if (can('refund.approve')): ?>
          You hold <code>refund.approve</code>, so a refund under the limit will post immediately.
        <?php else: ?>
          Your role cannot approve refunds, so this one will go to the approval queue whatever
          its value. No money leaves the till until someone else approves it.
        <?php endif; ?>
      </p>
      <button class="btn btn--lg" type="submit">Raise refund</button>
      <a class="btn btn--ghost" href="<?= e(base_url('sales/view', ['id' => $saleId])) ?>">Cancel</a>
    <?= card_close() ?>
  </form>
<?php endif; ?>

<?php layout_foot(); ?>
