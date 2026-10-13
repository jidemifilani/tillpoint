<?php
/**
 * The count sheet: enter what is on the shelf, line by line.
 *
 * The system quantity is shown, which is a deliberate choice with a trade-off.
 * Hiding it would be a purer blind count, but in practice a counter who cannot
 * see the expected figure cannot spot their own miskey either, and the variance
 * report fills up with transpositions that bury the real shortages. Showing it
 * and recording who entered each figure - with a separate person approving - is
 * the more useful control.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('count.enter');

$storeId = current_store_id();
$id      = get_int('id');

$c = q1(
    'SELECT sc.*, cat.name AS category_name, cb.full_name AS created_by_name,
            ct.full_name AS counted_by_name, ab.full_name AS approved_by_name,
            st.name AS store_name
       FROM stock_counts sc
       LEFT JOIN categories cat ON cat.id = sc.category_id
       JOIN users cb ON cb.id = sc.created_by
       JOIN stores st ON st.id = sc.store_id
       LEFT JOIN users ct ON ct.id = sc.counted_by
       LEFT JOIN users ab ON ab.id = sc.approved_by
      WHERE sc.id = ? AND sc.store_id = ?',
    [$id, $storeId]
);
if (!$c) {
    http_response_code(404);
    require APP_ROOT . '/404.php';
    exit;
}

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');

        if ($action === 'save') {
            if ($c['status'] !== 'open') {
                throw new StockError('This count has already been ' . $c['status'] . '.');
            }
            $saved = db_tx(function () use ($id): int {
                $n = 0;
                foreach ((array) ($_POST['counted'] ?? []) as $lineId => $raw) {
                    $lineId = (int) $lineId;
                    $line   = q1('SELECT * FROM stock_count_lines WHERE id = ? AND count_id = ?',
                        [$lineId, $id]);
                    if (!$line) {
                        continue;
                    }
                    $raw = is_string($raw) ? trim($raw) : '';
                    if ($raw === '') {
                        // Blank means "not counted", which is different from zero
                        // - zero is a positive finding that none were there.
                        q('UPDATE stock_count_lines SET counted_qty = NULL, variance_qty = 0,
                                  variance_value = 0 WHERE id = ?', [$lineId]);
                        continue;
                    }
                    $counted = qty_round(num($raw, 0.0));
                    if ($counted < 0) {
                        continue;
                    }
                    $variance = qty_round($counted - (float) $line['system_qty']);
                    db_update('stock_count_lines', [
                        'counted_qty'    => $counted,
                        'variance_qty'   => $variance,
                        'variance_value' => money_round($variance * (float) $line['unit_cost']),
                        'note'           => trim((string) ($_POST['note'][$lineId] ?? '')) !== ''
                            ? substr(trim((string) $_POST['note'][$lineId]), 0, 160) : null,
                    ], 'id = :id', ['id' => $lineId]);
                    $n++;
                }
                return $n;
            });
            flash_ok("Saved $saved counted line(s).");
            redirect('inventory/count', ['id' => $id]);
        }

        if ($action === 'submit') {
            $result = count_submit($id, (int) $_SESSION['uid']);
            flash_ok(sprintf('Count submitted. Variance %s units, %s at cost. '
                . 'It now needs signing off by someone other than you.',
                qty_fmt((float) $result['variance_qty']), money((float) $result['variance_value'])));
            redirect('inventory/count', ['id' => $id]);
        }

        if ($action === 'approve') {
            require_can('count.approve');
            $result = count_post($id, (int) $_SESSION['uid']);
            flash_ok(sprintf('Posted: %d line(s) adjusted, %s at cost.',
                $result['lines_posted'], money((float) $result['variance_value'])));
            redirect('inventory/count', ['id' => $id]);
        }
    } catch (SodViolation $e) {
        flash_err($e->getMessage());
        redirect('inventory/count', ['id' => $id]);
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('inventory/count', ['id' => $id]);
    }
}

$onlyVariance = (string) get('variance') === '1';
$search       = (string) get('q');

$where  = ['scl.count_id = ?'];
$params = [$id];
if ($onlyVariance) {
    $where[] = 'ABS(scl.variance_qty) > 0.0005';
}
if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like);
}
$whereSql = implode(' AND ', $where);

$lines = qa(
    "SELECT scl.*, p.sku, p.name, p.unit_code, p.barcode
       FROM stock_count_lines scl JOIN products p ON p.id = scl.product_id
      WHERE $whereSql ORDER BY p.name",
    $params
);

$stats = q1(
    'SELECT COUNT(*) AS total,
            SUM(counted_qty IS NOT NULL) AS counted,
            SUM(ABS(variance_qty) > 0.0005) AS variances,
            COALESCE(SUM(variance_value),0) AS net_value,
            COALESCE(SUM(CASE WHEN variance_value < 0 THEN variance_value ELSE 0 END),0) AS shortfall,
            COALESCE(SUM(CASE WHEN variance_value > 0 THEN variance_value ELSE 0 END),0) AS surplus
       FROM stock_count_lines WHERE count_id = ?',
    [$id]
);

$isOpen    = $c['status'] === 'open';
$isCounted = $c['status'] === 'counted';
$countedBy = $c['counted_by'] !== null ? (int) $c['counted_by'] : null;
$isMine    = $countedBy !== null && $countedBy === (int) $_SESSION['uid'];

$actions = '';
if ($isCounted && can('count.approve') && !$isMine) {
    $actions .= '<form method="post" class="inline-form" '
        . 'data-confirm="Approve and post this count? The system position changes to match what was counted.">'
        . csrf_field() . '<input type="hidden" name="action" value="approve">'
        . '<button class="btn" type="submit">Approve and post</button></form>';
}
$actions .= '<button class="btn btn--ghost" data-print>Print sheet</button>';

layout_head([
    'title'    => 'Count ' . $c['count_no'],
    'subtitle' => ucfirst((string) $c['count_type']) . ' count - '
                . ($c['category_name'] ?? 'all products') . ' at ' . $c['store_name'],
    'actions'  => $actions,
]);
?>

<?php if ($isCounted): ?>
  <div class="alert alert--<?= $isMine ? 'warning' : 'info' ?>">
    Submitted by <?= e($c['counted_by_name'] ?? 'unknown') ?> on
    <?= e(date_fmt((string) $c['counted_at'], true)) ?>, with a variance of
    <strong><?= e(money((float) $c['variance_value'])) ?></strong>.
    <?php if ($isMine): ?>
      You entered these counts, so you cannot approve them. Someone else has to sign the variance
      off &mdash; otherwise a count is just a way of writing off whatever is missing.
    <?php else: ?>
      Nothing has been adjusted yet. Approving it will change the system position to match.
    <?php endif; ?>
  </div>
<?php elseif ($c['status'] === 'posted'): ?>
  <div class="alert alert--success">
    Approved by <?= e($c['approved_by_name'] ?? 'unknown') ?> and posted on
    <?= e(date_fmt((string) $c['posted_at'], true)) ?>. The variances are in the stock ledger as
    <code>count_variance</code> movements.
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Lines counted', number_format((int) $stats['counted']) . ' / ' . number_format((int) $stats['total']),
        $isOpen ? 'blank means not counted' : 'on this sheet') ?>
  <?= tile('Lines with a variance', number_format((int) $stats['variances']),
        'differ from the system',
        (int) $stats['variances'] > 0 ? 'warn' : 'ok') ?>
  <?= tile('Shortfall', money(abs((float) $stats['shortfall'])), 'less on the shelf than expected',
        abs((float) $stats['shortfall']) > 0 ? 'bad' : 'ok') ?>
  <?= tile('Surplus', money((float) $stats['surplus']), 'more on the shelf than expected', 'info') ?>
  <?= tile('Net variance', money((float) $stats['net_value']), 'at weighted average cost',
        (float) $stats['net_value'] < 0 ? 'bad' : 'ok') ?>
</div>

<form class="filters" method="get">
  <input type="hidden" name="id" value="<?= $id ?>">
  <label class="field" style="flex:1">
    <span class="field__label">Find a product</span>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name or SKU">
  </label>
  <label class="check" style="margin-bottom:0">
    <input type="checkbox" name="variance" value="1" <?= $onlyVariance ? 'checked' : '' ?>>
    Only lines with a variance
  </label>
  <button class="btn" type="submit">Apply</button>
  <a class="btn btn--ghost" href="<?= e(base_url('inventory/count', ['id' => $id])) ?>">Reset</a>
</form>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <?= card_open('Count sheet',
        $isOpen ? '<span class="tiny muted">Variance updates as you type</span>' : '') ?>
    <?php if (!$lines): ?>
      <?= empty_state('No lines match.') ?>
    <?php else: ?>
      <div class="table-wrap">
      <table class="data">
        <thead><tr>
          <th>SKU</th><th>Product</th><th class="num">System says</th>
          <th class="num">Counted</th><th class="num">Variance</th><th class="num">Value</th>
          <th>Note</th>
        </tr></thead>
        <tbody>
        <?php foreach ($lines as $l):
            $system  = (float) $l['system_qty'];
            $counted = $l['counted_qty'] !== null ? (float) $l['counted_qty'] : null;
            $var     = (float) $l['variance_qty'];
            $varVal  = (float) $l['variance_value']; ?>
          <tr data-system="<?= e(number_format($system, 3, '.', '')) ?>"
              data-cost="<?= e(number_format((float) $l['unit_cost'], 4, '.', '')) ?>"
              class="<?= $counted !== null && abs($var) > 0.0005 ? ($var < 0 ? 'row-bad' : 'row-warn') : '' ?>">
            <td class="mono tiny"><?= e($l['sku']) ?></td>
            <td>
              <?= e($l['name']) ?>
              <?php if ($l['barcode'] !== null): ?>
                <br><span class="tiny muted mono"><?= e((string) $l['barcode']) ?></span>
              <?php endif; ?>
            </td>
            <td class="num"><?= e(qty_fmt($system)) ?>
              <span class="tiny muted"><?= e($l['unit_code']) ?></span></td>
            <td class="num">
              <?php if ($isOpen): ?>
                <input type="text" name="counted[<?= (int) $l['id'] ?>]" class="num-in"
                       style="max-width:92px" inputmode="decimal" data-counted
                       value="<?= e($counted !== null ? qty_fmt($counted) : '') ?>"
                       placeholder="-">
              <?php else: ?>
                <?= e($counted !== null ? qty_fmt($counted) : 'not counted') ?>
              <?php endif; ?>
            </td>
            <td class="num <?= $var < 0 ? 'neg' : '' ?>" data-var-qty>
              <?= e($counted !== null ? (($var > 0 ? '+' : '') . qty_fmt($var)) : '-') ?>
            </td>
            <td class="num <?= $varVal < 0 ? 'neg' : '' ?>" data-var-val>
              <?= e($counted !== null ? (($varVal > 0 ? '+' : '') . money($varVal, false)) : '-') ?>
            </td>
            <td>
              <?php if ($isOpen): ?>
                <input type="text" name="note[<?= (int) $l['id'] ?>]" maxlength="160"
                       style="max-width:190px" value="<?= e((string) ($l['note'] ?? '')) ?>"
                       placeholder="Why it differs">
              <?php else: ?>
                <span class="tiny muted"><?= e((string) ($l['note'] ?? '')) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <?php if ($isOpen): ?>
        <div class="btn-row mt1 no-print">
          <button class="btn" type="submit">Save counts</button>
          <button class="btn btn--warn" type="submit" name="action" value="submit"
                  formnovalidate
                  onclick="return confirm('Submit this count for approval? You will not be able to change the figures afterwards.')">
            Submit for approval
          </button>
        </div>
        <p class="small muted mt1">
          Leave a box blank for a line you did not count &mdash; that is different from entering
          zero, which is a positive finding that none were there. A full count must have every
          line entered.
        </p>
      <?php endif; ?>
    <?php endif; ?>
  <?= card_close() ?>
</form>

<?php if ($c['status'] === 'posted'): ?>
  <div class="sign-off">
    <div>Counted by: <?= e($c['counted_by_name'] ?? '') ?> on <?= e(date_fmt((string) $c['counted_at'])) ?></div>
    <div>Approved by: <?= e($c['approved_by_name'] ?? '') ?> on <?= e(date_fmt((string) $c['approved_at'])) ?></div>
  </div>
<?php endif; ?>

<?php layout_foot(); ?>
