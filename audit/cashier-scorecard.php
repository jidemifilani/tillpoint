<?php
/**
 * Cashier scorecard.
 *
 * The central audit report. Its value is comparative: a 6% void rate means
 * nothing on its own and a great deal when everyone else is at 1%. So every
 * ratio is shown next to the shop average, and a figure is only marked when it
 * is both above the rule threshold and well above what colleagues are doing.
 *
 * This is also the report most capable of being unfair, which is why the page
 * says so. Someone on the busiest till at the busiest hour will always top the
 * absolute columns, and a high figure is a question, not a finding.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('audit.view');

$storeId = current_store_id();
$from    = date_or_null(get('from')) ?? date('Y-m-d', strtotime('-30 days'));
$to      = date_or_null(get('to')) ?? date('Y-m-d');

$scorecard = report_cashier_scorecard($storeId, $from, $to);
$avg       = report_scorecard_averages($scorecard);

if ((string) get('export') === 'csv' && can('reports.view')) {
    audit_log('audit.scorecard_export', 'user', null,
        sprintf('Exported the cashier scorecard for %s to %s', $from, $to), null, null, 'notice');
    send_csv('cashier-scorecard-' . $from . '-to-' . $to . '.csv', [
        'Staff no', 'Name', 'Transactions', 'Sales', 'Items', 'Average basket',
        'Voids', 'Void value', 'Void %', 'Discounts', 'Discount %',
        'Refunds', 'Refund value', 'Refund %', 'Price overrides', 'No-sale opens',
        'Shifts', 'Absolute variance', 'Net variance', 'Open exceptions',
    ], array_map(fn ($r) => [
        $r['staff_no'], $r['name'], $r['txn'], $r['sales'], $r['items'], $r['avg_basket'],
        $r['voids'], $r['void_value'], round($r['void_pct'], 2), $r['discounts'],
        round($r['discount_pct'], 2), $r['refund_count'], $r['refund_value'],
        round($r['refund_pct'], 2), $r['overrides'], $r['no_sales'], $r['shifts'],
        $r['abs_variance'], $r['net_variance'], $r['open_exceptions'],
    ], $scorecard));
}

// Rule thresholds, so the page marks on the same basis the scan does.
$voidThreshold     = audit_rule_threshold('EXCESSIVE_VOIDS', 15.0);
$discountThreshold = audit_rule_threshold('EXCESSIVE_DISCOUNTS', 8.0);
$overrideThreshold = audit_rule_threshold('EXCESSIVE_PRICE_OVERRIDES', 10.0);
$noSaleThreshold   = audit_rule_threshold('EXCESSIVE_NO_SALE', 10.0);
$tolerance         = setting_num('till_variance_tolerance', (float) TILL_VARIANCE_TOLERANCE);

/**
 * Is a figure genuinely an outlier?
 *
 * Both tests have to pass: over the rule's own threshold, and at least twice the
 * shop average. The second test is what stops a report full of marks on a day
 * when everyone legitimately discounted - if the whole shop is at 9%, the person
 * at 9.5% is not the story.
 */
$isOutlier = static function (float $value, float $threshold, float $average): bool {
    return $value > $threshold && ($average <= 0.01 || $value > $average * 2);
};

$days = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);

