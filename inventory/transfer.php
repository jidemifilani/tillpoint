<?php
/**
 * One transfer: build it, dispatch it, count it in.
 *
 * The receiving form asks for a counted quantity per line rather than offering
 * "accept all". Shortages between branches are the point of having a transfer
 * document at all, and a form that defaults to agreement would never find one.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('inventory.view');

$storeId = current_store_id();
$id      = get_int('id');

if ($id === 0) {
    // Creating happens on the list page, which has the store picker.
    redirect('inventory/transfers');
}

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');
        $t      = q1('SELECT * FROM stock_transfers WHERE id = ?', [$id]);
        if (!$t) {
            throw new StockError('That transfer no longer exists.');
        }
        $isOutbound = (int) $t['from_store_id'] === $storeId;
        $isInbound  = (int) $t['to_store_id'] === $storeId;
        if (!$isOutbound && !$isInbound) {
            deny_403('That transfer does not involve this store.');
        }

        if ($action === 'add_line') {
            require_can('transfer.create');
            if ($t['status'] !== 'draft') {
                throw new StockError('Lines can only be added while the transfer is a draft.');
            }
            if (!$isOutbound) {
                throw new StockError('Only the sending store can add lines.');
            }
            $productId = post_int('product_id');
            $qty       = post_num('qty');
            if ($productId === 0 || $qty <= 0) {
                throw new StockError('Choose a product and a quantity.');
            }
            $available = stock_available($productId, (int) $t['from_store_id']);
            if ($qty > $available + 0.0005) {
                throw new StockError(sprintf('Only %s available to send.', qty_fmt($available)));
            }
            db_tx(function () use ($id, $productId, $qty): void {
                $existing = q1('SELECT * FROM stock_transfer_lines WHERE transfer_id = ? AND product_id = ?',
                    [$id, $productId]);
                if ($existing) {
                    q('UPDATE stock_transfer_lines SET qty_sent = qty_sent + ? WHERE id = ?',
                      [$qty, (int) $existing['id']]);
                } else {
                    db_insert('stock_transfer_lines', [
                        'transfer_id' => $id,
                        'product_id'  => $productId,
                        'qty_sent'    => $qty,
                    ]);
                }
            });
            flash_ok('Line added.');
            redirect('inventory/transfer', ['id' => $id]);
        }

        if ($action === 'remove_line') {
            require_can('transfer.create');
            if ($t['status'] !== 'draft') {
                throw new StockError('Lines can only be removed while the transfer is a draft.');
            }
            q('DELETE FROM stock_transfer_lines WHERE id = ? AND transfer_id = ?',
              [post_int('line_id'), $id]);
            flash_ok('Line removed.');
            redirect('inventory/transfer', ['id' => $id]);
        }

        if ($action === 'dispatch') {
            require_can('transfer.dispatch');
            transfer_dispatch($id, (int) $_SESSION['uid']);
            flash_ok('Dispatched. The stock has left this store.');
            redirect('inventory/transfer', ['id' => $id]);
        }

        if ($action === 'receive') {
            require_can('transfer.receive');
            if (!$isInbound) {
                throw new StockError('Only the receiving store can count this transfer in.');
            }
            $qtys = [];
            foreach ((array) ($_POST['received'] ?? []) as $lineId => $qty) {
                $qtys[(int) $lineId] = num($qty, 0.0);
            }
            transfer_receive($id, (int) $_SESSION['uid'], $qtys);
            flash_ok('Transfer received. Any shortfall has been recorded and raised for review.');
            redirect('inventory/transfer', ['id' => $id]);
        }

        if ($action === 'cancel') {
            require_can('transfer.create');
            if ($t['status'] !== 'draft') {
                throw new StockError('Only a draft transfer can be cancelled. '
                    . 'A dispatched one has to be received, even if short.');
            }
            db_update('stock_transfers', ['status' => 'cancelled'], 'id = :id', ['id' => $id]);
            audit_log('transfer.cancel', 'transfer', $id,
                'Draft transfer ' . $t['transfer_no'] . ' cancelled', null, null, 'notice');
            flash_ok('Draft transfer cancelled. No stock moved.');
            redirect('inventory/transfers');
        }
    } catch (SodViolation $e) {
        flash_err($e->getMessage());
        redirect('inventory/transfer', ['id' => $id]);
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('inventory/transfer', ['id' => $id]);
    }
}

$t = q1(
    'SELECT t.*, fs.name AS from_name, fs.code AS from_code,
            ts.name AS to_name, ts.code AS to_code,
            cb.full_name AS created_by_name, db_.full_name AS dispatched_by_name,
            rb.full_name AS received_by_name
       FROM stock_transfers t
       JOIN stores fs ON fs.id = t.from_store_id
       JOIN stores ts ON ts.id = t.to_store_id
       JOIN users cb ON cb.id = t.created_by
       LEFT JOIN users db_ ON db_.id = t.dispatched_by
       LEFT JOIN users rb ON rb.id = t.received_by
      WHERE t.id = ?',
    [$id]
);
if (!$t) {
    http_response_code(404);
    require APP_ROOT . '/404.php';
    exit;
}

$isOutbound = (int) $t['from_store_id'] === $storeId;
$isInbound  = (int) $t['to_store_id'] === $storeId;
if (!$isOutbound && !$isInbound) {
    deny_403('That transfer does not involve the store you are working in.');
}

$lines = qa(
    'SELECT tl.*, p.sku, p.name, p.unit_code,
            COALESCE(sl.qty_on_hand,0) AS sender_on_hand,
            COALESCE(sl.qty_reserved,0) AS sender_reserved
       FROM stock_transfer_lines tl
       JOIN products p ON p.id = tl.product_id
       LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
      WHERE tl.transfer_id = ? ORDER BY p.name',
    [(int) $t['from_store_id'], $id]
);

$isDraft    = $t['status'] === 'draft';
$dispatched = $t['status'] === 'dispatched';
$sentTotal  = array_sum(array_map(fn ($l) => (float) $l['qty_sent'], $lines));
$recvTotal  = array_sum(array_map(fn ($l) => (float) $l['qty_received'], $lines));
$value      = array_sum(array_map(fn ($l) => (float) $l['qty_sent'] * (float) $l['unit_cost'], $lines));
$dispatchedByMe = $t['dispatched_by'] !== null && (int) $t['dispatched_by'] === (int) $_SESSION['uid'];

$products = $isDraft && $isOutbound
    ? qpairs('SELECT p.id, CONCAT(p.sku, " - ", p.name, " (", FORMAT(sl.qty_on_hand - sl.qty_reserved, 0), " avail)")
                FROM products p
                JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
               WHERE p.is_active = 1 AND sl.qty_on_hand - sl.qty_reserved > 0
               ORDER BY p.name', [(int) $t['from_store_id']])
    : [];

$actions = '';
if ($isDraft && $isOutbound && can('transfer.dispatch') && $lines) {
    $actions .= '<form method="post" class="inline-form" '
        . 'data-confirm="Dispatch this transfer? The stock leaves this store immediately.">'
        . csrf_field() . '<input type="hidden" name="action" value="dispatch">'
        . '<button class="btn" type="submit">Dispatch</button></form>';
}
$actions .= '<button class="btn btn--ghost" data-print>Print</button>';

layout_head([
    'title'    => 'Transfer ' . $t['transfer_no'],
    'subtitle' => $t['from_name'] . ' to ' . $t['to_name'],
    'actions'  => $actions,
]);
?>

<?php if ($dispatched): ?>
  <div class="alert alert--warning">
    In transit since <?= e(date_fmt((string) $t['dispatched_at'], true)) ?>, dispatched by
    <?= e($t['dispatched_by_name'] ?? 'unknown') ?>. This stock has left
    <?= e($t['from_name']) ?> and has not yet arrived at <?= e($t['to_name']) ?>, so it is in
    neither store's sellable position.
  </div>
<?php elseif ($t['status'] === 'received' && $sentTotal - $recvTotal > 0.0005): ?>
  <div class="alert alert--error">
    Received <?= e(qty_fmt($sentTotal - $recvTotal)) ?> units short of what was dispatched.
    A shortage exception has been raised for review.
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Units sent', qty_fmt($sentTotal), count($lines) . ' line(s)') ?>
  <?= tile('Units received', $t['status'] === 'received' ? qty_fmt($recvTotal) : 'pending',
        $t['status'] === 'received' && $sentTotal - $recvTotal > 0.0005
          ? qty_fmt($sentTotal - $recvTotal) . ' short' : '',
        $t['status'] === 'received' && $sentTotal - $recvTotal > 0.0005 ? 'bad' : 'ok') ?>
  <?= tile('Value at cost', money($value), 'at the sending store cost') ?>
  <?= tile('Status', ucfirst((string) $t['status']),
        'raised by ' . $t['created_by_name'],
        $t['status'] === 'received' ? 'ok' : ($t['status'] === 'cancelled' ? 'bad' : 'warn')) ?>
</div>

<?= card_open('Trail') ?>
  <dl class="kv">
    <dt>From</dt><dd><?= e($t['from_name']) ?> (<?= e($t['from_code']) ?>)</dd>
    <dt>To</dt><dd><?= e($t['to_name']) ?> (<?= e($t['to_code']) ?>)</dd>
    <dt>Raised</dt><dd><?= e($t['created_by_name']) ?>,
      <?= e(date_fmt((string) $t['created_at'], true)) ?></dd>
    <?php if ($t['dispatched_at'] !== null): ?>
      <dt>Dispatched</dt><dd><?= e($t['dispatched_by_name'] ?? '-') ?>,
        <?= e(date_fmt((string) $t['dispatched_at'], true)) ?></dd>
    <?php endif; ?>
    <?php if ($t['received_at'] !== null): ?>
      <dt>Received</dt><dd><?= e($t['received_by_name'] ?? '-') ?>,
        <?= e(date_fmt((string) $t['received_at'], true)) ?></dd>
    <?php endif; ?>
    <?php if ($t['note'] !== null): ?>
      <dt>Note</dt><dd><?= e((string) $t['note']) ?></dd>
    <?php endif; ?>
  </dl>
<?= card_close() ?>

<?php if ($dispatched && $isInbound && can('transfer.receive')): ?>
  <?= card_open('Count it in') ?>
    <?php if ($dispatchedByMe): ?>
      <div class="alert alert--error mb0">
        You dispatched this transfer, so you cannot receive it. One person controlling both ends of
        a movement between branches can send nine and book ten, and nothing in the figures would
        show it. Someone else at <?= e($t['to_name']) ?> must count it in.
      </div>
    <?php else: ?>
      <p class="small muted">
        Count what actually arrived, line by line. Do not copy the dispatched figures &mdash; the
        whole purpose of this step is to catch the difference.
      </p>
      <form method="post" data-confirm="Post this receipt? Any shortfall will be recorded against this transfer.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="receive">
        <div class="table-wrap">
        <table class="data">
          <thead><tr>
            <th>Product</th><th class="num">Dispatched</th><th class="num">Counted in</th>
            <th class="num">Unit cost</th>
          </tr></thead>
          <tbody>
          <?php foreach ($lines as $l): ?>
            <tr>
              <td><?= e($l['name']) ?><br><span class="tiny muted mono"><?= e($l['sku']) ?></span></td>
              <td class="num"><?= e(qty_fmt((float) $l['qty_sent'])) ?>
                <span class="tiny muted"><?= e($l['unit_code']) ?></span></td>
              <td class="num">
                <input type="text" name="received[<?= (int) $l['id'] ?>]" class="num-in"
                       style="max-width:96px" inputmode="decimal"
                       value="<?= e(qty_fmt((float) $l['qty_sent'])) ?>">
              </td>
              <?= num_cell($l['unit_cost']) ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <button class="btn btn--lg mt1" type="submit">Post receipt</button>
      </form>
    <?php endif; ?>
  <?= card_close() ?>
<?php endif; ?>

<?= card_open('Lines') ?>
  <?php if (!$lines): ?>
    <?= empty_state('No lines on this transfer.',
          $isDraft && $isOutbound ? 'Add the first one below.' : '') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>SKU</th><th>Product</th>
        <?php if ($isDraft): ?><th class="num">Available to send</th><?php endif; ?>
        <th class="num">Sent</th><th class="num">Received</th><th class="num">Difference</th>
        <th class="num">Unit cost</th><th class="num">Value</th>
        <?= $isDraft && $isOutbound && can('transfer.create') ? '<th></th>' : '' ?>
      </tr></thead>
      <tbody>
      <?php foreach ($lines as $l):
          $sent = (float) $l['qty_sent'];
          $recv = (float) $l['qty_received'];
          $diff = $t['status'] === 'received' ? $recv - $sent : 0.0; ?>
        <tr class="<?= $diff < -0.0005 ? 'row-bad' : '' ?>">
          <td class="mono tiny"><?= e($l['sku']) ?></td>
          <td><?= e($l['name']) ?></td>
          <?php if ($isDraft): ?>
            <td class="num">
              <?= e(qty_fmt((float) $l['sender_on_hand'] - (float) $l['sender_reserved'])) ?>
            </td>
          <?php endif; ?>
          <td class="num"><?= e(qty_fmt($sent)) ?>
            <span class="tiny muted"><?= e($l['unit_code']) ?></span></td>
          <td class="num"><?= e($t['status'] === 'received' ? qty_fmt($recv) : '-') ?></td>
          <?php if ($t['status'] === 'received'): ?>
            <?= num_cell($diff, false, true) ?>
          <?php else: ?>
            <td class="num muted">-</td>
          <?php endif; ?>
          <?= num_cell($l['unit_cost']) ?>
          <?= num_cell($sent * (float) $l['unit_cost']) ?>
          <?php if ($isDraft && $isOutbound && can('transfer.create')): ?>
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
    </table>
    </div>
  <?php endif; ?>

  <?php if ($isDraft && $isOutbound && can('transfer.create')): ?>
    <form method="post" class="mt1 no-print">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_line">
      <div class="form-grid">
        <label class="field">
          <span class="field__label">Product</span>
          <select name="product_id" required><?= select_options($products, '', true, '-- choose --') ?></select>
        </label>
        <label class="field">
          <span class="field__label">Quantity to send</span>
          <input type="text" name="qty" class="num-in" required inputmode="decimal">
        </label>
      </div>
      <button class="btn" type="submit">Add line</button>
    </form>
  <?php endif; ?>
<?= card_close() ?>

<?php if ($isDraft && $isOutbound && can('transfer.create')): ?>
  <?= card_open('Cancel this draft') ?>
    <form method="post" data-confirm="Cancel this draft transfer?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel">
      <button class="btn btn--danger" type="submit">Cancel draft transfer</button>
    </form>
  <?= card_close() ?>
<?php endif; ?>

<?php layout_foot(); ?>
