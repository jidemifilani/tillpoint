<?php
/**
 * Record and post one delivery.
 *
 * Started from a purchase order (?po_id=) the lines are pre-filled with what is
 * outstanding, which is what a receiving clerk actually needs: confirm, correct
 * the odd figure, post. A direct receipt with no order behind it is allowed
 * because deliveries do turn up unordered - but it is visible as "direct" on
 * every list, because an unordered delivery is worth noticing.
 *
 * Posting is where stock and cost enter the system, so it is also where
 * over-receipts and cost creep against the agreed price are flagged (see
 * grn_post()).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('grn.create');

$storeId = current_store_id();
$id      = get_int('id');
$poId    = get_int('po_id');

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');

        /* ---- Start a receipt --------------------------------------- */
        if ($action === 'create') {
            $fromPo     = post_int('po_id');
            $supplierId = post_int('supplier_id');

            if ($fromPo > 0) {
                $po = q1('SELECT * FROM purchase_orders WHERE id = ? AND store_id = ?', [$fromPo, $storeId]);
                if (!$po) {
                    throw new StockError('That order is not in this store.');
                }
                if (!in_array($po['status'], ['approved', 'partly_received'], true)) {
                    throw new StockError('Goods can only be received against an approved order; '
                        . 'this one is ' . $po['status'] . '.');
                }
                $supplierId = (int) $po['supplier_id'];
            } elseif ($supplierId === 0) {
                throw new StockError('Choose the supplier.');
            }

            $id = db_tx(function () use ($storeId, $fromPo, $supplierId): int {
                $grnId = db_insert('grns', [
                    'grn_no'              => next_number('grn', $storeId),
                    'po_id'               => $fromPo > 0 ? $fromPo : null,
                    'store_id'            => $storeId,
                    'supplier_id'         => $supplierId,
                    'supplier_invoice_no' => (string) post('supplier_invoice_no') !== ''
                        ? (string) post('supplier_invoice_no') : null,
                    'received_date'       => date_or_null(post('received_date')) ?? date('Y-m-d'),
                    'status'              => 'draft',
                    'note'                => (string) post('note') !== '' ? (string) post('note') : null,
                    'received_by'         => (int) $_SESSION['uid'],
                ]);

                // Pre-fill from what the order still owes us.
                if ($fromPo > 0) {
                    foreach (qa('SELECT pol.*, p.track_batches, p.shelf_life_days, p.sku
                                   FROM purchase_order_lines pol
                                   JOIN products p ON p.id = pol.product_id
                                  WHERE pol.po_id = ? AND pol.qty_ordered > pol.qty_received',
                                [$fromPo]) as $pol) {
                        $outstanding = (float) $pol['qty_ordered'] - (float) $pol['qty_received'];
                        db_insert('grn_lines', [
                            'grn_id'      => $grnId,
                            'po_line_id'  => (int) $pol['id'],
                            'product_id'  => (int) $pol['product_id'],
                            'qty'         => $outstanding,
                            'unit_cost'   => (float) $pol['unit_cost'],
                            'vat_rate'    => (float) $pol['vat_rate'],
                            'batch_no'    => (int) $pol['track_batches'] === 1
                                ? 'B' . date('ymd') . '-' . $pol['sku'] : null,
                            'expiry_date' => (int) $pol['track_batches'] === 1
                                             && $pol['shelf_life_days'] !== null
                                ? date('Y-m-d', strtotime('+' . (int) $pol['shelf_life_days'] . ' days'))
                                : null,
                        ]);
                    }
                    grn_recalculate($grnId);
                }
                return $grnId;
            });

            audit_log('grn.draft', 'grn', $id, 'Delivery recorded, not yet posted', null, null, 'info');
            flash_ok('Delivery started. Check the quantities, costs and dates, then post it to stock.');
            redirect('inventory/grn', ['id' => $id]);
        }

        $grn = q1('SELECT * FROM grns WHERE id = ? AND store_id = ?', [$id, $storeId]);
        if (!$grn) {
            throw new StockError('That delivery is not in this store.');
        }
        if ($grn['status'] !== 'draft' && $action !== 'nothing') {
            throw new StockError('This delivery has already been ' . $grn['status'] . ' and cannot be changed.');
        }

        /* ---- Save the lines in bulk -------------------------------- */
        if ($action === 'save_lines') {
            db_tx(function () use ($id): void {
                foreach ((array) ($_POST['line'] ?? []) as $lineId => $vals) {
                    $lineId = (int) $lineId;
                    $line   = q1('SELECT * FROM grn_lines WHERE id = ? AND grn_id = ?', [$lineId, $id]);
                    if (!$line) {
                        continue;
                    }
                    $qty = num($vals['qty'] ?? 0, 0.0);
                    if ($qty <= 0) {
                        // A zero means nothing of that line arrived, so the line
                        // goes rather than posting a zero-quantity movement.
                        q('DELETE FROM grn_lines WHERE id = ?', [$lineId]);
                        continue;
                    }
                    db_update('grn_lines', [
                        'qty'         => $qty,
                        'unit_cost'   => num($vals['unit_cost'] ?? 0, (float) $line['unit_cost']),
                        'batch_no'    => trim((string) ($vals['batch_no'] ?? '')) !== ''
                            ? trim((string) $vals['batch_no']) : null,
                        'expiry_date' => date_or_null($vals['expiry_date'] ?? null),
                    ], 'id = :id', ['id' => $lineId]);
                }
                grn_recalculate($id);
            });
            flash_ok('Lines saved.');
            redirect('inventory/grn', ['id' => $id]);
        }

        /* ---- Add a line not on the order --------------------------- */
        if ($action === 'add_line') {
            $productId = post_int('product_id');
            $qty       = post_num('qty');
            if ($productId === 0 || $qty <= 0) {
                throw new StockError('Choose a product and a quantity.');
            }
            $p = q1('SELECT * FROM products WHERE id = ? AND is_active = 1', [$productId]);
            if (!$p) {
                throw new StockError('That product is not active.');
            }
            db_tx(function () use ($id, $productId, $qty, $p): void {
                db_insert('grn_lines', [
                    'grn_id'      => $id,
                    'product_id'  => $productId,
                    'qty'         => $qty,
                    'unit_cost'   => (string) post('unit_cost') !== ''
                        ? post_num('unit_cost') : (float) $p['cost_price'],
                    'vat_rate'    => (float) $p['vat_rate'],
                    'batch_no'    => (int) $p['track_batches'] === 1
                        ? (((string) post('batch_no') !== '') ? (string) post('batch_no')
                            : 'B' . date('ymd') . '-' . $p['sku']) : null,
                    'expiry_date' => date_or_null(post('expiry_date')),
                ]);
                grn_recalculate($id);
            });
            flash_ok($p['name'] . ' added to the delivery.');
            redirect('inventory/grn', ['id' => $id]);
        }

        if ($action === 'remove_line') {
            db_tx(function () use ($id): void {
                q('DELETE FROM grn_lines WHERE id = ? AND grn_id = ?', [post_int('line_id'), $id]);
                grn_recalculate($id);
            });
            flash_ok('Line removed.');
            redirect('inventory/grn', ['id' => $id]);
        }

        if ($action === 'post') {
            require_can('grn.post');
            grn_post($id, (int) $_SESSION['uid']);
            flash_ok('Posted to stock. The goods are sellable and the weighted-average cost has moved.');
            redirect('inventory/grn', ['id' => $id]);
        }

        if ($action === 'cancel') {
            db_tx(function () use ($id, $grn): void {
                db_update('grns', ['status' => 'cancelled'], 'id = :id', ['id' => $id]);
                audit_log('grn.cancel', 'grn', $id,
                    'Draft delivery ' . $grn['grn_no'] . ' cancelled before posting',
                    null, null, 'warning');
            });
            flash_ok('Draft delivery cancelled. No stock moved.');
            redirect('inventory/grns');
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect($id > 0 ? 'inventory/grn?id=' . $id : 'inventory/grn');
    }
}