layout_head([
    'title'    => 'Cashier scorecard',
    'subtitle' => date_fmt($from) . ' to ' . date_fmt($to) . ' (' . $days . ' days)',
    'actions'  => (can('reports.view')
            ? '<a class="btn btn--ghost" href="' . e(url_with(['export' => 'csv'])) . '">Export CSV</a>' : '')
        . '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">From</span>
    <input type="date" name="from" value="<?= e($from) ?>">
  </label>
  <label class="field">
    <span class="field__label">To</span>
    <input type="date" name="to" value="<?= e($to) ?>">
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('audit/cashier-scorecard')) ?>">Last 30 days</a>
</form>

<?php if (!$scorecard): ?>
  <?= card_open('Scorecard') ?>
    <?= empty_state('No transactions in that period.') ?>
  <?= card_close() ?>
<?php else: ?>

<div class="tiles">
  <?= tile('Shop void rate', pct_fmt($avg['void_pct']),
        'flagged above ' . pct_fmt($voidThreshold, 0) . ' per person') ?>
  <?= tile('Shop discount rate', pct_fmt($avg['discount_pct']),
        'flagged above ' . pct_fmt($discountThreshold, 0)) ?>
  <?= tile('Shop refund rate', pct_fmt($avg['refund_pct']), 'of takings') ?>
  <?= tile('Average basket', money($avg['avg_basket']), 'across everyone', 'info') ?>
</div>

<?= card_open('By cashier',
      '<span class="tiny muted">Marked cells are above the rule threshold and twice the shop average</span>') ?>
  <div class="table-wrap">
  <table class="data">
    <thead><tr>
      <th>Cashier</th><th class="num">Txn</th><th class="num">Sales</th>
      <th class="num">Avg basket</th>
      <th class="num">Voids</th><th class="num">Void %</th>
      <th class="num">Discount</th><th class="num">Disc %</th>
      <th class="num">Refunds</th><th class="num">Ref %</th>
      <th class="num">Overrides</th><th class="num">No-sale</th>
      <th class="num">Shifts</th><th class="num">Till variance</th>
      <th class="num">Open findings</th>
    </tr></thead>
    <tbody>
    <?php foreach ($scorecard as $r):
        $voidOut     = $isOutlier($r['void_pct'], $voidThreshold, $avg['void_pct']);
        $discOut     = $isOutlier($r['discount_pct'], $discountThreshold, $avg['discount_pct']);
        $refOut      = $isOutlier($r['refund_pct'], 10.0, $avg['refund_pct']);
        $overrideOut = $r['overrides'] > $overrideThreshold * max(1, $days / 7);
        $noSaleOut   = $r['no_sales'] > $noSaleThreshold * max(1, $r['shifts']);
        $varOut      = $r['shifts'] > 0 && $r['abs_variance'] > $tolerance * $r['shifts'];
        $anyOut      = $voidOut || $discOut || $refOut || $overrideOut || $noSaleOut || $varOut; ?>
      <tr class="<?= $anyOut ? 'row-warn' : '' ?>">
        <td>
          <?= e($r['name']) ?>
          <br><span class="tiny muted mono"><?= e($r['staff_no']) ?></span>
        </td>
        <td class="num"><?= number_format($r['txn']) ?></td>
        <?= num_cell($r['sales']) ?>
        <?= num_cell($r['avg_basket']) ?>
        <td class="num"><?= number_format($r['voids']) ?></td>
        <td class="num <?= $voidOut ? 'neg' : '' ?>">
          <?= e(pct_fmt($r['void_pct'])) ?>
          <?php if ($voidOut): ?><br><?= badge('High', 'bad') ?><?php endif; ?>
        </td>
        <?= num_cell($r['discounts']) ?>
        <td class="num <?= $discOut ? 'neg' : '' ?>">
          <?= e(pct_fmt($r['discount_pct'])) ?>
          <?php if ($discOut): ?><br><?= badge('High', 'bad') ?><?php endif; ?>
        </td>
        <?= num_cell($r['refund_value']) ?>
        <td class="num <?= $refOut ? 'neg' : '' ?>">
          <?= e(pct_fmt($r['refund_pct'])) ?>
          <?php if ($refOut): ?><br><?= badge('High', 'bad') ?><?php endif; ?>
        </td>
        <td class="num <?= $overrideOut ? 'neg' : '' ?>"><?= number_format($r['overrides']) ?></td>
        <td class="num <?= $noSaleOut ? 'neg' : '' ?>"><?= number_format($r['no_sales']) ?></td>
        <td class="num"><?= number_format($r['shifts']) ?></td>
        <td class="num <?= $varOut ? 'neg' : '' ?>">
          <?= e(money($r['abs_variance'])) ?>
          <?php if ($r['net_variance'] < 0): ?>
            <br><span class="tiny bad-text">net <?= e(money($r['net_variance'])) ?></span>
          <?php endif; ?>
        </td>
        <td class="num <?= $r['open_exceptions'] > 0 ? 'neg' : '' ?>">
          <?php if ($r['open_exceptions'] > 0): ?>
            <a href="<?= e(base_url('audit/exceptions', ['subject' => $r['user_id'], 'status' => 'open'])) ?>">
              <?= number_format($r['open_exceptions']) ?></a>
          <?php else: ?>
            0
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td>Shop average</td>
        <td class="num">-</td><td class="num">-</td>
        <td class="num"><?= e(money($avg['avg_basket'])) ?></td>
        <td class="num">-</td>
        <td class="num"><?= e(pct_fmt($avg['void_pct'])) ?></td>
        <td class="num">-</td>
        <td class="num"><?= e(pct_fmt($avg['discount_pct'])) ?></td>
        <td class="num">-</td>
        <td class="num"><?= e(pct_fmt($avg['refund_pct'])) ?></td>
        <td class="num">-</td><td class="num">-</td><td class="num">-</td>
        <td class="num">-</td><td class="num">-</td>
      </tr>
    </tfoot>
  </table>
  </div>
<?= card_close() ?>

<?= card_open('How to read this') ?>
  <div class="grid-2">
    <div>
      <h3>What the columns mean</h3>
      <dl class="kv">
        <dt>Void %</dt>
        <dd>Baskets started and abandoned, as a share of all transactions. The strongest single
            signal of till theft: ring it up, take the cash, cancel the record.</dd>
        <dt>Discount %</dt>
        <dd>Discount given as a share of what the goods would have cost at shelf price. Catches a
            staff-and-friends discount applied without authority.</dd>
        <dt>Refund %</dt>
        <dd>Refund value as a share of takings. A refund is cash out of the drawer with only a
            reason code behind it.</dd>
        <dt>Overrides</dt>
        <dd>Lines sold at a manually set price. Either the shelf prices are wrong, or someone is
            choosing their own.</dd>
        <dt>No-sale</dt>
        <dd>Drawer opened with no transaction. Each one is an opportunity to take cash, or to
            settle a shortfall from an earlier one.</dd>
        <dt>Till variance</dt>
        <dd>Total cash discrepancy across their shifts, ignoring direction. A net figure close to
            zero with a large absolute figure means sloppiness; a consistently negative net figure
            means something else.</dd>
      </dl>
    </div>
    <div>
      <h3>Where this report can mislead</h3>
      <p class="small">
        Absolute figures track how busy someone was. Whoever works the express till at the Friday
        evening peak will have the most voids, the most no-sale opens and the largest variance,
        because they handled the most transactions and the most cash. That is why the marks here
        require a figure to be twice the shop average as well as over the threshold.
      </p>
      <p class="small">
        A new cashier will look worse than an experienced one, for the same reason and with the
        same innocence. A single bad day distorts a short reporting window, so prefer a month.
      </p>
      <p class="small">
        A marked cell is a <strong>question</strong>, not a finding. The pattern holding over
        several periods, on one person, while colleagues on comparable tills stay flat &mdash;
        that is a finding. Record what you concluded on the
        <a href="<?= e(base_url('audit/exceptions')) ?>">exception</a>, whichever way it goes.
      </p>
    </div>
  </div>
<?= card_close() ?>

<div class="sign-off">
  <div>Reviewed by: ________________________</div>
  <div>Date: ________________________</div>
</div>
<?php endif; ?>

<?php layout_foot(); ?>
