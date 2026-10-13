<?php
/**
 * Stock transfers between stores.
 *
 * Dispatch and receipt are separate postings, so stock in a van belongs to
 * neither store's sellable position. The gap between the two is the in-transit
 * figure, and a transfer that stays dispatched is stock nobody is accountable
 * for - which is exactly what this list is for.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('inventory.view');

$storeId = current_store_id();

if (is_post()) {
    csrf_require();
    try {
        $action     = (string) post('action');
        $transferId = post_int('transfer_id');

        if ($action === 'create') {
            require_can('transfer.create');
            $toStore = post_int('to_store_id');
            if ($toStore === 0 || $toStore === $storeId) {
                throw new StockError('Choose a different store to send the stock to.');
            }
            $newId = db_tx(fn (): int => db_insert('stock_transfers', [
                'transfer_no'   => next_number('transfer', $storeId),
                'from_store_id' => $storeId,
                'to_store_id'   => $toStore,
                'status'        => 'draft',
                'note'          => (string) post('note') !== '' ? (string) post('note') : null,
                'created_by'    => (int) $_SESSION['uid'],
            ]));
            audit_log('transfer.draft', 'transfer', $newId, 'Draft transfer started', null, null, 'info');
            flash_ok('Draft transfer started. Add the lines.');
            redirect('inventory/transfer', ['id' => $newId]);
        }

        if ($action === 'dispatch') {
            require_can('transfer.dispatch');
            transfer_dispatch($transferId, (int) $_SESSION['uid']);
            flash_ok('Transfer dispatched. The stock has left this store and is in transit.');
        }

        if ($action === 'receive_full') {
            require_can('transfer.receive');
            // Receiving in full is the common case and deserves one click. The
            // short-receipt path is on the detail page, where each line can be
            // counted - a "receive all" button that silently accepted shortages
            // would destroy the control.
            transfer_receive($transferId, (int) $_SESSION['uid'], []);
            flash_ok('Transfer received in full.');
        }
    } catch (SodViolation $e) {
        flash_err($e->getMessage());
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect('inventory/transfers');
}

$direction = one_of((string) get('direction'), ['all', 'out', 'in'], 'all');
$status    = one_of((string) get('status'), ['open', 'all', 'draft', 'dispatched', 'received', 'cancelled'], 'open');

$where  = [];
$params = [];
switch ($direction) {
    case 'out': $where[] = 't.from_store_id = ?'; $params[] = $storeId; break;
    case 'in':  $where[] = 't.to_store_id = ?';   $params[] = $storeId; break;
    default:
        $where[] = '(t.from_store_id = ? OR t.to_store_id = ?)';
        array_push($params, $storeId, $storeId);
}
if ($status === 'open') {
    $where[] = "t.status IN ('draft','dispatched')";
} elseif ($status !== 'all') {
    $where[]  = 't.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);

$rows = qa(
    "SELECT t.*, fs.name AS from_name, fs.code AS from_code,
            ts.name AS to_name, ts.code AS to_code,
            cb.full_name AS created_by_name, db_.full_name AS dispatched_by_name,
            rb.full_name AS received_by_name,
            (SELECT COUNT(*) FROM stock_transfer_lines tl WHERE tl.transfer_id = t.id) AS line_count,
            (SELECT COALESCE(SUM(tl.qty_sent),0) FROM stock_transfer_lines tl WHERE tl.transfer_id = t.id) AS qty_sent,
            (SELECT COALESCE(SUM(tl.qty_received),0) FROM stock_transfer_lines tl WHERE tl.transfer_id = t.id) AS qty_received,
            (SELECT COALESCE(SUM(tl.qty_sent * tl.unit_cost),0) FROM stock_transfer_lines tl WHERE tl.transfer_id = t.id) AS value,
            DATEDIFF(CURDATE(), DATE(t.dispatched_at)) AS days_in_transit
       FROM stock_transfers t
       JOIN stores fs ON fs.id = t.from_store_id
       JOIN stores ts ON ts.id = t.to_store_id
       JOIN users cb ON cb.id = t.created_by
       LEFT JOIN users db_ ON db_.id = t.dispatched_by
       LEFT JOIN users rb ON rb.id = t.received_by
      WHERE $whereSql
      ORDER BY FIELD(t.status,'dispatched','draft','received','cancelled'), t.created_at DESC
      LIMIT 200",
    $params
);

$inTransit = array_filter($rows, fn ($r) => $r['status'] === 'dispatched');
$transitValue = array_sum(array_map(fn ($r) => (float) $r['value'], $inTransit));
$stale = array_filter($inTransit, fn ($r) => (int) ($r['days_in_transit'] ?? 0) >= 2);

$stores = qpairs('SELECT id, name FROM stores WHERE is_active = 1 AND id <> ? ORDER BY name', [$storeId]);

layout_head([
    'title'    => 'Stock transfers',
    'subtitle' => count($inTransit) . ' in transit, ' . money($transitValue) . ' at cost',
    'actions'  => can('transfer.create')
        ? '<a class="btn" href="' . e(base_url('inventory/transfer')) . '">New transfer</a>' : '',
]);
?>

<?php if ($stale): ?>
  <div class="alert alert--warning">
    <strong><?= count($stale) ?></strong> transfer(s) have been in transit for two days or more.
    Stock in transit belongs to neither store's sellable position, so until it is received it is
    stock nobody is accountable for.
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('In transit', number_format(count($inTransit)), 'dispatched, not yet received',
        count($inTransit) > 0 ? 'warn' : 'ok') ?>
  <?= tile('Value in transit', money($transitValue), 'at dispatching store cost') ?>
  <?= tile('To receive here', number_format(count(array_filter($rows,
        fn ($r) => $r['status'] === 'dispatched' && (int) $r['to_store_id'] === $storeId))),
        'waiting at this store', 'info') ?>
  <?= tile('Transfers shown', number_format(count($rows)), 'matching the filter', 'mute') ?>
</div>

<?php if (can('transfer.create')): ?>
  <?= card_open('Start a transfer out of this store') ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-grid">
        <label class="field">
          <span class="field__label">Send to</span>
          <select name="to_store_id" required><?= select_options($stores, '', true, '-- choose --') ?></select>
        </label>
        <label class="field">
          <span class="field__label">Note</span>
          <input type="text" name="note" maxlength="255" placeholder="Why the stock is moving">
        </label>
      </div>
      <button class="btn" type="submit">Start draft transfer</button>
    </form>
  <?= card_close() ?>
<?php endif; ?>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">Direction</span>
    <select name="direction">
      <?= select_options(['all' => 'In and out', 'out' => 'Out of this store',
                          'in' => 'Into this store'], $direction) ?>
    </select>
  </label>
  <label class="field">
    <span class="field__label">Status</span>
    <select name="status">
      <?= select_options(['open' => 'Open', 'all' => 'All', 'draft' => 'Draft',
                          'dispatched' => 'In transit', 'received' => 'Received',
                          'cancelled' => 'Cancelled'], $status) ?>
    </select>
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('inventory/transfers')) ?>">Reset</a>
</form>

<?= card_open('Transfers') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No transfers match.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Transfer</th><th>From</th><th>To</th><th class="num">Lines</th>
        <th class="num">Sent</th><th class="num">Received</th><th class="num">Value</th>
        <th>Dispatched</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $t):
          $sent     = (float) $t['qty_sent'];
          $received = (float) $t['qty_received'];
          $short    = $t['status'] === 'received' && $sent - $received > 0.0005;
          $isInbound  = (int) $t['to_store_id'] === $storeId;
          $isOutbound = (int) $t['from_store_id'] === $storeId;
          $daysOut    = $t['days_in_transit'] !== null ? (int) $t['days_in_transit'] : null; ?>
        <tr class="<?= $short ? 'row-bad' : ($t['status'] === 'dispatched' ? 'row-warn' : '') ?>">
          <td class="mono tiny">
            <a href="<?= e(base_url('inventory/transfer', ['id' => (int) $t['id']])) ?>">
              <?= e($t['transfer_no']) ?></a>
          </td>
          <td class="tiny"><?= e($t['from_name']) ?>
            <?php if ($isOutbound): ?><?= badge('here', 'info') ?><?php endif; ?></td>
          <td class="tiny"><?= e($t['to_name']) ?>
            <?php if ($isInbound): ?><?= badge('here', 'info') ?><?php endif; ?></td>
          <td class="num"><?= (int) $t['line_count'] ?></td>
          <td class="num"><?= e(qty_fmt($sent)) ?></td>
          <td class="num <?= $short ? 'neg' : '' ?>">
            <?= e($t['status'] === 'received' ? qty_fmt($received) : '-') ?>
            <?php if ($short): ?><br><?= badge(qty_fmt($sent - $received) . ' short', 'bad') ?><?php endif; ?>
          </td>
          <?= num_cell($t['value']) ?>
          <td class="tiny nowrap">
            <?= e($t['dispatched_at'] !== null ? date_fmt((string) $t['dispatched_at']) : '-') ?>
            <?php if ($t['status'] === 'dispatched' && $daysOut !== null && $daysOut >= 2): ?>
              <br><?= badge($daysOut . 'd in transit', 'warn') ?>
            <?php endif; ?>
          </td>
          <td><?= status_badge((string) $t['status']) ?></td>
          <td class="right nowrap">
            <?php if ($t['status'] === 'draft' && $isOutbound && can('transfer.dispatch')): ?>
              <form method="post" class="inline-form"
                    data-confirm="Dispatch this transfer? The stock leaves this store now.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="dispatch">
                <input type="hidden" name="transfer_id" value="<?= (int) $t['id'] ?>">
                <button class="btn btn--sm" type="submit">Dispatch</button>
              </form>
            <?php elseif ($t['status'] === 'dispatched' && $isInbound && can('transfer.receive')): ?>
              <?php if ((int) $t['dispatched_by'] === (int) $_SESSION['uid']): ?>
                <span class="tiny bad-text" title="Segregation of duties">You dispatched it</span>
              <?php else: ?>
                <a class="btn btn--sm btn--ghost"
                   href="<?= e(base_url('inventory/transfer', ['id' => (int) $t['id']])) ?>">Count in</a>
                <form method="post" class="inline-form"
                      data-confirm="Receive this transfer in full, with no shortages?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="receive_full">
                  <input type="hidden" name="transfer_id" value="<?= (int) $t['id'] ?>">
                  <button class="btn btn--sm" type="submit">Receive all</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<p class="small muted">
  Whoever dispatched a transfer cannot receive it. One person controlling both ends of a movement
  between branches can send nine and book ten, and nothing in the figures would show it.
</p>

<?php layout_foot(); ?>