/* ---- New receipt form ------------------------------------------ */
if ($id === 0) {
    $po = $poId > 0
        ? q1('SELECT po.*, s.name AS supplier_name FROM purchase_orders po
                JOIN suppliers s ON s.id = po.supplier_id
               WHERE po.id = ? AND po.store_id = ?', [$poId, $storeId])
        : null;

    $openOrders = qpairs(
        'SELECT po.id, CONCAT(po.po_no, " - ", s.name, " (", DATE_FORMAT(po.order_date, "%d %b"), ")")
           FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id
          WHERE po.store_id = ? AND po.status IN (\'approved\',\'partly_received\')
          ORDER BY po.order_date DESC', [$storeId]
    );
    $suppliers = qpairs('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name');

    layout_head([
        'title'    => 'Record a delivery',
        'subtitle' => $po !== null ? 'Against order ' . $po['po_no'] : 'Goods in at the back door',
    ]);
    ?>
    <?= card_open('What arrived?') ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <label class="field">
            <span class="field__label">Against which order?</span>
            <select name="po_id">
              <?= select_options($openOrders, $poId, true, 'No order - direct receipt') ?>
            </select>
            <span class="field__hint">
              Picking an order pre-fills the lines with what is still outstanding on it.
            </span>
          </label>
          <label class="field">
            <span class="field__label">Supplier (direct receipts only)</span>
            <select name="supplier_id"><?= select_options($suppliers, '', true, '-- choose --') ?></select>
          </label>
          <label class="field">
            <span class="field__label">Supplier invoice or delivery note</span>
            <input type="text" name="supplier_invoice_no" maxlength="40">
          </label>
          <label class="field">
            <span class="field__label">Date received</span>
            <input type="date" name="received_date" value="<?= e(date('Y-m-d')) ?>" required>
          </label>
        </div>
        <label class="field">
          <span class="field__label">Note</span>
          <input type="text" name="note" maxlength="255" placeholder="Anything unusual about the delivery">
        </label>
        <button class="btn btn--lg" type="submit">Start recording</button>
        <a class="btn btn--ghost" href="<?= e(base_url('inventory/grns')) ?>">Cancel</a>
      </form>
    <?= card_close() ?>

    <p class="small muted">
      A direct receipt is a delivery with no purchase order behind it. That happens, but every list
      shows it as "direct" &mdash; goods arriving unordered are worth a question, and the system
      should not quietly normalise them.
    </p>
    <?php
    layout_foot();
    exit;
}

/* ---- Existing receipt ------------------------------------------ */
$grn = q1(
    'SELECT g.*, s.name AS supplier_name, s.contact_name, po.po_no,
            u.full_name AS received_by_name, pb.full_name AS posted_by_name
       FROM grns g
       JOIN suppliers s ON s.id = g.supplier_id
       JOIN users u ON u.id = g.received_by
       LEFT JOIN users pb ON pb.id = g.posted_by
       LEFT JOIN purchase_orders po ON po.id = g.po_id
      WHERE g.id = ? AND g.store_id = ?',
    [$id, $storeId]
);
if (!$grn) {
    http_response_code(404);
    require APP_ROOT . '/404.php';
    exit;
}

$lines = qa(
    'SELECT gl.*, p.sku, p.name, p.unit_code, p.track_batches, p.shelf_life_days,
            pol.qty_ordered, pol.qty_received, pol.unit_cost AS agreed_cost,
            COALESCE(sl.avg_cost, p.cost_price) AS current_avg_cost
       FROM grn_lines gl
       JOIN products p ON p.id = gl.product_id
       LEFT JOIN purchase_order_lines pol ON pol.id = gl.po_line_id
       LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
      WHERE gl.grn_id = ? ORDER BY p.name',
    [$storeId, $id]
);
$isDraft  = $grn['status'] === 'draft';
$products = $isDraft
    ? qpairs('SELECT id, CONCAT(sku, " - ", name) FROM products WHERE is_active = 1 ORDER BY name')
    : [];

$movements = $grn['status'] === 'posted'
    ? qa('SELECT sm.*, p.name, b.batch_no, b.expiry_date
            FROM stock_movements sm
            JOIN products p ON p.id = sm.product_id
            LEFT JOIN stock_batches b ON b.id = sm.batch_id
           WHERE sm.ref_type = \'grn\' AND sm.ref_id = ? ORDER BY sm.id', [$id])
    : [];

$actions = '';
if ($isDraft && can('grn.post') && $lines) {
    $actions .= '<form method="post" class="inline-form" '
        . 'data-confirm="Post this delivery to stock? Quantities and the average cost will move, '
        . 'and a posted receipt cannot be edited.">'
        . csrf_field() . '<input type="hidden" name="action" value="post">'
        . '<button class="btn" type="submit">Post to stock</button></form>';
}
$actions .= '<button class="btn btn--ghost" data-print>Print</button>';

layout_head([
    'title'    => 'Delivery ' . $grn['grn_no'],
    'subtitle' => $grn['supplier_name'] . ' - ' . date_fmt((string) $grn['received_date']),
    'actions'  => $actions,
]);
?>

<?php if ($isDraft): ?>
  <div class="alert alert--warning">
    This delivery is <strong>not in stock yet</strong>. Nothing here is sellable and the costs have
    not moved. Check each line against the physical goods and the supplier's note, then post it.
  </div>
<?php elseif ($grn['status'] === 'posted'): ?>
  <div class="alert alert--success">
    Posted to stock by <?= e($grn['posted_by_name'] ?? 'unknown') ?> on
    <?= e(date_fmt((string) $grn['posted_at'], true)) ?>. A posted receipt cannot be edited &mdash;
    a correction is a separate adjustment, so the original record of what arrived survives.
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Delivery total', money((float) $grn['total']),
        'net ' . money((float) $grn['subtotal']) . ' + VAT ' . money((float) $grn['vat_total'])) ?>
  <?= tile('Lines', number_format(count($lines)),
        qty_fmt(array_sum(array_map(fn ($l) => (float) $l['qty'], $lines))) . ' units') ?>
  <?= tile('Order', (string) ($grn['po_no'] ?? 'Direct'),
        $grn['po_no'] !== null ? 'received against an order' : 'no order behind it',
        $grn['po_no'] !== null ? 'ok' : 'warn') ?>
  <?= tile('Status', ucfirst((string) $grn['status']),
        'received by ' . $grn['received_by_name'],
        $grn['status'] === 'posted' ? 'ok' : ($grn['status'] === 'cancelled' ? 'bad' : 'warn')) ?>
</div>

<?= card_open('Lines', $isDraft ? '<span class="tiny muted">Editable until posted</span>' : '') ?>
  <?php if (!$lines): ?>
    <?= empty_state('No lines on this delivery.', $isDraft ? 'Add what arrived below.' : '') ?>
  <?php elseif ($isDraft): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_lines">
      <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th>Product</th><th class="num">Ordered</th><th class="num">Already in</th>
          <th class="num">Arrived</th><th class="num">Unit cost</th><th class="num">Agreed</th>
          <th>Batch</th><th>Expires</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($lines as $l):
            $agreed   = $l['agreed_cost'] !== null ? (float) $l['agreed_cost'] : null;
            $costHigh = $agreed !== null && $agreed > 0 && (float) $l['unit_cost'] > $agreed * 1.05;
            $outstanding = $l['qty_ordered'] !== null
                ? (float) $l['qty_ordered'] - (float) $l['qty_received'] : null;
            $over = $outstanding !== null && (float) $l['qty'] > $outstanding + 0.0005; ?>
          <tr class="<?= $costHigh || $over ? 'row-warn' : '' ?>">
            <td>
              <?= e($l['name']) ?>
              <br><span class="tiny muted mono"><?= e($l['sku']) ?></span>
            </td>
            <td class="num"><?= e($l['qty_ordered'] !== null ? qty_fmt((float) $l['qty_ordered']) : '-') ?></td>
            <td class="num"><?= e($l['qty_received'] !== null ? qty_fmt((float) $l['qty_received']) : '-') ?></td>
            <td class="num">
              <input type="text" name="line[<?= (int) $l['id'] ?>][qty]" class="num-in"
                     style="max-width:84px" inputmode="decimal"
                     value="<?= e(qty_fmt((float) $l['qty'])) ?>">
              <span class="tiny muted"><?= e($l['unit_code']) ?></span>
              <?php if ($over): ?><br><?= badge('Over order', 'warn') ?><?php endif; ?>
            </td>
            <td class="num">
              <input type="text" name="line[<?= (int) $l['id'] ?>][unit_cost]" class="num-in"
                     style="max-width:96px" inputmode="decimal"
                     value="<?= e(number_format((float) $l['unit_cost'], 4, '.', '')) ?>">
            </td>
            <td class="num">
              <?= e($agreed !== null ? money($agreed) : '-') ?>
              <?php if ($costHigh): ?><br><?= badge('Above agreed', 'warn') ?><?php endif; ?>
            </td>
            <td>
              <?php if ((int) $l['track_batches'] === 1): ?>
                <input type="text" name="line[<?= (int) $l['id'] ?>][batch_no]"
                       style="max-width:140px" value="<?= e((string) ($l['batch_no'] ?? '')) ?>">
              <?php else: ?>
                <span class="tiny muted">not tracked</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ((int) $l['track_batches'] === 1): ?>
                <input type="date" name="line[<?= (int) $l['id'] ?>][expiry_date]"
                       style="max-width:150px" value="<?= e((string) ($l['expiry_date'] ?? '')) ?>">
              <?php else: ?>
                <span class="tiny muted">-</span>
              <?php endif; ?>
            </td>
            <td class="right">
              <button class="btn btn--sm btn--ghost" type="submit"
                      name="line[<?= (int) $l['id'] ?>][qty]" value="0"
                      title="Nothing of this line arrived">&times;</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div class="btn-row mt1">
        <button class="btn" type="submit">Save lines</button>
      </div>
      <p class="small muted mt1">
        Set a quantity to zero to drop a line that did not arrive. Lines tinted amber are either
        above the quantity ordered or above the agreed cost &mdash; both are allowed, and both
        raise an audit exception when the delivery is posted, so somebody can ask about them.
      </p>
    </form>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Product</th><th class="num">Quantity</th><th class="num">Unit cost</th>
        <th class="num">Line total</th><th>Batch</th><th>Expires</th>
      </tr></thead>
      <tbody>
      <?php foreach ($lines as $l): ?>
        <tr>
          <td><?= e($l['name']) ?><br><span class="tiny muted mono"><?= e($l['sku']) ?></span></td>
          <td class="num"><?= e(qty_fmt((float) $l['qty'])) ?>
            <span class="tiny muted"><?= e($l['unit_code']) ?></span></td>
          <?= num_cell($l['unit_cost']) ?>
          <?= num_cell($l['line_total']) ?>
          <td class="mono tiny"><?= e($l['batch_no'] ?? '-') ?></td>
          <td class="tiny nowrap">
            <?= e($l['expiry_date'] !== null ? date_fmt((string) $l['expiry_date']) : '-') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="3">Net</td><td class="num"><?= e(money((float) $grn['subtotal'])) ?></td><td colspan="2"></td></tr>
        <tr><td colspan="3">VAT</td><td class="num"><?= e(money((float) $grn['vat_total'])) ?></td><td colspan="2"></td></tr>
        <tr><td colspan="3"><strong>Total</strong></td>
            <td class="num"><strong><?= e(money((float) $grn['total'])) ?></strong></td><td colspan="2"></td></tr>
      </tfoot>
    </table>
    </div>
  <?php endif; ?>

  <?php if ($isDraft): ?>
    <form method="post" class="mt1 no-print">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_line">
      <h3>Add something not on the order</h3>
      <div class="form-grid">
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
        <label class="field">
          <span class="field__label">Batch (if tracked)</span>
          <input type="text" name="batch_no" maxlength="40">
        </label>
        <label class="field">
          <span class="field__label">Expires (if tracked)</span>
          <input type="date" name="expiry_date">
        </label>
      </div>
      <button class="btn" type="submit">Add line</button>
    </form>
  <?php endif; ?>
<?= card_close() ?>

<?php if ($movements): ?>
<?= card_open('Stock this delivery created') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Product</th><th class="num">Quantity</th><th class="num">Unit cost</th>
      <th class="num">Balance after</th><th>Batch</th><th>Expires</th>
    </tr></thead>
    <tbody>
    <?php foreach ($movements as $m): ?>
      <tr>
        <td><?= e($m['name']) ?></td>
        <?= num_cell($m['qty_delta'], false, true) ?>
        <?= num_cell($m['unit_cost']) ?>
        <td class="num"><?= e(qty_fmt((float) $m['balance_after'])) ?></td>
        <td class="mono tiny"><?= e($m['batch_no'] ?? '-') ?></td>
        <td class="tiny nowrap">
          <?= e($m['expiry_date'] !== null ? date_fmt((string) $m['expiry_date']) : '-') ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?php if ($isDraft): ?>
  <?= card_open('Cancel this draft') ?>
    <form method="post" data-confirm="Cancel this draft delivery? No stock has moved, so nothing is reversed.">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel">
      <button class="btn btn--danger" type="submit">Cancel draft delivery</button>
    </form>
  <?= card_close() ?>
<?php endif; ?>

<?php layout_foot(); ?>
