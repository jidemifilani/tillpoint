<?php
/**
 * Wastage.
 *
 * The easiest route for stock to leave a supermarket with paperwork that looks
 * correct, so the controls are: a mandatory reason code from a closed list, the
 * person who logged it recorded by name, an approval threshold above which
 * someone else must agree, and a per-person daily total that the audit scan
 * compares against the shop.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('wastage.log');

$storeId = current_store_id();

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');

        if ($action === 'log') {
            $result = wastage_log([
                'product_id'  => post_int('product_id'),
                'store_id'    => $storeId,
                'qty'         => post_num('qty'),
                'user_id'     => (int) $_SESSION['uid'],
                'reason_code' => (string) post('reason_code'),
                'note'        => (string) post('note'),
            ]);
            if ($result['posted']) {
                flash_ok(sprintf('%s written off, %s at cost.',
                    $result['waste_no'], money((float) $result['value'])));
            } else {
                flash_warn(sprintf('%s raised for %s and is waiting for a manager. '
                    . 'The stock stays on the books until it is approved.',
                    $result['waste_no'], money((float) $result['value'])));
            }
        }

        if ($action === 'approve') {
            require_can('wastage.approve');
            wastage_approve(post_int('waste_id'), (int) $_SESSION['uid']);
            flash_ok('Write-off approved and posted. The stock is out.');
        }

        if ($action === 'reject') {
            require_can('wastage.approve');
            $note = trim((string) post('note'));
            if ($note === '') {
                throw new StockError('Say why the write-off is being rejected.');
            }
            wastage_reject(post_int('waste_id'), (int) $_SESSION['uid'], $note);
            flash_ok('Write-off rejected. The stock stays on the books.');
        }
    } catch (SodViolation $e) {
        flash_err($e->getMessage());
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect('inventory/wastage');
}

$pending = qa(
    'SELECT w.*, p.name AS product_name, p.sku, p.unit_code,
            u.full_name AS logged_by_name,
            COALESCE(rc.label, w.reason_code) AS reason_label,
            b.batch_no, b.expiry_date
       FROM wastage w
       JOIN products p ON p.id = w.product_id
       JOIN users u ON u.id = w.logged_by
       LEFT JOIN reason_codes rc ON rc.code = w.reason_code
       LEFT JOIN stock_batches b ON b.id = w.batch_id
      WHERE w.store_id = ? AND w.status = \'pending\'
      ORDER BY w.total_value DESC',
    [$storeId]
);

$from = date_or_null(get('from')) ?? date('Y-m-d', strtotime('-30 days'));
$to   = date_or_null(get('to')) ?? date('Y-m-d');

$history = qa(
    'SELECT w.*, p.name AS product_name, p.sku, p.unit_code,
            u.full_name AS logged_by_name, a.full_name AS approved_by_name,
            COALESCE(rc.label, w.reason_code) AS reason_label
       FROM wastage w
       JOIN products p ON p.id = w.product_id
       JOIN users u ON u.id = w.logged_by
       LEFT JOIN users a ON a.id = w.approved_by
       LEFT JOIN reason_codes rc ON rc.code = w.reason_code
      WHERE w.store_id = ? AND w.status <> \'pending\'
        AND DATE(w.created_at) BETWEEN ? AND ?
      ORDER BY w.created_at DESC LIMIT 300',
    [$storeId, $from, $to]
);

$byReason = report_wastage_by_reason($storeId, $from, $to);
$byPerson = qa(
    'SELECT u.id, u.full_name, COUNT(*) AS records,
            COALESCE(SUM(w.total_value),0) AS value
       FROM wastage w JOIN users u ON u.id = w.logged_by
      WHERE w.store_id = ? AND DATE(w.created_at) BETWEEN ? AND ? AND w.status = \'posted\'
      GROUP BY u.id, u.full_name ORDER BY value DESC',
    [$storeId, $from, $to]
);

$summary = q1(
    'SELECT COUNT(*) AS n, COALESCE(SUM(total_value),0) AS value
       FROM wastage WHERE store_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = \'posted\'',
    [$storeId, $from, $to]
);
$costOfSales = (float) qv(
    'SELECT COALESCE(SUM(cost_total),0) FROM sales
      WHERE store_id = ? AND business_date BETWEEN ? AND ?
        AND status IN (\'completed\',\'refunded\',\'partly_refunded\')',
    [$storeId, $from, $to], 0.0
);

$products = qpairs(
    'SELECT p.id, CONCAT(p.sku, " - ", p.name, " (", FORMAT(COALESCE(sl.qty_on_hand,0), 0), " on hand)")
       FROM products p
       LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
      WHERE p.is_active = 1 AND COALESCE(sl.qty_on_hand,0) > 0
      ORDER BY p.name', [$storeId]
);
$reasons   = qpairs('SELECT code, label FROM reason_codes WHERE applies_to = \'wastage\' AND is_active = 1 ORDER BY label');
$limit     = setting_num('wastage_approval_limit', (float) WASTAGE_APPROVAL_LIMIT);
$personCap = audit_rule_threshold('EXCESSIVE_WASTAGE', 15000.0);

layout_head([
    'title'    => 'Wastage',
    'subtitle' => count($pending) > 0
        ? count($pending) . ' write-off(s) awaiting approval'
        : money((float) $summary['value']) . ' written off in the selected period',
]);
?>

<div class="tiles">
  <?= tile('Written off', money((float) $summary['value']),
        number_format((int) $summary['n']) . ' records in the period', 'warn') ?>
  <?= tile('As % of cost of sales',
        $costOfSales > 0 ? pct_fmt(((float) $summary['value'] / $costOfSales) * 100) : '-',
        'the figure to watch over time',
        $costOfSales > 0 && ((float) $summary['value'] / $costOfSales) * 100 > 3 ? 'bad' : 'ok') ?>
  <?= tile('Awaiting approval', number_format(count($pending)),
        'above ' . money($limit) . ' at cost',
        count($pending) > 0 ? 'warn' : 'ok') ?>
  <?= tile('Approval threshold', money($limit), 'above this, someone else must agree', 'info') ?>
</div>

<!-- ---- Pending approvals ----------------------------------- -->
<?php if ($pending): ?>
<?= card_open('Awaiting approval (' . count($pending) . ')') ?>
  <?php foreach ($pending as $w):
      $isMine = (int) $w['logged_by'] === (int) $_SESSION['uid']; ?>
    <div class="exc exc--<?= (float) $w['total_value'] > $limit * 3 ? 'high' : 'medium' ?>">
      <h3>
        <?= e($w['waste_no']) ?> &mdash; <?= e(money((float) $w['total_value'])) ?>
        <?= badge($w['reason_label'], 'warn') ?>
      </h3>
      <p>
        <?= e(qty_fmt((float) $w['qty'])) ?> <?= e($w['unit_code']) ?>
        of <strong><?= e($w['product_name']) ?></strong>
        at <?= e(money((float) $w['unit_cost'])) ?> each
        <?php if ($w['batch_no'] !== null): ?>
          &mdash; batch <?= e((string) $w['batch_no']) ?>
          <?php if ($w['expiry_date'] !== null): ?>
            (expires <?= e(date_fmt((string) $w['expiry_date'])) ?>)
          <?php endif; ?>
        <?php endif; ?>
      </p>
      <?php if ($w['note']): ?><p><?= e((string) $w['note']) ?></p><?php endif; ?>
      <div class="exc__meta">
        <span>Raised by <?= e($w['logged_by_name']) ?></span>
        <span><?= e(ago((string) $w['created_at'])) ?></span>
      </div>

      <?php if (!can('wastage.approve')): ?>
        <p class="small muted">Your role cannot approve write-offs.</p>
      <?php elseif ($isMine): ?>
        <div class="alert alert--warning mb0" style="margin-top:.5rem">
          You raised this write-off, so you cannot approve it.
        </div>
      <?php else: ?>
        <div class="btn-row" style="margin-top:.5rem">
          <form method="post" class="inline-form"
                data-confirm="Approve this write-off? <?= e(qty_fmt((float) $w['qty'])) ?> will come out of stock.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="waste_id" value="<?= (int) $w['id'] ?>">
            <button class="btn btn--sm" type="submit">Approve</button>
          </form>
          <form method="post" class="btn-row" style="gap:.3rem">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="waste_id" value="<?= (int) $w['id'] ?>">
            <input type="text" name="note" placeholder="Why it is rejected" style="max-width:260px" required>
            <button class="btn btn--sm btn--danger" type="submit">Reject</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?= card_close() ?>
<?php endif; ?>

<!-- ---- Log one ---------------------------------------------- -->
<?= card_open('Write something off') ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="log">
    <div class="form-grid">
      <label class="field">
        <span class="field__label">Product</span>
        <select name="product_id" required><?= select_options($products, '', true, '-- choose --') ?></select>
      </label>
      <label class="field">
        <span class="field__label">Quantity</span>
        <input type="text" name="qty" class="num-in" required inputmode="decimal">
      </label>
      <label class="field">
        <span class="field__label">Reason</span>
        <select name="reason_code" required><?= select_options($reasons, '', true, '-- reason --') ?></select>
      </label>
      <label class="field">
        <span class="field__label">Note</span>
        <input type="text" name="note" maxlength="255" placeholder="What happened">
      </label>
    </div>
    <p class="small muted">
      Below <?= e(money($limit)) ?> at cost this posts immediately &mdash; a cashier binning one
      broken jar should not need a manager. Above it, a manager who is not you has to agree.
      To write off a specific batch, use the
      <a href="<?= e(base_url('inventory/batches')) ?>">batch list</a> instead, so the expiry
      figures stay right.
    </p>
    <button class="btn" type="submit">Write off</button>
  </form>
<?= card_close() ?>

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
</form>

<div class="grid-2">
  <?= card_open('Why stock was written off') ?>
    <?php if (!$byReason): ?>
      <?= empty_state('Nothing written off in that period.') ?>
    <?php else:
        $maxVal = max(array_map(fn ($r) => (float) $r['value'], $byReason)) ?: 1.0; ?>
      <table class="data data--tight">
        <thead><tr><th>Reason</th><th class="num">Records</th><th class="num">Units</th>
                   <th class="num">Value</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($byReason as $r): ?>
          <tr>
            <td><?= e($r['label']) ?></td>
            <td class="num"><?= (int) $r['records'] ?></td>
            <td class="num"><?= e(qty_fmt((float) $r['units'])) ?></td>
            <?= num_cell($r['value']) ?>
            <td><div class="bar bar--warn" style="max-width:100px">
              <span style="width:<?= (int) round(((float) $r['value'] / $maxVal) * 100) ?>%"></span>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?= card_close() ?>

  <?= card_open('Who wrote it off',
        '<span class="tiny muted">Flagged above ' . e(money($personCap)) . ' a day</span>') ?>
    <?php if (!$byPerson): ?>
      <?= empty_state('No posted write-offs in that period.') ?>
    <?php else: ?>
      <table class="data data--tight">
        <thead><tr><th>Person</th><th class="num">Records</th><th class="num">Value</th></tr></thead>
        <tbody>
        <?php foreach ($byPerson as $p): ?>
          <tr class="<?= (float) $p['value'] > $personCap ? 'row-warn' : '' ?>">
            <td><?= e($p['full_name']) ?></td>
            <td class="num"><?= (int) $p['records'] ?></td>
            <?= num_cell($p['value']) ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="tiny muted mt1">
        Write-offs concentrated on one person are worth a look. The comparison, not the absolute
        figure, is the signal &mdash; whoever works the chiller will always be top of this list.
      </p>
    <?php endif; ?>
  <?= card_close() ?>
</div>

<?= card_open('Write-off history') ?>
  <?php if (!$history): ?>
    <?= empty_state('Nothing in that period.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>Reference</th><th>When</th><th>Product</th><th class="num">Qty</th>
        <th class="num">Value</th><th>Reason</th><th>Raised by</th><th>Approved by</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($history as $w):
          $selfApproved = $w['approved_by'] !== null
                          && (int) $w['approved_by'] === (int) $w['logged_by']; ?>
        <tr class="<?= $selfApproved ? 'row-bad' : '' ?>">
          <td class="mono tiny"><?= e($w['waste_no']) ?></td>
          <td class="nowrap tiny"><?= e(date_fmt((string) $w['created_at'], true)) ?></td>
          <td><?= e($w['product_name']) ?></td>
          <td class="num"><?= e(qty_fmt((float) $w['qty'])) ?>
            <span class="tiny muted"><?= e($w['unit_code']) ?></span></td>
          <?= num_cell($w['total_value']) ?>
          <td class="tiny"><?= e($w['reason_label']) ?></td>
          <td class="tiny"><?= e($w['logged_by_name']) ?></td>
          <td class="tiny">
            <?= e($w['approved_by_name'] ?? '-') ?>
            <?php if ($selfApproved): ?><br><?= badge('Self-approved', 'bad') ?><?php endif; ?>
          </td>
          <td><?= status_badge((string) $w['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?php layout_foot(); ?>
