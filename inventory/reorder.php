<?php
/**
 * Replenishment.
 *
 * Suggests what to order and how much, from the gap to the reorder level plus
 * the demand the last 30 days of actual sales imply over the supplier's lead
 * time. A static reorder quantity is how a shop ends up with a pallet of
 * something that stopped selling, so the rate is measured rather than assumed.
 *
 * Ticked lines become draft purchase orders, one per supplier - a single order
 * spanning three suppliers is not an order anyone can send.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('inventory.view');

$storeId = current_store_id();

if (is_post()) {
    csrf_require();
    require_can('po.create');
    try {
        $wanted = [];
        foreach ((array) ($_POST['order_qty'] ?? []) as $pid => $qty) {
            $q = num($qty, 0.0);
            if ($q > 0) {
                $wanted[(int) $pid] = $q;
            }
        }
        if (!$wanted) {
            throw new RuntimeException('Enter a quantity against at least one line.');
        }

        $created = db_tx(function () use ($wanted, $storeId): array {
            // Group by preferred supplier first: an order has to be sendable.
            $bySupplier = [];
            foreach ($wanted as $pid => $qty) {
                $p = q1('SELECT id, supplier_id, cost_price, vat_rate, name FROM products WHERE id = ?', [$pid]);
                if (!$p) {
                    continue;
                }
                if ($p['supplier_id'] === null) {
                    throw new RuntimeException($p['name'] . ' has no preferred supplier, so it cannot be ordered. '
                        . 'Set one on the product first.');
                }
                $bySupplier[(int) $p['supplier_id']][] = ['product' => $p, 'qty' => $qty];
            }

            $orders = [];
            foreach ($bySupplier as $supplierId => $items) {
                $lead = (int) qv('SELECT lead_time_days FROM suppliers WHERE id = ?', [$supplierId],
                    (float) DEFAULT_REORDER_LEAD_DAYS);
                $poId = db_insert('purchase_orders', [
                    'po_no'         => next_number('po', $storeId),
                    'store_id'      => $storeId,
                    'supplier_id'   => $supplierId,
                    'status'        => 'draft',
                    'order_date'    => date('Y-m-d'),
                    'expected_date' => date('Y-m-d', strtotime("+$lead days")),
                    'created_by'    => (int) $_SESSION['uid'],
                    'note'          => 'Raised from replenishment suggestions',
                ]);
                foreach ($items as $it) {
                    db_insert('purchase_order_lines', [
                        'po_id'       => $poId,
                        'product_id'  => (int) $it['product']['id'],
                        'qty_ordered' => $it['qty'],
                        'unit_cost'   => (float) $it['product']['cost_price'],
                        'vat_rate'    => (float) $it['product']['vat_rate'],
                    ]);
                }
                po_recalculate($poId);
                $orders[] = (string) qv('SELECT po_no FROM purchase_orders WHERE id = ?', [$poId], '');

                audit_log('po.create', 'purchase_order', $poId,
                    sprintf('Draft order raised from replenishment: %d line(s) for %s',
                        count($items),
                        (string) qv('SELECT name FROM suppliers WHERE id = ?', [$supplierId], '')),
                    null, null, 'notice');
            }
            return $orders;
        });

        flash_ok(sprintf('%d draft order(s) created: %s. Review and submit them for approval.',
            count($created), implode(', ', $created)));
        redirect('inventory/purchase-orders');
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('inventory/reorder');
    }
}

$lookback    = max(7, min(90, get_int('lookback', 30)));
$suggestions = reorder_suggestions($storeId, $lookback);
$supplierId  = get_int('supplier_id');

if ($supplierId > 0) {
    $suggestions = array_values(array_filter($suggestions,
        fn ($r) => (int) ($r['supplier_id'] ?? 0) === $supplierId));
}

$estimatedCost = 0.0;
$noSupplier    = 0;
foreach ($suggestions as &$s) {
    $s['suggested'] = reorder_qty_for($s);
    $estimatedCost += $s['suggested'] * (float) $s['cost_price'];
    if ($s['supplier_id'] === null) {
        $noSupplier++;
    }
}
unset($s);

$suppliers = qpairs('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name');

layout_head([
    'title'    => 'Replenishment',
    'subtitle' => count($suggestions) . ' line(s) at or below reorder level',
]);
?>

<div class="tiles">
  <?= tile('Lines to order', number_format(count($suggestions)), 'at or below reorder level',
        count($suggestions) > 0 ? 'warn' : 'ok') ?>
  <?= tile('Estimated cost', money_short($estimatedCost), 'at current list cost') ?>
  <?= tile('Demand measured over', $lookback . ' days', 'of actual sales', 'info') ?>
  <?= tile('No supplier set', number_format($noSupplier), 'cannot be ordered',
        $noSupplier > 0 ? 'bad' : 'mute') ?>
</div>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">Supplier</span>
    <select name="supplier_id"><?= select_options($suppliers, $supplierId, true, 'All suppliers') ?></select>
  </label>
  <label class="field">
    <span class="field__label">Measure demand over</span>
    <select name="lookback">
      <?= select_options([7 => '7 days', 14 => '14 days', 30 => '30 days', 60 => '60 days', 90 => '90 days'], $lookback) ?>
    </select>
  </label>
  <button class="btn" type="submit">Apply</button>
</form>

<?php if (!$suggestions): ?>
  <?= card_open('Replenishment') ?>
    <?= empty_state('Nothing needs ordering.',
          'Every line with a reorder level set is above it, counting stock already on order.') ?>
  <?= card_close() ?>
<?php else: ?>
  <form method="post">
    <?= csrf_field() ?>
    <?= card_open('Suggested order',
          can('po.create')
            ? '<button class="btn" type="submit">Create draft orders</button>'
            : '<span class="tiny muted">You cannot raise orders</span>') ?>
      <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th>Product</th><th>Supplier</th>
          <th class="num">On hand</th><th class="num">Reserved</th><th class="num">On order</th>
          <th class="num">Reorder at</th>
          <th class="num">Sold / <?= $lookback ?>d</th><th class="num">Per day</th>
          <th class="num">Lead days</th><th class="num">Cover</th>
          <th class="num">Suggested</th><th class="num">Cost</th>
        </tr></thead>
        <tbody>
        <?php foreach ($suggestions as $r):
            $onHand    = (float) $r['qty_on_hand'];
            $daily     = (float) $r['daily_rate'];
            $available = $onHand - (float) $r['qty_reserved'];
            // Days of cover left at the measured rate. The number that tells you
            // whether this is urgent or merely due.
            $cover     = $daily > 0.0001 ? $available / $daily : null;
            $suggested = (float) $r['suggested']; ?>
          <tr class="<?= $onHand <= 0 ? 'row-bad' : ($cover !== null && $cover < 3 ? 'row-warn' : '') ?>">
            <td>
              <a href="<?= e(base_url('inventory/product', ['id' => (int) $r['id']])) ?>">
                <?= e($r['name']) ?></a>
              <br><span class="tiny muted mono"><?= e($r['sku']) ?></span>
            </td>
            <td class="tiny">
              <?php if ($r['supplier_name'] !== null): ?>
                <?= e((string) $r['supplier_name']) ?>
              <?php else: ?>
                <span class="bad-text">none set</span>
              <?php endif; ?>
            </td>
            <td class="num <?= $onHand < 0 ? 'neg' : '' ?>">
              <?= e(qty_fmt($onHand)) ?> <span class="tiny muted"><?= e($r['unit_code']) ?></span>
            </td>
            <td class="num"><?= e(qty_fmt((float) $r['qty_reserved'])) ?></td>
            <td class="num"><?= e(qty_fmt((float) $r['qty_on_order'])) ?></td>
            <td class="num"><?= e(qty_fmt((float) $r['reorder_level'])) ?></td>
            <td class="num"><?= e(qty_fmt((float) $r['units_sold'])) ?></td>
            <td class="num"><?= e(number_format($daily, 2)) ?></td>
            <td class="num"><?= (int) $r['lead_time_days'] ?></td>
            <td class="num <?= $cover !== null && $cover < 3 ? 'neg' : '' ?>">
              <?= e($cover === null ? '-' : number_format($cover, 1) . 'd') ?>
            </td>
            <td class="num">
              <?php if ($r['supplier_id'] !== null): ?>
                <input type="text" name="order_qty[<?= (int) $r['id'] ?>]" class="num-in"
                       style="max-width:88px" inputmode="decimal"
                       value="<?= e(qty_fmt($suggested)) ?>">
              <?php else: ?>
                <span class="muted">-</span>
              <?php endif; ?>
            </td>
            <?= num_cell($suggested * (float) $r['cost_price']) ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="10">Estimated total at list cost</td>
            <td></td>
            <td class="num"><?= e(money($estimatedCost)) ?></td>
          </tr>
        </tfoot>
      </table>
      </div>

      <p class="small muted mt1">
        Suggested quantity covers the gap to the reorder level plus the demand implied by the last
        <?= $lookback ?> days of sales over the supplier's lead time, rounded up to whole supplier
        cases. Stock already on an open order is counted, so a line you ordered yesterday is not
        suggested again. Edit any figure before creating the orders &mdash; the suggestion is
        arithmetic, not a buying decision.
      </p>
    <?= card_close() ?>
  </form>
<?php endif; ?>

<?php layout_foot(); ?>
