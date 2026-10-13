<?php
/**
 * One purchase order: build it while it is a draft, read it afterwards.
 *
 * Lines can only be changed on a draft. Once submitted the order is a
 * commitment someone is being asked to approve, and an order whose contents can
 * shift after approval is not an approved order.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('purchasing.view');

$storeId = current_store_id();
$id      = get_int('id');
$isNew   = $id === 0;

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');

        if ($action === 'create') {
            require_can('po.create');
            $supplierId = post_int('supplier_id');
            if ($supplierId === 0) {
                throw new StockError('Choose a supplier.');
            }
            $lead = (int) qv('SELECT lead_time_days FROM suppliers WHERE id = ?', [$supplierId],
                (float) DEFAULT_REORDER_LEAD_DAYS);

            $id = db_tx(fn (): int => db_insert('purchase_orders', [
                'po_no'         => next_number('po', $storeId),
                'store_id'      => $storeId,
                'supplier_id'   => $supplierId,
                'status'        => 'draft',
                'order_date'    => date_or_null(post('order_date')) ?? date('Y-m-d'),
                'expected_date' => date_or_null(post('expected_date')) ?? date('Y-m-d', strtotime("+$lead days")),
                'note'          => (string) post('note') !== '' ? (string) post('note') : null,
                'created_by'    => (int) $_SESSION['uid'],
            ]));

            audit_log('po.draft', 'purchase_order', $id, 'Draft purchase order started', null, null, 'info');
            flash_ok('Draft order started. Add the lines.');
            redirect('inventory/purchase-order', ['id' => $id]);
        }

        $po = q1('SELECT * FROM purchase_orders WHERE id = ? AND store_id = ?', [$id, $storeId]);
        if (!$po) {
            throw new StockError('That order is not in this store.');
        }

        if ($action === 'add_line') {
            require_can('po.create');
            if ($po['status'] !== 'draft') {
                throw new StockError('Lines can only be added while the order is a draft.');
            }
            $productId = post_int('product_id');
            $qty       = post_num('qty');
            if ($productId === 0 || $qty <= 0) {
                throw new StockError('Choose a product and a quantity.');
            }
            $p = q1('SELECT * FROM products WHERE id = ? AND is_active = 1', [$productId]);
            if (!$p) {
                throw new StockError('That product is not active.');
            }
            $cost = (string) post('unit_cost') !== '' ? post_num('unit_cost') : (float) $p['cost_price'];

            db_tx(function () use ($id, $productId, $qty, $cost, $p): void {
                $existing = q1('SELECT * FROM purchase_order_lines WHERE po_id = ? AND product_id = ?',
                    [$id, $productId]);
                if ($existing) {
                    // Adding the same product twice is a mistake nine times in
                    // ten, so the quantities merge rather than producing two
                    // lines the supplier has to reconcile.
                    q('UPDATE purchase_order_lines SET qty_ordered = qty_ordered + ?, unit_cost = ? WHERE id = ?',
                      [$qty, $cost, (int) $existing['id']]);
                } else {
                    db_insert('purchase_order_lines', [
                        'po_id'       => $id,
                        'product_id'  => $productId,
                        'qty_ordered' => $qty,
                        'unit_cost'   => $cost,
                        'vat_rate'    => (float) $p['vat_rate'],
                    ]);
                }
                po_recalculate($id);
            });
            flash_ok(qty_fmt($qty) . ' x ' . $p['name'] . ' added.');
            redirect('inventory/purchase-order', ['id' => $id]);
        }

        if ($action === 'remove_line') {
            require_can('po.create');
            if ($po['status'] !== 'draft') {
                throw new StockError('Lines can only be removed while the order is a draft.');
            }
            db_tx(function () use ($id): void {
                q('DELETE FROM purchase_order_lines WHERE id = ? AND po_id = ?',
                  [post_int('line_id'), $id]);
                po_recalculate($id);
            });
            flash_ok('Line removed.');
            redirect('inventory/purchase-order', ['id' => $id]);
        }

        if ($action === 'submit') {
            require_can('po.create');
            po_submit($id, (int) $_SESSION['uid']);
            flash_ok('Order submitted for approval.');
            redirect('inventory/purchase-order', ['id' => $id]);
        }

        if ($action === 'approve') {
            require_can('po.approve');
            po_approve($id, (int) $_SESSION['uid']);
            flash_ok('Order approved.');
            redirect('inventory/purchase-order', ['id' => $id]);
        }

        if ($action === 'cancel') {
            require_can('po.create');
            $reason = trim((string) post('reason'));
            if ($reason === '') {
                throw new StockError('Say why the order is being cancelled.');
            }
            po_cancel($id, (int) $_SESSION['uid'], $reason);
            flash_ok('Order cancelled.');
            redirect('inventory/purchase-orders');
        }
    } catch (SodViolation $e) {
        flash_err($e->getMessage());
        redirect('inventory/purchase-order', ['id' => $id]);
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect($isNew ? 'inventory/purchase-order' : 'inventory/purchase-order?id=' . $id);
    }
}

/* ---- New order form -------------------------------------------- */
if ($isNew) {
    require_can('po.create');
    $suppliers = qpairs('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name');

    layout_head(['title' => 'New purchase order', 'subtitle' => 'Start a draft']);
    ?>
    <?= card_open('Who are we ordering from?') ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <label class="field">
            <span class="field__label">Supplier</span>
            <select name="supplier_id" required>
              <?= select_options($suppliers, '', true, '-- choose --') ?>
            </select>
          </label>
          <label class="field">
            <span class="field__label">Order date</span>
            <input type="date" name="order_date" value="<?= e(date('Y-m-d')) ?>">
          </label>
          <label class="field">
            <span class="field__label">Expected delivery</span>
            <input type="date" name="expected_date">
            <span class="field__hint">Defaults to the supplier's lead time. Used to flag overdue orders.</span>
          </label>
        </div>
        <label class="field">
          <span class="field__label">Note</span>
          <input type="text" name="note" maxlength="255">
        </label>
        <button class="btn" type="submit">Start draft order</button>
        <a class="btn btn--ghost" href="<?= e(base_url('inventory/purchase-orders')) ?>">Cancel</a>
      </form>
    <?= card_close() ?>
    <?php
    layout_foot();
    exit;
}

