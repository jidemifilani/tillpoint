<?php
/**
 * Expiring stock.
 *
 * A food-safety control before it is a financial one, which is why anything
 * already past its date is separated out and shown first regardless of value.
 * The markdown suggestion is advisory: clearing stock at a reduced price
 * recovers more than writing it off, but only if it goes out before the date.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('reports.view');

$storeId  = current_store_id();
$horizon  = max(1, min(365, get_int('days', (int) setting_num('expiry_warn_days', (float) EXPIRY_WARN_DAYS))));
$batches  = expiring_batches($storeId, $horizon);

$expired  = array_values(array_filter($batches, fn ($b) => (int) $b['days_left'] < 0));
$urgent   = array_values(array_filter($batches, fn ($b) => (int) $b['days_left'] >= 0 && (int) $b['days_left'] <= 7));
$soon     = array_values(array_filter($batches, fn ($b) => (int) $b['days_left'] > 7));

$valueOf = static fn (array $rows): float
    => array_sum(array_map(fn ($b) => (float) $b['value_at_risk'], $rows));

$retailOf = static fn (array $rows): float
    => array_sum(array_map(fn ($b) => (float) $b['qty_on_hand'] * (float) $b['sell_price'], $rows));

// How much is being written off against how much is being cleared. A shop that
// writes off everything perishable is losing money it could have recovered with
// a timely markdown, and that comparison is the point of this report.
$period = 30;
$writtenOff = (float) qv(
    "SELECT COALESCE(SUM(w.total_value),0) FROM wastage w
      WHERE w.store_id = ? AND w.status = 'posted' AND w.reason_code IN ('EXPIRED','SPOILED')
        AND w.created_at >= (CURDATE() - INTERVAL ? DAY)",
    [$storeId, $period], 0.0
);
$markedDown = (float) qv(
    "SELECT COALESCE(SUM(sl.line_discount),0) FROM sale_lines sl
       JOIN sales s ON s.id = sl.sale_id
      WHERE s.store_id = ? AND s.business_date >= (CURDATE() - INTERVAL ? DAY)
        AND s.status IN ('completed','refunded','partly_refunded')
        AND sl.discount_reason = 'MARKDOWN'",
    [$storeId, $period], 0.0
);

if ((string) get('export') === 'csv') {
    audit_log('reports.expiring_export', 'stock', null,
        'Exported the expiry report: ' . count($batches) . ' batches', null, null, 'notice');
    send_csv('expiring-stock-' . date('Y-m-d') . '.csv', [
        'Batch', 'SKU', 'Product', 'Store', 'Expires', 'Days left',
        'On hand', 'Unit cost', 'Value at cost', 'Shelf price', 'Value at retail',
    ], array_map(fn ($b) => [
        $b['batch_no'], $b['sku'], $b['name'], $b['store_name'],
        $b['expiry_date'], $b['days_left'], $b['qty_on_hand'], $b['unit_cost'],
        $b['value_at_risk'], $b['sell_price'],
        (float) $b['qty_on_hand'] * (float) $b['sell_price'],
    ], $batches));
}

/** Render one block of batches. */
$renderTable = static function (array $rows, bool $showMarkdown) use ($storeId): void {
    ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Expires</th><th class="num">Days</th><th>Product</th><th>Batch</th>
        <th class="num">On hand</th><th class="num">At cost</th><th class="num">At retail</th>
        <?php if ($showMarkdown): ?><th class="num">Suggested price</th><?php endif; ?>
        <th>Delivered</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $b):
          $left  = (int) $b['days_left'];
          $price = (float) $b['sell_price'];
          // A sliding markdown: the closer the date, the deeper the cut. Floored
          // at cost, because selling below cost to avoid a write-off only makes
          // sense when the alternative is losing the whole value - and that is a
          // decision for a manager, not a default.
          $cut   = $left <= 1 ? 0.50 : ($left <= 3 ? 0.35 : ($left <= 7 ? 0.20 : 0.10));
          $suggested = max((float) $b['unit_cost'], money_round($price * (1 - $cut))); ?>
        <tr class="<?= $left < 0 ? 'row-bad' : ($left <= 7 ? 'row-warn' : '') ?>">
          <td class="nowrap"><?= e(date_fmt((string) $b['expiry_date'])) ?></td>
          <td class="num <?= $left < 0 ? 'neg' : '' ?>">
            <?= e($left < 0 ? abs($left) . 'd ago' : (string) $left) ?>
          </td>
          <td>
            <a href="<?= e(base_url('inventory/product', ['id' => (int) $b['product_id']])) ?>">
              <?= e($b['name']) ?></a>
            <br><span class="tiny muted mono"><?= e($b['sku']) ?></span>
          </td>
          <td class="mono tiny"><?= e($b['batch_no']) ?></td>
          <td class="num"><?= e(qty_fmt((float) $b['qty_on_hand'])) ?>
            <span class="tiny muted"><?= e($b['unit_code']) ?></span></td>
          <?= num_cell($b['value_at_risk']) ?>
          <?= num_cell((float) $b['qty_on_hand'] * $price) ?>
          <?php if ($showMarkdown): ?>
            <td class="num">
              <?= e(money($suggested)) ?>
              <br><span class="tiny muted">from <?= e(money($price)) ?></span>
            </td>
          <?php endif; ?>
          <td class="tiny nowrap"><?= e(date_fmt((string) $b['received_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php
};

layout_head([
    'title'    => 'Expiring stock',
    'subtitle' => (string) (current_store()['name'] ?? '') . ' - next ' . $horizon . ' days',
    'actions'  => '<a class="btn btn--ghost" href="' . e(url_with(['export' => 'csv'])) . '">Export CSV</a>'
                . '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<?php if ($expired): ?>
  <div class="verdict verdict--broken">
    <h2><?= count($expired) ?> batch(es) are past their date and still on the books</h2>
    <p>
      <?= e(money($valueOf($expired))) ?> at cost. Pull them off the shelf now and write them off
      from the <a href="<?= e(base_url('inventory/batches', ['view' => 'expired'])) ?>">batch
      list</a>, which takes them from the right batch so the figures stay honest.
    </p>
  </div>
<?php endif; ?>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">Look ahead</span>
    <select name="days">
      <?= select_options([7 => '7 days', 14 => '14 days', 30 => '30 days',
                          60 => '60 days', 90 => '90 days', 180 => '6 months'], $horizon) ?>
    </select>
  </label>
  <button class="btn" type="submit">Apply</button>
</form>

<div class="tiles">
  <?= tile('Already expired', number_format(count($expired)),
        money($valueOf($expired)) . ' at cost',
        $expired ? 'bad' : 'ok') ?>
  <?= tile('Within 7 days', number_format(count($urgent)),
        money($valueOf($urgent)) . ' at cost',
        $urgent ? 'warn' : 'ok') ?>
  <?= tile('Within ' . $horizon . ' days', number_format(count($soon)),
        money($valueOf($soon)) . ' at cost', 'info') ?>
  <?= tile('Total at risk', money($valueOf($batches)),
        money($retailOf($batches)) . ' at retail', 'warn') ?>
  <?= tile('Written off, 30 days', money($writtenOff),
        'expired or spoiled', $writtenOff > 0 ? 'bad' : 'ok') ?>
  <?= tile('Recovered by markdown', money($markedDown),
        'discount given to clear stock', 'ok') ?>
</div>

<?php if (!$batches): ?>
  <?= card_open('Expiring stock') ?>
    <?= empty_state('Nothing expires within ' . $horizon . ' days.',
          'Only products with batch tracking switched on appear here.') ?>
  <?= card_close() ?>
<?php else: ?>

  <?php if ($expired): ?>
    <?= card_open('Past their date - remove from sale (' . count($expired) . ')') ?>
      <?php $renderTable($expired, false); ?>
    <?= card_close() ?>
  <?php endif; ?>

  <?php if ($urgent): ?>
    <?= card_open('Within a week - mark down today (' . count($urgent) . ')') ?>
      <?php $renderTable($urgent, true); ?>
    <?= card_close() ?>
  <?php endif; ?>

  <?php if ($soon): ?>
    <?= card_open('Coming up (' . count($soon) . ')') ?>
      <?php $renderTable($soon, true); ?>
    <?= card_close() ?>
  <?php endif; ?>
<?php endif; ?>

<?= card_open('Markdown or write-off?') ?>
  <div class="grid-2">
    <div>
      <h3>The arithmetic</h3>
      <p class="small">
        A write-off recovers nothing and costs the full purchase price. A markdown recovers
        whatever the customer pays. So anything sold above cost before its date beats the bin,
        and anything sold at all beats the bin once the alternative is a total loss.
      </p>
      <p class="small">
        The suggested prices above cut deeper as the date approaches &mdash; 10% at a month out,
        half price on the last day &mdash; and are floored at cost. Going below cost can still be
        right when the choice is between recovering some of the value and none of it, but that is
        a manager's decision, so it is not the default.
      </p>
      <p class="small muted">
        Apply one with a price override at the till using the <code>MARKDOWN</code> reason, which
        is what the "recovered by markdown" figure above counts.
      </p>
    </div>
    <div>
      <h3>How the figures are kept honest</h3>
      <p class="small">
        Batches are consumed first-expiry-first-out, so the units nearest their date are always
        the ones sold next. That is what makes this report trustworthy: if the till sold from the
        newest batch, the oldest would sit here indefinitely while the shelf actually emptied.
      </p>
      <p class="small">
        Writing off from the <a href="<?= e(base_url('inventory/batches')) ?>">batch list</a>
        rather than the general wastage screen takes the stock from the named batch, so the batch
        balance and the expiry figures stay in step.
      </p>
      <p class="small muted">
        Expired stock still showing on hand raises <code>EXPIRED_ON_SHELF</code> at critical
        severity on the nightly scan &mdash; the one stock rule that is about safety rather than
        money.
      </p>
    </div>
  </div>
<?= card_close() ?>

<?php layout_foot(); ?>
