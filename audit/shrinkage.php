<?php
/**
 * Shrinkage.
 *
 * Read off the movement ledger rather than the documents, because the ledger is
 * the thing that balances. Stock leaves a supermarket four ways, and the whole
 * point of this page is that they are not the same thing:
 *
 *   sold            - expected, and the denominator everything else is measured against
 *   wastage         - accounted loss: someone wrote it off and said why
 *   count variance  - unexplained loss: it was not on the shelf and nobody knows why
 *   adjustment      - unexplained loss someone corrected the books for
 *
 * The last two are what "shrinkage" actually means. The first two are costs.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('audit.view');

$storeId = current_store_id();
$from    = date_or_null(get('from')) ?? date('Y-m-d', strtotime('-90 days'));
$to      = date_or_null(get('to')) ?? date('Y-m-d');

$shrink    = report_shrinkage($storeId, $from, $to);

// The store's own target. A bare shrinkage percentage is uninterpretable - 1.8%
// is good for a convenience branch and poor for a depot - so the figure is shown
// against the target set on the store, and the gap is what gets the colour.
$target = (float) qv('SELECT shrinkage_target_pct FROM stores WHERE id = ?', [$storeId], 2.0);
$gap    = $shrink['shrinkage_pct'] - $target;

// Previous equal-length period, so the trend is visible. A rate on its own
// cannot tell you whether things are getting better or worse.
$periodDays = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);
$prevTo     = date('Y-m-d', strtotime($from . ' -1 day'));
$prevFrom   = date('Y-m-d', strtotime($prevTo . ' -' . ($periodDays - 1) . ' days'));
$prevShrink = report_shrinkage($storeId, $prevFrom, $prevTo);
$trend      = $shrink['shrinkage_pct'] - $prevShrink['shrinkage_pct'];
$byReason  = report_wastage_by_reason($storeId, $from, $to);
$ledger    = stock_ledger_discrepancies($storeId);

// Where the unexplained loss sits, by product. The list a manager walks the
// shop with.
// The three loss expressions are repeated in HAVING and ORDER BY rather than
// referenced by alias: MariaDB refuses an alias that wraps an aggregate in
// either clause ("reference to group function"), so a tidier query does not run.
$lossSum = "COALESCE(SUM(CASE WHEN sm.movement_type = 'count_variance' AND sm.qty_delta < 0
                         THEN sm.value_delta ELSE 0 END),0)
          + COALESCE(SUM(CASE WHEN sm.movement_type = 'wastage'
                         THEN sm.value_delta ELSE 0 END),0)
          + COALESCE(SUM(CASE WHEN sm.movement_type = 'adjustment' AND sm.qty_delta < 0
                         THEN sm.value_delta ELSE 0 END),0)";

$worstProducts = qa(
    "SELECT p.id, p.sku, p.name, c.name AS category,
            COALESCE(SUM(CASE WHEN sm.movement_type = 'count_variance' AND sm.qty_delta < 0
                         THEN sm.value_delta ELSE 0 END),0) AS count_loss,
            COALESCE(SUM(CASE WHEN sm.movement_type = 'wastage'
                         THEN sm.value_delta ELSE 0 END),0) AS waste_loss,
            COALESCE(SUM(CASE WHEN sm.movement_type = 'adjustment' AND sm.qty_delta < 0
                         THEN sm.value_delta ELSE 0 END),0) AS adj_loss,
            COALESCE(SUM(CASE WHEN sm.movement_type = 'sale'
                         THEN ABS(sm.value_delta) ELSE 0 END),0) AS sold
       FROM stock_movements sm
       JOIN products p ON p.id = sm.product_id
       JOIN categories c ON c.id = p.category_id
      WHERE sm.store_id = ? AND DATE(sm.created_at) BETWEEN ? AND ?
      GROUP BY p.id, p.sku, p.name, c.name
      HAVING ($lossSum) < -0.005
      ORDER BY ($lossSum) ASC
      LIMIT 40",
    [$storeId, $from, $to]
);

$catLoss = "COALESCE(SUM(CASE WHEN sm.movement_type IN ('wastage','count_variance','adjustment')
                              AND sm.qty_delta < 0 THEN sm.value_delta ELSE 0 END),0)";
$catSold = "COALESCE(SUM(CASE WHEN sm.movement_type = 'sale'
                         THEN ABS(sm.value_delta) ELSE 0 END),0)";

$byCategory = qa(
    "SELECT c.id, c.name, $catLoss AS loss, $catSold AS sold
       FROM stock_movements sm
       JOIN products p ON p.id = sm.product_id
       JOIN categories c ON c.id = p.category_id
      WHERE sm.store_id = ? AND DATE(sm.created_at) BETWEEN ? AND ?
      GROUP BY c.id, c.name
      HAVING $catLoss < -0.005 OR $catSold > 0
      ORDER BY $catLoss ASC",
    [$storeId, $from, $to]
);

$countHistory = qa(
    'SELECT sc.*, cb.full_name AS counted_by_name, ab.full_name AS approved_by_name,
            cat.name AS category_name
       FROM stock_counts sc
       LEFT JOIN users cb ON cb.id = sc.counted_by
       LEFT JOIN users ab ON ab.id = sc.approved_by
       LEFT JOIN categories cat ON cat.id = sc.category_id
      WHERE sc.store_id = ? AND sc.status = \'posted\'
        AND DATE(sc.posted_at) BETWEEN ? AND ?
      ORDER BY sc.posted_at DESC LIMIT 30',
    [$storeId, $from, $to]
);

layout_head([
    'title'    => 'Shrinkage',
    'subtitle' => date_fmt($from) . ' to ' . date_fmt($to),
    'actions'  => '<button class="btn btn--ghost" data-print>Print</button>',
]);
?>

<?php if ($ledger): ?>
  <div class="verdict verdict--broken">
    <h2>A stock position disagrees with its own ledger</h2>
    <p>
      <?= count($ledger) ?> line(s) hold a quantity that is not the sum of their own movements.
      Every change to stock goes through a single write path that always writes both, so this
      should be impossible. It means a defect or a direct database edit, and until it is explained
      no shrinkage figure on this page can be relied on.
    </p>
    <div class="table-wrap" style="margin-top:.6rem">
    <table class="data data--tight">
      <thead><tr><th>Product</th><th class="num">Position says</th>
                 <th class="num">Ledger says</th><th class="num">Difference</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($ledger, 0, 15) as $d): ?>
        <tr>
          <td><?= e($d['name']) ?> <span class="tiny muted mono"><?= e($d['sku']) ?></span></td>
          <td class="num"><?= e(qty_fmt((float) $d['qty_on_hand'])) ?></td>
          <td class="num"><?= e(qty_fmt((float) $d['ledger_qty'])) ?></td>
          <?= num_cell($d['difference'], false, true) ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Cost of goods sold', money_short($shrink['cost_of_sales']),
        'the figure loss is measured against') ?>
  <?= tile('Unexplained loss', money($shrink['unexplained_loss']),
        'count variance and adjustments',
        $shrink['unexplained_loss'] > 0 ? 'bad' : 'ok') ?>
  <?= tile('Accounted wastage', money($shrink['accounted_loss']),
        'written off with a reason', 'warn') ?>
  <?= tile('Total loss', money($shrink['total_loss']), 'wastage plus unexplained', 'bad') ?>
  <?= tile('Shrinkage rate', pct_fmt($shrink['shrinkage_pct']),
        sprintf('target %s - %s%s', pct_fmt($target),
                $gap > 0 ? 'over by ' : 'inside by ', pct_fmt(abs($gap))),
        $gap > 0.5 ? 'bad' : ($gap > 0 ? 'warn' : 'ok')) ?>
  <?= tile('Against last period',
        ($trend > 0 ? '+' : '') . pct_fmt($trend),
        date_fmt($prevFrom) . ' to ' . date_fmt($prevTo)
          . ' was ' . pct_fmt($prevShrink['shrinkage_pct']),
        $trend > 0.2 ? 'bad' : ($trend < -0.2 ? 'ok' : 'mute')) ?>
  <?= tile('Ledger integrity', $ledger ? count($ledger) . ' broken' : 'Reconciles',
        'positions against movements', $ledger ? 'bad' : 'ok') ?>
</div>

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
  <a class="btn btn--ghost" href="<?= e(base_url('audit/shrinkage')) ?>">Last 90 days</a>
</form>

<?= card_open('How stock left the store') ?>
  <div class="table-wrap">
  <table class="data">
    <thead><tr>
      <th>Movement</th><th class="num">Value at cost</th>
      <th class="num">% of cost of sales</th><th>What it means</th>
    </tr></thead>
    <tbody>
      <tr>
        <td><strong>Sold</strong></td>
        <?= num_cell($shrink['cost_of_sales']) ?>
        <td class="num">-</td>
        <td class="tiny muted">Expected. The denominator for everything below.</td>
      </tr>
      <tr class="row-warn">
        <td><strong>Written off</strong></td>
        <?= num_cell($shrink['wastage']) ?>
        <td class="num">
          <?= e($shrink['cost_of_sales'] > 0
                ? pct_fmt(($shrink['wastage'] / $shrink['cost_of_sales']) * 100) : '-') ?>
        </td>
        <td class="tiny muted">
          Accounted loss: somebody recorded it, with a reason code and their name against it.
          A cost of doing business, within reason.
        </td>
      </tr>
      <tr class="<?= $shrink['count_variance'] < 0 ? 'row-bad' : '' ?>">
        <td><strong>Found short at count</strong></td>
        <?= num_cell($shrink['count_variance'], true, true) ?>
        <td class="num">
          <?= e($shrink['cost_of_sales'] > 0
                ? pct_fmt((abs(min(0.0, $shrink['count_variance'])) / $shrink['cost_of_sales']) * 100) : '-') ?>
        </td>
        <td class="tiny muted">
          Unexplained loss: it was not on the shelf and nobody knows why. Theft, unrecorded
          wastage, or goods that never arrived.
        </td>
      </tr>
      <tr class="<?= $shrink['adjustments'] < 0 ? 'row-bad' : '' ?>">
        <td><strong>Adjusted out</strong></td>
        <?= num_cell($shrink['adjustments'], true, true) ?>
        <td class="num">
          <?= e($shrink['cost_of_sales'] > 0
                ? pct_fmt((abs(min(0.0, $shrink['adjustments'])) / $shrink['cost_of_sales']) * 100) : '-') ?>
        </td>
        <td class="tiny muted">
          The books corrected with no physical event behind it. Watch this one: a large figure
          here can be a real loss being tidied away.
        </td>
      </tr>
    </tbody>
    <tfoot>
      <tr>
        <td><strong>Total loss</strong></td>
        <td class="num"><strong><?= e(money($shrink['total_loss'])) ?></strong></td>
        <td class="num"><strong><?= e(pct_fmt($shrink['shrinkage_pct'])) ?></strong></td>
        <td class="tiny muted">
          This store's target is <?= e(pct_fmt($target)) ?>, set under
          Administration &rarr; Stores. Fresh and high-value lines carry most of the loss, so a
          branch with a large chilled range will legitimately sit higher than a dry-goods depot.
        </td>
      </tr>
    </tfoot>
  </table>
  </div>
<?= card_close() ?>

<div class="grid-2">
  <?= card_open('Loss by category') ?>
    <?php if (!$byCategory): ?>
      <?= empty_state('No movements in that period.') ?>
    <?php else:
        $worst = min(array_map(fn ($c) => (float) $c['loss'], $byCategory)) ?: -1.0; ?>
      <div class="table-wrap">
      <table class="data data--tight">
        <thead><tr>
          <th>Category</th><th class="num">Sold at cost</th><th class="num">Lost</th>
          <th class="num">Rate</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($byCategory as $c):
            $sold = (float) $c['sold'];
            $loss = abs((float) $c['loss']);
            $rate = $sold > 0 ? ($loss / $sold) * 100 : null; ?>
          <tr class="<?= $rate !== null && $rate > 3 ? 'row-bad' : '' ?>">
            <td><?= e($c['name']) ?></td>
            <?= num_cell($sold) ?>
            <?= num_cell(-$loss) ?>
            <td class="num <?= $rate !== null && $rate > 3 ? 'neg' : '' ?>">
              <?= e($rate !== null ? pct_fmt($rate) : '-') ?>
            </td>
            <td><div class="bar bar--bad" style="max-width:80px">
              <span style="width:<?= (int) round(($loss / abs($worst)) * 100) ?>%"></span>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <p class="tiny muted mt1">
        The rate matters more than the amount. Fresh produce losing 4% of what it sells is normal;
        tinned goods losing 4% is not, because nothing in that aisle spoils.
      </p>
    <?php endif; ?>
  <?= card_close() ?>

  <?= card_open('Wastage by reason') ?>
    <?php if (!$byReason): ?>
      <?= empty_state('Nothing written off in that period.') ?>
    <?php else:
        $maxVal = max(array_map(fn ($r) => (float) $r['value'], $byReason)) ?: 1.0; ?>
      <table class="data data--tight">
        <thead><tr><th>Reason</th><th class="num">Records</th>
                   <th class="num">Value</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($byReason as $r): ?>
          <tr>
            <td><?= e($r['label']) ?></td>
            <td class="num"><?= (int) $r['records'] ?></td>
            <?= num_cell($r['value']) ?>
            <td><div class="bar bar--warn" style="max-width:80px">
              <span style="width:<?= (int) round(((float) $r['value'] / $maxVal) * 100) ?>%"></span>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="tiny muted mt1">
        A reason code that dominates is worth questioning. "Damaged in store" running well above
        "expired" on a chilled range usually means something other than damage.
      </p>
    <?php endif; ?>
  <?= card_close() ?>
</div>

<?= card_open('Worst products by unexplained loss') ?>
  <?php if (!$worstProducts): ?>
    <?= empty_state('No product recorded a net loss in that period.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>SKU</th><th>Product</th><th>Category</th>
        <th class="num">Sold at cost</th><th class="num">Written off</th>
        <th class="num">Short at count</th><th class="num">Adjusted out</th>
        <th class="num">Total loss</th><th class="num">Rate</th>
      </tr></thead>
      <tbody>
      <?php foreach ($worstProducts as $p):
          $total = (float) $p['count_loss'] + (float) $p['waste_loss'] + (float) $p['adj_loss'];
          $sold  = (float) $p['sold'];
          $rate  = $sold > 0 ? (abs($total) / $sold) * 100 : null; ?>
        <tr class="<?= $rate !== null && $rate > 5 ? 'row-bad' : '' ?>">
          <td class="mono tiny"><?= e($p['sku']) ?></td>
          <td>
            <a href="<?= e(base_url('inventory/movements', ['product_id' => (int) $p['id']])) ?>">
              <?= e($p['name']) ?></a>
          </td>
          <td class="tiny"><?= e($p['category']) ?></td>
          <?= num_cell($sold) ?>
          <?= num_cell($p['waste_loss']) ?>
          <?= num_cell($p['count_loss']) ?>
          <?= num_cell($p['adj_loss']) ?>
          <?= num_cell($total) ?>
          <td class="num <?= $rate !== null && $rate > 5 ? 'neg' : '' ?>">
            <?= e($rate !== null ? pct_fmt($rate) : '-') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="small muted mt1">
      Each product name links to its full movement history, which is where a loss either explains
      itself or does not.
    </p>
  <?php endif; ?>
<?= card_close() ?>

<?php if ($countHistory): ?>
<?= card_open('Counts posted in the period') ?>
  <div class="table-wrap">
  <table class="data data--tight">
    <thead><tr>
      <th>Count</th><th>Scope</th><th>Posted</th>
      <th class="num">Variance units</th><th class="num">Variance value</th>
      <th>Counted by</th><th>Approved by</th>
    </tr></thead>
    <tbody>
    <?php foreach ($countHistory as $c):
        $selfApproved = $c['approved_by'] !== null && $c['counted_by'] !== null
                        && (int) $c['approved_by'] === (int) $c['counted_by']; ?>
      <tr class="<?= $selfApproved ? 'row-bad' : ((float) $c['variance_value'] < 0 ? 'row-warn' : '') ?>">
        <td class="mono tiny">
          <a href="<?= e(base_url('inventory/count', ['id' => (int) $c['id']])) ?>">
            <?= e($c['count_no']) ?></a>
        </td>
        <td class="tiny"><?= e($c['category_name'] ?? 'All products') ?></td>
        <td class="nowrap tiny"><?= e(date_fmt((string) $c['posted_at'])) ?></td>
        <?= num_cell($c['variance_qty'], false, true) ?>
        <?= num_cell($c['variance_value'], true, true) ?>
        <td class="tiny"><?= e($c['counted_by_name'] ?? '-') ?></td>
        <td class="tiny">
          <?= e($c['approved_by_name'] ?? '-') ?>
          <?php if ($selfApproved): ?><br><?= badge('Self-approved', 'bad') ?><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?= card_close() ?>
<?php endif; ?>

<div class="sign-off">
  <div>Reviewed by: ________________________</div>
  <div>Date: ________________________</div>
</div>

<?php layout_foot(); ?>
