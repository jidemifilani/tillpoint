<?php
/**
 * Stock counts.
 *
 * The approval step is the whole control. A stock controller who could count
 * and sign off their own sheet could write off whatever they removed by counting
 * it as absent - turning the control that detects theft into the means of
 * concealing it. count_post() refuses it; this page hides the button too.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('count.enter');

$storeId = current_store_id();

if (is_post()) {
    csrf_require();
    try {
        $action  = (string) post('action');
        $countId = post_int('count_id');

        if ($action === 'create') {
            require_can('count.create');
            $type = one_of((string) post('count_type'), ['cycle', 'full', 'spot'], 'cycle');
            $cat  = post_int('category_id') ?: null;

            if ($type === 'cycle' && $cat === null) {
                throw new StockError('A cycle count needs a category to scope it to. '
                    . 'Choose "full" to count everything.');
            }

            $newId = db_tx(function () use ($storeId, $type, $cat): int {
                $id = db_insert('stock_counts', [
                    'count_no'    => next_number('count', $storeId),
                    'store_id'    => $storeId,
                    'count_type'  => $type,
                    'status'      => 'open',
                    'category_id' => $cat,
                    'scope_note'  => (string) post('scope_note') !== '' ? (string) post('scope_note') : null,
                    'created_by'  => (int) $_SESSION['uid'],
                ]);
                $n = count_generate_sheet($id);
                if ($n === 0) {
                    throw new StockError('That scope has no active products in it.');
                }
                return $id;
            });

            flash_ok('Count sheet generated. The system quantities are frozen as of now.');
            redirect('inventory/count', ['id' => $newId]);
        }

        if ($action === 'approve') {
            require_can('count.approve');
            $result = count_post($countId, (int) $_SESSION['uid']);
            flash_ok(sprintf('Count approved and posted: %d line(s) adjusted, %s at cost.',
                $result['lines_posted'], money((float) $result['variance_value'])));
        }

        if ($action === 'cancel') {
            require_can('count.create');
            $c = q1('SELECT * FROM stock_counts WHERE id = ? AND store_id = ?', [$countId, $storeId]);
            if (!$c || !in_array($c['status'], ['open', 'counted'], true)) {
                throw new StockError('Only an open or submitted count can be cancelled.');
            }
            db_update('stock_counts', ['status' => 'cancelled'], 'id = :id', ['id' => $countId]);
            audit_log('count.cancel', 'stock_count', $countId,
                'Count ' . $c['count_no'] . ' cancelled before posting', null, null, 'warning');
            flash_ok('Count cancelled. No stock was adjusted.');
        }
    } catch (SodViolation $e) {
        flash_err($e->getMessage());
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect('inventory/counts');
}

$status = one_of((string) get('status'), ['open', 'all', 'counted', 'posted', 'cancelled'], 'open');
$where  = ['sc.store_id = ?'];
$params = [$storeId];

if ($status === 'open') {
    $where[] = "sc.status IN ('open','counted')";
} elseif ($status !== 'all') {
    $where[]  = 'sc.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);

$rows = qa(
    "SELECT sc.*, c.name AS category_name,
            cb.full_name AS created_by_name, ct.full_name AS counted_by_name,
            ab.full_name AS approved_by_name,
            (SELECT COUNT(*) FROM stock_count_lines scl WHERE scl.count_id = sc.id) AS line_count,
            (SELECT COUNT(*) FROM stock_count_lines scl
               WHERE scl.count_id = sc.id AND scl.counted_qty IS NOT NULL) AS counted_lines,
            (SELECT COUNT(*) FROM stock_count_lines scl
               WHERE scl.count_id = sc.id AND ABS(scl.variance_qty) > 0.0005) AS variance_lines
       FROM stock_counts sc
       LEFT JOIN categories c ON c.id = sc.category_id
       JOIN users cb ON cb.id = sc.created_by
       LEFT JOIN users ct ON ct.id = sc.counted_by
       LEFT JOIN users ab ON ab.id = sc.approved_by
      WHERE $whereSql
      ORDER BY FIELD(sc.status,'counted','open','posted','cancelled'), sc.created_at DESC
      LIMIT 100",
    $params
);

$summary = q1(
    "SELECT COUNT(*) AS n, COALESCE(SUM(sc.variance_value),0) AS net_variance,
            SUM(sc.status = 'counted') AS awaiting
       FROM stock_counts sc WHERE $whereSql",
    $params
);
$shrinkage = (float) qv(
    'SELECT COALESCE(SUM(variance_value),0) FROM stock_counts
      WHERE store_id = ? AND status = \'posted\' AND variance_value < 0
        AND posted_at >= (CURDATE() - INTERVAL 90 DAY)',
    [$storeId], 0.0
);

$categories = qpairs('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name');
$staleDays  = (int) audit_rule_threshold('STALE_COUNT', 90.0);
$uncounted  = (int) qv(
    'SELECT COUNT(*) FROM stock_levels sl JOIN products p ON p.id = sl.product_id
      WHERE sl.store_id = ? AND sl.qty_on_hand > 0 AND p.is_active = 1
        AND (sl.last_counted_at IS NULL OR sl.last_counted_at < (CURDATE() - INTERVAL ? DAY))',
    [$storeId, $staleDays], 0
);

layout_head([
    'title'    => 'Stock counts',
    'subtitle' => (int) $summary['awaiting'] > 0
        ? (int) $summary['awaiting'] . ' awaiting sign-off'
        : 'Nothing awaiting sign-off',
    'actions'  => can('count.create')
        ? '<a class="btn btn--ghost" href="' . e(base_url('inventory/stock', ['view' => 'uncounted'])) . '">What needs counting</a>'
        : '',
]);
?>

<div class="tiles">
  <?= tile('Counts', number_format((int) $summary['n']), 'matching the filter') ?>
  <?= tile('Awaiting sign-off', number_format((int) $summary['awaiting']), 'counted, not approved',
        (int) $summary['awaiting'] > 0 ? 'warn' : 'ok') ?>
  <?= tile('Shortfall, last 90 days', money(abs($shrinkage)), 'found at count, at cost',
        abs($shrinkage) > 0 ? 'bad' : 'ok') ?>
  <?= tile('Not counted in ' . $staleDays . 'd', number_format($uncounted),
        'lines holding stock', $uncounted > 0 ? 'warn' : 'ok') ?>
</div>

<?php if (can('count.create')): ?>
  <?= card_open('Start a count') ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-grid">
        <label class="field">
          <span class="field__label">Type</span>
          <select name="count_type" required>
            <option value="cycle">Cycle count &mdash; one category</option>
            <option value="full">Full count &mdash; everything</option>
            <option value="spot">Spot check &mdash; a few lines</option>
          </select>
        </label>
        <label class="field">
          <span class="field__label">Category (cycle counts)</span>
          <select name="category_id"><?= select_options($categories, '', true, '-- all products --') ?></select>
        </label>
        <label class="field">
          <span class="field__label">Note</span>
          <input type="text" name="scope_note" maxlength="255" placeholder="Aisle 4, top shelf">
        </label>
      </div>
      <p class="small muted">
        The sheet freezes the system quantity for every line in scope as of the moment you generate
        it. That matters: the shop keeps trading while someone walks the aisles, and comparing a
        09:00 count against the 14:00 position would report every sale in between as shrinkage.
        A full count must have every line entered before it can be submitted.
      </p>
      <button class="btn" type="submit">Generate count sheet</button>
    </form>
  <?= card_close() ?>
<?php endif; ?>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">Status</span>
    <select name="status">
      <?= select_options(['open' => 'In progress', 'all' => 'All', 'counted' => 'Awaiting sign-off',
                          'posted' => 'Posted', 'cancelled' => 'Cancelled'], $status) ?>
    </select>
  </label>
  <button class="btn" type="submit">Apply</button>
</form>

<?= card_open('Counts') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No counts match.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Count</th><th>Type</th><th>Scope</th><th>Progress</th>
        <th class="num">Variance lines</th><th class="num">Variance value</th>
        <th>Counted by</th><th>Approved by</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $c):
          $countedBy = $c['counted_by'] !== null ? (int) $c['counted_by'] : null;
          $isMine    = $countedBy !== null && $countedBy === (int) $_SESSION['uid'];
          $progress  = (int) $c['line_count'] > 0
              ? ((int) $c['counted_lines'] / (int) $c['line_count']) * 100 : 0;
          $variance  = (float) $c['variance_value']; ?>
        <tr class="<?= $c['status'] === 'counted' ? 'row-warn' : ($variance < 0 ? 'row-bad' : '') ?>">
          <td class="mono tiny">
            <a href="<?= e(base_url('inventory/count', ['id' => (int) $c['id']])) ?>">
              <?= e($c['count_no']) ?></a>
          </td>
          <td class="tiny"><?= e(ucfirst((string) $c['count_type'])) ?></td>
          <td class="tiny">
            <?= e($c['category_name'] ?? 'All products') ?>
            <?php if ($c['scope_note'] !== null): ?>
              <br><span class="muted"><?= e((string) $c['scope_note']) ?></span>
            <?php endif; ?>
          </td>
          <td class="tiny nowrap">
            <?= (int) $c['counted_lines'] ?> / <?= (int) $c['line_count'] ?>
            <div class="bar" style="max-width:80px;margin-top:2px">
              <span style="width:<?= (int) round($progress) ?>%"></span>
            </div>
          </td>
          <td class="num"><?= (int) $c['variance_lines'] ?></td>
          <?= num_cell($variance, true, true) ?>
          <td class="tiny"><?= e($c['counted_by_name'] ?? '-') ?></td>
          <td class="tiny"><?= e($c['approved_by_name'] ?? '-') ?></td>
          <td><?= status_badge((string) $c['status']) ?></td>
          <td class="right nowrap">
            <?php if ($c['status'] === 'open'): ?>
              <a class="btn btn--sm btn--ghost"
                 href="<?= e(base_url('inventory/count', ['id' => (int) $c['id']])) ?>">Enter counts</a>
            <?php elseif ($c['status'] === 'counted' && can('count.approve')): ?>
              <?php if ($isMine): ?>
                <span class="tiny bad-text" title="Segregation of duties">You counted it</span>
              <?php else: ?>
                <form method="post" class="inline-form"
                      data-confirm="Approve and post this count? The system position will be changed to match what was counted.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="count_id" value="<?= (int) $c['id'] ?>">
                  <button class="btn btn--sm" type="submit">Approve</button>
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
  Whoever entered the counted quantities cannot approve the variance. That is the single most
  important control in stock management: without it, a count becomes a way to write off anything
  that has already walked out of the building.
</p>

<?php layout_foot(); ?>