/* ---- Existing order -------------------------------------------- */
$po = q1(
    'SELECT po.*, s.name AS supplier_name, s.contact_name, s.phone, s.email,
            s.payment_terms_days, cb.full_name AS created_by_name,
            ab.full_name AS approved_by_name, xb.full_name AS cancelled_by_name
       FROM purchase_orders po
       JOIN suppliers s ON s.id = po.supplier_id
       JOIN users cb ON cb.id = po.created_by
       LEFT JOIN users ab ON ab.id = po.approved_by
       LEFT JOIN users xb ON xb.id = po.cancelled_by
      WHERE po.id = ? AND po.store_id = ?',
    [$id, $storeId]
);
if (!$po) {
    http_response_code(404);
    require APP_ROOT . '/404.php';
    exit;
}

$lines = qa(
    'SELECT pol.*, p.sku, p.name, p.unit_code, p.pack_size,
            COALESCE(sl.qty_on_hand,0) AS qty_on_hand
       FROM purchase_order_lines pol
       JOIN products p ON p.id = pol.product_id
       LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
      WHERE pol.po_id = ? ORDER BY p.name',
    [$storeId, $id]
);
$receipts = qa(
    'SELECT g.*, u.full_name AS posted_by_name FROM grns g
       LEFT JOIN users u ON u.id = g.posted_by
      WHERE g.po_id = ? ORDER BY g.id',
    [$id]
);
$isDraft = $po['status'] === 'draft';
$isMine  = (int) $po['created_by'] === (int) $_SESSION['uid'];

$actions = '';
if ($isDraft && can('po.create') && $lines) {
    $actions .= '<form method="post" class="inline-form">' . csrf_field()
        . '<input type="hidden" name="action" value="submit">'
        . '<button class="btn" type="submit">Submit for approval</button></form>';
}
if ($po['status'] === 'submitted' && can('po.approve') && !$isMine) {
    $actions .= '<form method="post" class="inline-form" data-confirm="Approve this order?">'
        . csrf_field() . '<input type="hidden" name="action" value="approve">'
        . '<button class="btn" type="submit">Approve</button></form>';
}
if (in_array($po['status'], ['approved', 'partly_received'], true) && can('grn.create')) {
    $actions .= '<a class="btn" href="' . e(base_url('inventory/grn', ['po_id' => $id])) . '">Receive goods</a>';
}
$actions .= '<button class="btn btn--ghost" data-print>Print</button>';

$products = $isDraft
    ? qpairs('SELECT id, CONCAT(sku, " - ", name) FROM products
               WHERE is_active = 1 AND (supplier_id = ? OR supplier_id IS NULL) ORDER BY name',
             [(int) $po['supplier_id']])
    : [];

