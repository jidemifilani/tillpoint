<?php
/**
 * One stock adjustment: build the lines, submit, approve.
 *
 * Each line shows the current position and what the adjustment would make it,
 * so whoever approves can see the consequence rather than a bare delta. An
 * approver reading "+40" has no idea whether that is reasonable; one reading
 * "12 becomes 52" does.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('stock.adjust');

$storeId = current_store_id();
$id      = get_int('id');

if ($id === 0) {
    redirect('inventory/adjustments');
}

$a = q1(
    'SELECT a.*, cb.full_name AS created_by_name, ab.full_name AS approved_by_name,
            COALESCE(rc.label, a.reason_code) AS reason_label, st.name AS store_name
       FROM stock_adjustments a
       JOIN users cb ON cb.id = a.created_by
       JOIN stores st ON st.id = a.store_id
       LEFT JOIN users ab ON ab.id = a.approved_by
       LEFT JOIN reason_codes rc ON rc.code = a.reason_code
      WHERE a.id = ? AND a.store_id = ?',
    [$id, $storeId]
);
if (!$a) {
    http_response_code(404);
    require APP_ROOT . '/404.php';
    exit;
}

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');

        if ($action === 'add_line') {
            if ($a['status'] !== 'draft') {
                throw new StockError('Lines can only be changed while the adjustment is a draft.');
            }
            $productId = post_int('product_id');
            $delta     = post_num('qty_delta');
            if ($productId === 0 || abs($delta) < 0.0005) {
                throw new StockError('Choose a product and a quantity to add or remove.');
            }
            $onHand = stock_on_hand($productId, $storeId);
            if ($onHand + $delta < 0) {
                throw new StockError(sprintf(
                    'That would take %s to %s. Adjust to zero at most, or check for an unposted delivery.',
                    (string) qv('SELECT name FROM products WHERE id = ?', [$productId], ''),
                    qty_fmt($onHand + $delta)
                ));
            }

            db_tx(function () use ($id, $productId, $delta, $storeId): void {
                $cost     = stock_avg_cost($productId, $storeId);
                $existing = q1('SELECT * FROM stock_adjustment_lines WHERE adj_id = ? AND product_id = ?',
                    [$id, $productId]);
                if ($existing) {
                    q('UPDATE stock_adjustment_lines SET qty_delta = qty_delta + ?, unit_cost = ? WHERE id = ?',
                      [$delta, $cost, (int) $existing['id']]);
                } else {
                    db_insert('stock_adjustment_lines', [
                        'adj_id'     => $id,
                        'product_id' => $productId,
                        'qty_delta'  => $delta,
                        'unit_cost'  => $cost,
                        'note'       => (string) post('line_note') !== ''
                            ? substr((string) post('line_note'), 0, 160) : null,
                    ]);
                }
            });
            flash_ok('Line added.');
            redirect('inventory/adjustment', ['id' => $id]);
        }

        if ($action === 'remove_line') {
            if ($a['status'] !== 'draft') {
                throw new StockError('Lines can only be changed while the adjustment is a draft.');
            }
            q('DELETE FROM stock_adjustment_lines WHERE id = ? AND adj_id = ?',
              [post_int('line_id'), $id]);
            flash_ok('Line removed.');
            redirect('inventory/adjustment', ['id' => $id]);
        }

        if ($action === 'submit') {
            if ($a['status'] !== 'draft') {
                throw new StockError('This adjustment is already ' . $a['status'] . '.');
            }
            $n = (int) qv('SELECT COUNT(*) FROM stock_adjustment_lines WHERE adj_id = ?', [$id], 0);
            if ($n === 0) {
                throw new StockError('Add at least one line before submitting.');
            }
            db_tx(function () use ($id, $a, $n): void {
                db_update('stock_adjustments', ['status' => 'pending'], 'id = :id', ['id' => $id]);
                $value = (float) qv('SELECT COALESCE(SUM(qty_delta * unit_cost),0)
                                       FROM stock_adjustment_lines WHERE adj_id = ?', [$id], 0.0);
                audit_log('adjustment.submit', 'adjustment', $id,
                    sprintf('Adjustment %s submitted for approval: %d line(s), %s',
                        $a['adj_no'], $n, money($value)),
                    null, null, 'notice');
                notify_role('manager', 'warning', 'Stock adjustment awaiting approval',
                    sprintf('%s: %s across %d line(s) (%s).',
                        $a['adj_no'], money($value), $n, $a['reason_code']),
                    base_url('inventory/adjustment', ['id' => $id]));
            });
            flash_ok('Submitted for approval. Someone other than you must approve it.');
            redirect('inventory/adjustment', ['id' => $id]);
        }

        if ($action === 'approve') {
            require_can('stock.adjust.approve');
            $result = adjustment_post($id, (int) $_SESSION['uid']);
            flash_ok(sprintf('Posted: %d line(s), %s of stock value moved.',
                $result['lines'], money((float) $result['value'])));
            redirect('inventory/adjustment', ['id' => $id]);
        }

        if ($action === 'delete_draft') {
            if ($a['status'] !== 'draft') {
                throw new StockError('Only a draft can be discarded.');
            }
            db_tx(function () use ($id, $a): void {
                q('DELETE FROM stock_adjustment_lines WHERE adj_id = ?', [$id]);
                q('DELETE FROM stock_adjustments WHERE id = ?', [$id]);
                audit_log('adjustment.discard', 'adjustment', $id,
                    'Draft adjustment ' . $a['adj_no'] . ' discarded before submission',
                    null, null, 'notice');
            });
            flash_ok('Draft discarded.');
            redirect('inventory/adjustments');
        }
    } catch (SodViolation $e) {
        flash_err($e->getMessage());
        redirect('inventory/adjustment', ['id' => $id]);
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('inventory/adjustment', ['id' => $id]);
    }
}

$lines = qa(
    'SELECT al.*, p.sku, p.name, p.unit_code,
            COALESCE(sl.qty_on_hand,0) AS qty_on_hand
       FROM stock_adjustment_lines al
       JOIN products p ON p.id = al.product_id
       LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
      WHERE al.adj_id = ? ORDER BY p.name',
    [$storeId, $id]
);
$movements = $a['status'] === 'posted'
    ? qa('SELECT sm.*, p.name FROM stock_movements sm JOIN products p ON p.id = sm.product_id
           WHERE sm.ref_type = \'adjustment\' AND sm.ref_id = ? ORDER BY sm.id', [$id])
    : [];

$isDraft = $a['status'] === 'draft';
$isMine  = (int) $a['created_by'] === (int) $_SESSION['uid'];
$value   = array_sum(array_map(fn ($l) => (float) $l['qty_delta'] * (float) $l['unit_cost'], $lines));
$threshold = setting_num('adjustment_exception_value', 15000.0);

$products = $isDraft
    ? qpairs('SELECT p.id, CONCAT(p.sku, " - ", p.name, " (", FORMAT(COALESCE(sl.qty_on_hand,0), 0), " on hand)")
                FROM products p
                LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
               WHERE p.is_active = 1 ORDER BY p.name', [$storeId])
    : [];

$actions = '';
if ($isDraft && $lines) {
    $actions .= '<form method="post" class="inline-form" '
        . 'data-confirm="Submit this adjustment for approval?">'
        . csrf_field() . '<input type="hidden" name="action" value="submit">'
        . '<button class="btn" type="submit">Submit for approval</button></form>';
}
if ($a['status'] === 'pending' && can('stock.adjust.approve') && !$isMine) {
    $actions .= '<form method="post" class="inline-form" '
        . 'data-confirm="Approve and post this adjustment? Stock changes with no physical event behind it.">'
        . csrf_field() . '<input type="hidden" name="action" value="approve">'
        . '<button class="btn" type="submit">Approve and post</button></form>';
}
$actions .= '<button class="btn btn--ghost" data-print>Print</button>';

layout_head([
    'title'    => 'Adjustment ' . $a['adj_no'],
    'subtitle' => $a['reason_label'] . ' - ' . $a['store_name'],
    'actions'  => $actions,
]);
?>

<?php if ($a['status'] === 'pending'): ?>
  <div class="alert alert--<?= $isMine ? 'warning' : 'info' ?>">
    <?php if ($isMine): ?>
      Awaiting approval. You raised this, so you cannot approve it &mdash; an adjustment one person
      can both raise and approve is a way to make any shortage disappear.
    <?php else: ?>
      Awaiting approval. Raised by <?= e($a['created_by_name']) ?>.
      Check there is no unposted delivery or in-transit transfer that would explain the
      discrepancy before approving.
    <?php endif; ?>
  </div>
<?php elseif ($a['status'] === 'posted'): ?>
  <div class="alert alert--success">
    Posted by <?= e($a['approved_by_name'] ?? 'unknown') ?> on
    <?= e(date_fmt((string) $a['posted_at'], true)) ?>.
    <?php if (abs((float) $a['total_value']) > $threshold): ?>
      It exceeded <?= e(money($threshold)) ?>, so an audit exception was raised for review.
    <?php endif; ?>
  </div>
<?php elseif ($a['status'] === 'rejected'): ?>
  <div class="alert alert--error">
    Rejected by <?= e($a['approved_by_name'] ?? 'unknown') ?>. No stock moved.
  </div>
<?php endif; ?>

<div class="tiles">
  <?= tile('Lines', number_format(count($lines)), 'products affected') ?>
  <?= tile('Stock value moved', money($value),
        $value < 0 ? 'written down' : 'written up',
        abs($value) > $threshold ? 'bad' : ($value < 0 ? 'warn' : 'info')) ?>
  <?= tile('Reason', (string) $a['reason_code'], (string) $a['reason_label'], 'mute') ?>
  <?= tile('Status', ucfirst((string) $a['status']),
        'raised by ' . $a['created_by_name'],
        $a['status'] === 'posted' ? 'ok' : ($a['status'] === 'rejected' ? 'bad' : 'warn')) ?>
</div>

<?= card_open('Explanation') ?>
  <dl class="kv">
    <dt>Reason code</dt><dd><?= e((string) $a['reason_code']) ?> &mdash; <?= e($a['reason_label']) ?></dd>
    <dt>Explanation</dt><dd><?= e($a['note'] ?? '(none given)') ?></dd>
    <dt>Raised</dt><dd><?= e($a['created_by_name']) ?>,
      <?= e(date_fmt((string) $a['created_at'], true)) ?></dd>
    <?php if ($a['approved_at'] !== null): ?>
      <dt><?= $a['status'] === 'rejected' ? 'Rejected' : 'Approved' ?></dt>
      <dd><?= e($a['approved_by_name'] ?? '-') ?>,
        <?= e(date_fmt((string) $a['approved_at'], true)) ?></dd>
    <?php endif; ?>
  </dl>
<?= card_close() ?>

<?= card_open('Lines') ?>
  <?php if (!$lines): ?>
    <?= empty_state('No lines yet.', $isDraft ? 'Add the first one below.' : '') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>SKU</th><th>Product</th><th class="num">On hand now</th>
        <th class="num">Adjustment</th><th class="num">Would become</th>
        <th class="num">Unit cost</th><th class="num">Value</th><th>Note</th>
        <?= $isDraft ? '<th></th>' : '' ?>
      </tr></thead>
      <tbody>
      <?php foreach ($lines as $l):
          $delta  = (float) $l['qty_delta'];
          $onHand = (float) $l['qty_on_hand'];
          $lineVal = $delta * (float) $l['unit_cost']; ?>
        <tr class="<?= $delta < 0 ? 'row-warn' : '' ?>">
          <td class="mono tiny"><?= e($l['sku']) ?></td>
          <td>
            <a href="<?= e(base_url('inventory/product', ['id' => (int) $l['product_id']])) ?>">
              <?= e($l['name']) ?></a>
          </td>
          <td class="num"><?= e(qty_fmt($onHand)) ?>
            <span class="tiny muted"><?= e($l['unit_code']) ?></span></td>
          <?= num_cell($delta, false, true) ?>
          <td class="num"><strong><?= e(qty_fmt($a['status'] === 'posted' ? $onHand : $onHand + $delta)) ?></strong></td>
          <?= num_cell($l['unit_cost']) ?>
          <?= num_cell($lineVal, true, true) ?>
          <td class="tiny muted"><?= e($l['note'] ?? '') ?></td>
          <?php if ($isDraft): ?>
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
      <tfoot>
        <tr>
          <td colspan="6">Net stock value moved</td>
          <td class="num <?= $value < 0 ? 'neg' : '' ?>">
            <?= e(($value > 0 ? '+' : '') . money($value, false)) ?>
          </td>
          <td></td><?= $isDraft ? '<td></td>' : '' ?>
        </tr>
      </tfoot>
    </table>
    </div>
  <?php endif; ?>

  <?php if ($isDraft): ?>
    <form method="post" class="mt1 no-print">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_line">
      <div class="form-grid">
        <label class="field">
          <span class="field__label">Product</span>
          <select name="product_id" required><?= select_options($products, '', true, '-- choose --') ?></select>
        </label>
        <label class="field">
          <span class="field__label">Adjustment</span>
          <input type="text" name="qty_delta" class="num-in" required inputmode="decimal"
                 placeholder="e.g. 5 or -5">
          <span class="field__hint">Negative takes stock out, positive puts it in.</span>
        </label>
        <label class="field">
          <span class="field__label">Line note</span>
          <input type="text" name="line_note" maxlength="160">
        </label>
      </div>
      <button class="btn" type="submit">Add line</button>
    </form>
  <?php endif; ?>
<?= card_close() ?>

<?php if ($movements): ?>
<?= card_open('Ledger movements this created') ?>
  <table class="data data--tight">
    <thead><tr>
      <th>#</th><th>Product</th><th class="num">Quantity</th>
      <th class="num">Unit cost</th><th class="num">Balance after</th><th>When</th>
    </tr></thead>
    <tbody>
    <?php foreach ($movements as $m): ?>
      <tr>
        <td class="mono tiny"><?= (int) $m['id'] ?></td>
        <td><?= e($m['name']) ?></td>
        <?= num_cell($m['qty_delta'], false, true) ?>
        <?= num_cell($m['unit_cost']) ?>
        <td class="num"><?= e(qty_fmt((float) $m['balance_after'])) ?></td>
        <td class="nowrap tiny"><?= e(date_fmt((string) $m['created_at'], true)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?= card_close() ?>
<?php endif; ?>

<?php if ($isDraft): ?>
  <?= card_open('Discard this draft') ?>
    <form method="post" data-confirm="Discard this draft adjustment? Nothing has been posted.">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete_draft">
      <button class="btn btn--danger" type="submit">Discard draft</button>
    </form>
    <p class="small muted mt1">
      Only a draft can be discarded. Once submitted it stays on the record whatever the outcome,
      so a rejected adjustment is still evidence that somebody asked.
    </p>
  <?= card_close() ?>
<?php endif; ?>

<?php if ($a['status'] === 'pending' && can('stock.adjust.approve') && !$isMine): ?>
  <?= card_open('Reject instead') ?>
    <form method="post" class="btn-row">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="adj_id" value="<?= $id ?>">
      <input type="text" name="note" placeholder="Why it is being rejected"
             style="max-width:340px" required>
      <button class="btn btn--danger" type="submit">Reject</button>
    </form>
  <?= card_close() ?>
<?php endif; ?>

<?php layout_foot(); ?>
