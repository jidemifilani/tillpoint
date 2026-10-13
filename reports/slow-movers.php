<?php
/**
 * Slow movers.
 *
 * Lines holding stock that nothing is buying, and the cash tied up in them.
 * The useful number is not "days since last sale" on its own - a line that has
 * not sold for ninety days and holds one unit is noise. It is days since last
 * sale weighted by the value sitting on the shelf, which is what decides whether
 * to mark down, delist, or leave alone.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('reports.view');

$storeId = current_store_id();
$days    = max(7, min(365, get_int('days', (int) setting_num('slow_mover_days', (float) SLOW_MOVER_DAYS))));
$minValue = num(get('min_value'), 0.0);

$rows = slow_movers($storeId, $days);

if ($minValue > 0) {
    $rows = array_values(array_filter($rows, fn ($r) => (float) $r['tied_up'] >= $minValue));
}

$tiedUp    = array_sum(array_map(fn ($r) => (float) $r['tied_up'], $rows));
$neverSold = array_values(array_filter($rows, fn ($r) => $r['last_sold_at'] === null));
$totalStock = stock_valuation($storeId);

// How much of the shop's stock value is not moving. The figure that makes the
// case for clearing it.
$sharePct = $totalStock['cost_value'] > 0 ? ($tiedUp / $totalStock['cost_value']) * 100 : 0.0;

if ((string) get('export') === 'csv') {
    audit_log('reports.slow_movers_export', 'stock', null,
        sprintf('Exported the slow-mover report: %d line(s), %s tied up', count($rows), money($tiedUp)),
        null, null, 'notice');
    send_csv('slow-movers-' . date('Y-m-d') . '.csv', [
        'SKU', 'Product', 'Unit', 'On hand', 'Avg cost', 'Tied up at cost',
        'Shelf price', 'Last sold', 'Days since sale',
    ], array_map(fn ($r) => [
        $r['sku'], $r['name'], $r['unit_code'], $r['qty_on_hand'], $r['avg_cost'],
        $r['tied_up'], $r['sell_price'], $r['last_sold_at'] ?? 'never',
        $r['days_since_sale'] ?? '',
    ], $rows));
}

layout_head([
    'title'    => 'Slow movers',
    'subtitle' => (string) (current_store()['name'] ?? '') . ' - no sale in ' . $days . '+ days',
    'actions'  => '<a class="btn btn--ghost" href="' . e(url_with(['export' => 'csv'])) . '">Export CSV</a>'
                . '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">No sale in</span>
    <select name="days">
      <?= select_options([14 => '14 days', 30 => '30 days', 60 => '60 days',
                          90 => '90 days', 180 => '6 months', 365 => 'a year'], $days) ?>
    </select>
  </label>
  <label class="field">
    <span class="field__label">Tied up at least</span>
    <input type="text" name="min_value" class="num-in"
           value="<?= e($minValue > 0 ? (string) $minValue : '') ?>" placeholder="0.00">
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('reports/slow-movers')) ?>">Reset</a>
</form>

<div class="tiles">
  <?= tile('Lines not moving', number_format(count($rows)),
        'holding stock, no sale in ' . $days . ' days',
        count($rows) > 0 ? 'warn' : 'ok') ?>
  <?= tile('Cash tied up', money_short($tiedUp), 'at weighted average cost',
        $tiedUp > 0 ? 'bad' : 'ok') ?>
  <?= tile('Share of stock value', pct_fmt($sharePct),
        'of ' . money_short($totalStock['cost_value']) . ' held',
        $sharePct > 20 ? 'bad' : ($sharePct > 10 ? 'warn' : 'ok')) ?>
  <?= tile('Never sold here', number_format(count($neverSold)),
        money(array_sum(array_map(fn ($r) => (float) $r['tied_up'], $neverSold))) . ' at cost',
        count($neverSold) > 0 ? 'bad' : 'ok') ?>
</div>

<?php if (!$rows): ?>
  <?= card_open('Slow movers') ?>
    <?= empty_state('Nothing has gone ' . $days . ' days without a sale.',
          'Every line holding stock has sold at least once in that window.') ?>
  <?= card_close() ?>
<?php else: ?>

<?= card_open('Lines to deal with (' . count($rows) . ')') ?>
  <div class="table-wrap">
  <table class="data">
    <thead><tr>
      <th>SKU</th><th>Product</th><th class="num">On hand</th>
      <th class="num">Avg cost</th><th class="num">Tied up</th>
      <th class="num">Shelf price</th><th>Last sold</th><th class="num">Days</th>
      <th>Suggestion</th>
    </tr></thead>
    <tbody>
    <?php
    $maxTied = max(array_map(fn ($r) => (float) $r['tied_up'], $rows)) ?: 1.0;
    foreach ($rows as $r):
        $tied  = (float) $r['tied_up'];
        $since = $r['days_since_sale'] !== null ? (int) $r['days_since_sale'] : null;
        $never = $r['last_sold_at'] === null;

        // The suggestion weighs how long it has sat against how much is sitting.
        // A dead line with real money in it is worth a markdown; a dead line
        // with one unit left is worth delisting and forgetting.
        if ($never) {
            [$tone, $suggest] = ['bad', 'Never sold here - check it is on the shelf and priced'];
        } elseif ($tied >= 50000) {
            [$tone, $suggest] = ['bad', 'Mark down hard - significant cash sitting still'];
        } elseif ($since !== null && $since > 180) {
            [$tone, $suggest] = ['bad', 'Delist - half a year without a buyer'];
        } elseif ($tied >= 10000) {
            [$tone, $suggest] = ['warn', 'Mark down to clear'];
        } else {
            [$tone, $suggest] = ['neutral', 'Run down and do not reorder'];
        } ?>
      <tr class="<?= $tone === 'bad' ? 'row-bad' : ($tone === 'warn' ? 'row-warn' : '') ?>">
        <td class="mono tiny"><?= e($r['sku']) ?></td>
        <td>
          <a href="<?= e(base_url('inventory/product', ['id' => (int) $r['id']])) ?>">
            <?= e($r['name']) ?></a>
        </td>
        <td class="num"><?= e(qty_fmt((float) $r['qty_on_hand'])) ?>
          <span class="tiny muted"><?= e($r['unit_code']) ?></span></td>
        <?= num_cell($r['avg_cost']) ?>
        <td class="num">
          <?= e(money($tied)) ?>
          <div class="bar bar--bad" style="max-width:70px;margin-top:2px">
            <span style="width:<?= (int) round(($tied / $maxTied) * 100) ?>%"></span>
          </div>
        </td>
        <?= num_cell($r['sell_price']) ?>
        <td class="tiny nowrap">
          <?= e($never ? 'never' : date_fmt((string) $r['last_sold_at'])) ?>
        </td>
        <td class="num"><?= e($since !== null ? (string) $since : '-') ?></td>
        <td class="tiny"><?= e($suggest) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="4"><strong>Total tied up</strong></td>
        <td class="num"><strong><?= e(money($tiedUp)) ?></strong></td>
        <td colspan="4"></td>
      </tr>
    </tfoot>
  </table>
  </div>
<?= card_close() ?>

<?= card_open('Reading this report') ?>
  <div class="grid-2">
    <div>
      <h3>Value matters more than age</h3>
      <p class="small">
        A line that has not sold in ninety days and holds one unit worth 300 naira is not a
        problem; it is a slot on a shelf. A line that has not sold in thirty days and holds
        80,000 naira of stock is working capital doing nothing, and it will keep doing nothing
        until somebody prices it to move.
      </p>
      <p class="small">
        That is why the suggestions above weigh both. The bar in the "tied up" column shows each
        line against the worst one, so the few that matter stand out from the long tail.
      </p>
    </div>
    <div>
      <h3>Check before you delist</h3>
      <p class="small">
        A line showing as never sold is as likely to be a shelving or pricing problem as a demand
        problem &mdash; stock received into the back and never put out will look exactly like
        this. So will a product whose barcode does not scan, because the cashier will reach for
        something that does.
      </p>
      <p class="small muted">
        Check the <a href="<?= e(base_url('inventory/batches')) ?>">batch list</a> first for
        anything perishable: a slow mover with a date on it is a write-off waiting to happen, and
        marking it down now recovers more than binning it later.
      </p>
    </div>
  </div>
<?= card_close() ?>
<?php endif; ?>

<?php layout_foot(); ?>