layout_head([
    'title'    => 'Order ' . $po['po_no'],
    'subtitle' => $po['supplier_name'] . ' - ' . date_fmt((string) $po['order_date']),
    'actions'  => $actions,
]);
?>

<?php if ($po['status'] === 'submitted'): ?>
  <div class="alert alert--<?= $isMine ? 'warning' : 'info' ?>">
    <?php if ($isMine): ?>
      This order is waiting for approval. You raised it, so you cannot approve it &mdash;
      another authorised user must.
    <?php else: ?>
      This order is waiting for approval. It was raised by <?= e($po['created_by_name']) ?>.
    <?php endif; ?>
  </div>
<?php elseif ($po['status'] === 'cancelled'): ?>
  <div class="alert alert--error">
    Cancelled by <?= e($po['cancelled_by_name'] ?? 'unknown') ?> on
    <?= e(date_fmt((string) $po['cancelled_at'], true)) ?>.
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Order total', money((float) $po['total']),
        'net ' . money((float) $po['subtotal']) . ' + VAT ' . money((float) $po['vat_total'])) ?>
  <?= tile('Lines', number_format(count($lines)),
        qty_fmt(array_sum(array_map(fn ($l) => (float) $l['qty_ordered'], $lines))) . ' units ordered') ?>
  <?= tile('Received', qty_fmt(array_sum(array_map(fn ($l) => (float) $l['qty_received'], $lines))),
        count($receipts) . ' delivery(ies) posted',
        $po['status'] === 'received' ? 'ok' : 'info') ?>
  <?= tile('Status', ucwords(str_replace('_', ' ', (string) $po['status'])),
        $po['expected_date'] !== null ? 'expected ' . date_fmt((string) $po['expected_date']) : '',
        $po['status'] === 'cancelled' ? 'bad' : ($po['status'] === 'received' ? 'ok' : 'warn')) ?>
</div>

<div class="grid-2">
  <?= card_open('Supplier') ?>
    <dl class="kv">
      <dt>Name</dt><dd><?= e($po['supplier_name']) ?></dd>
      <dt>Contact</dt><dd><?= e($po['contact_name'] ?? '-') ?></dd>
      <dt>Phone</dt><dd><?= e($po['phone'] ?? '-') ?></dd>
      <dt>Email</dt><dd><?= e($po['email'] ?? '-') ?></dd>
      <dt>Payment terms</dt><dd><?= (int) $po['payment_terms_days'] ?> days</dd>
    </dl>
  <?= card_close() ?>

  <?= card_open('Order trail') ?>
    <dl class="kv">
      <dt>Raised by</dt><dd><?= e($po['created_by_name']) ?>
        <span class="tiny muted"><?= e(date_fmt((string) $po['created_at'], true)) ?></span></dd>
      <?php if ($po['submitted_at'] !== null): ?>
        <dt>Submitted</dt><dd><?= e(date_fmt((string) $po['submitted_at'], true)) ?></dd>
      <?php endif; ?>
      <?php if ($po['approved_at'] !== null): ?>
        <dt>Approved by</dt><dd><?= e($po['approved_by_name'] ?? '-') ?>
          <span class="tiny muted"><?= e(date_fmt((string) $po['approved_at'], true)) ?></span></dd>
      <?php endif; ?>
      <?php if ($po['note'] !== null): ?>
        <dt>Note</dt><dd><?= e((string) $po['note']) ?></dd>
      <?php endif; ?>
    </dl>
  <?= card_close() ?>
</div>

<?= card_open('Lines') ?>
  <?php if (!$lines): ?>
    <?= empty_state('No lines on this order yet.',
          $isDraft ? 'Add the first one below.' : '') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>SKU</th><th>Product</th><th class="num">On hand</th>
        <th class="num">Ordered</th><th class="num">Received</th><th class="num">Outstanding</th>
        <th class="num">Unit cost</th><th class="num">Line total</th>
        <?= $isDraft && can('po.create') ? '<th></th>' : '' ?>
      </tr></thead>
      <tbody>
      <?php foreach ($lines as $l):
          $outstanding = (float) $l['qty_ordered'] - (float) $l['qty_received'];
          $over        = $outstanding < -0.0005; ?>
        <tr class="<?= $over ? 'row-warn' : '' ?>">
          <td class="mono tiny"><?= e($l['sku']) ?></td>
          <td>
            <a href="<?= e(base_url('inventory/product', ['id' => (int) $l['product_id']])) ?>">
              <?= e($l['name']) ?></a>
            <?php if ((float) $l['pack_size'] > 1): ?>
              <br><span class="tiny muted"><?= e(qty_fmt((float) $l['pack_size'])) ?> per case</span>
            <?php endif; ?>
          </td>
          <td class="num"><?= e(qty_fmt((float) $l['qty_on_hand'])) ?></td>
          <td class="num"><?= e(qty_fmt((float) $l['qty_ordered'])) ?>
            <span class="tiny muted"><?= e($l['unit_code']) ?></span></td>
          <td class="num"><?= e(qty_fmt((float) $l['qty_received'])) ?></td>
          <td class="num <?= $over ? 'neg' : '' ?>">
            <?= e(qty_fmt($outstanding)) ?>
            <?php if ($over): ?><br><?= badge('Over-received', 'warn') ?><?php endif; ?>
          </td>
          <?= num_cell($l['unit_cost']) ?>
          <?= num_cell($l['line_total']) ?>
          <?php if ($isDraft && can('po.create')): ?>
            <td class="right">
              <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="remove_line">
                <input type="hidden" name="line_id" value="<?= (int) $l['id'] ?>">
                <button class="btn btn--sm btn--ghost" type="submit">&times;</button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6">Net of VAT</td>
          <td></td><td class="num"><?= e(money((float) $po['subtotal'])) ?></td>
          <?= $isDraft && can('po.create') ? '<td></td>' : '' ?>
        </tr>
        <tr>
          <td colspan="6">VAT</td>
          <td></td><td class="num"><?= e(money((float) $po['vat_total'])) ?></td>
          <?= $isDraft && can('po.create') ? '<td></td>' : '' ?>
        </tr>
        <tr>
          <td colspan="6"><strong>Total</strong></td>
          <td></td><td class="num"><strong><?= e(money((float) $po['total'])) ?></strong></td>
          <?= $isDraft && can('po.create') ? '<td></td>' : '' ?>
        </tr>
      </tfoot>
    </table>
    </div>
  <?php endif; ?>

  <?php if ($isDraft && can('po.create')): ?>
    <form method="post" class="mt1 no-print">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_line">
      <div class="form-grid form-grid--3">
        <label class="field">
          <span class="field__label">Product</span>
          <select name="product_id" required><?= select_options($products, '', true, '-- choose --') ?></select>
        </label>
        <label class="field">
          <span class="field__label">Quantity</span>
          <input type="text" name="qty" class="num-in" required inputmode="decimal">
        </label>
        <label class="field">
          <span class="field__label">Unit cost (blank = list)</span>
          <input type="text" name="unit_cost" class="num-in" inputmode="decimal">
        </label>
      </div>
      <button class="btn" type="submit">Add line</button>
    </form>
  <?php endif; ?>
<?= card_close() ?>

<?php if ($receipts): ?>
<?= card_open('Deliveries against this order') ?>
  <table class="data data--tight">
    <thead><tr>
      <th>Receipt</th><th>Date</th><th>Supplier invoice</th>
      <th class="num">Total</th><th>Posted by</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($receipts as $g): ?>
      <tr>
        <td class="mono tiny">
          <a href="<?= e(base_url('inventory/grn', ['id' => (int) $g['id']])) ?>"><?= e($g['grn_no']) ?></a>
        </td>
        <td class="nowrap tiny"><?= e(date_fmt((string) $g['received_date'])) ?></td>
        <td class="mono tiny"><?= e($g['supplier_invoice_no'] ?? '-') ?></td>
        <?= num_cell($g['total']) ?>
        <td class="tiny"><?= e($g['posted_by_name'] ?? '-') ?></td>
        <td><?= status_badge((string) $g['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?= card_close() ?>
<?php endif; ?>

<?php if (in_array($po['status'], ['draft', 'submitted', 'approved'], true) && can('po.create')): ?>
  <?= card_open('Cancel this order') ?>
    <form method="post" class="btn-row"
          data-confirm="Cancel order <?= e($po['po_no']) ?>?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel">
      <input type="text" name="reason" placeholder="Why it is being cancelled"
             style="max-width:340px" required>
      <button class="btn btn--danger" type="submit">Cancel order</button>
    </form>
    <p class="small muted mt1">
      An order with goods already received against it cannot be cancelled &mdash; close it off by
      receiving what arrived instead, so the stock and the paperwork agree.
    </p>
  <?= card_close() ?>
<?php endif; ?>

<?php layout_foot(); ?>
