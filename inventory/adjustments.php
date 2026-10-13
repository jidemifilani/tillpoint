<?php
/**
 * Stock adjustments.
 *
 * The most dangerous document in the system: it changes stock with no physical
 * event behind it, which makes it the most direct way to make a shortage
 * disappear. So it carries the strictest controls - a mandatory reason, an
 * approver who is not the author, and an audit exception on anything large.
 *
 * Everything else that moves stock has a real-world counterpart: a delivery
 * arrived, a customer bought something, a shelf was counted. An adjustment has
 * none, which is why it is last on the menu and first in an audit.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('stock.adjust');

$storeId = current_store_id();

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');
        $adjId  = post_int('adj_id');

        if ($action === 'create') {
            $reason = (string) post('reason_code');
            if ($reason === '') {
                throw new StockError('An adjustment must have a reason code.');
            }
            $newId = db_tx(fn (): int => db_insert('stock_adjustments', [
                'adj_no'      => next_number('adjust', $storeId),
                'store_id'    => $storeId,
                'reason_code' => $reason,
                'note'        => (string) post('note') !== '' ? (string) post('note') : null,
                'status'      => 'draft',
                'created_by'  => (int) $_SESSION['uid'],
            ]));
            audit_log('adjustment.draft', 'adjustment', $newId,
                'Draft adjustment started (' . $reason . ')', null, null, 'notice');
            flash_ok('Draft adjustment started. Add the lines.');
            redirect('inventory/adjustment', ['id' => $newId]);
        }

        if ($action === 'approve') {
            require_can('stock.adjust.approve');
            $result = adjustment_post($adjId, (int) $_SESSION['uid']);
            flash_ok(sprintf('Adjustment posted: %d line(s), %s of stock value moved.',
                $result['lines'], money((float) $result['value'])));
        }

        if ($action === 'reject') {
            require_can('stock.adjust.approve');
            $note = trim((string) post('note'));
            if ($note === '') {
                throw new StockError('Say why the adjustment is being rejected.');
            }
            db_tx(function () use ($adjId, $note): void {
                $a = db_lock_row('stock_adjustments', 'id = :id', ['id' => $adjId]);
                if (!$a || $a['status'] !== 'pending') {
                    throw new StockError('Only a submitted adjustment can be rejected.');
                }
                sod_assert_different((int) $_SESSION['uid'], (int) $a['created_by'],
                    'reject this adjustment');
                db_update('stock_adjustments', [
                    'status'      => 'rejected',
                    'approved_by' => (int) $_SESSION['uid'],
                    'approved_at' => date('Y-m-d H:i:s'),
                    'note'        => substr(trim((string) ($a['note'] ?? '') . ' | Rejected: ' . $note), 0, 255),
                ], 'id = :id', ['id' => $adjId]);
                audit_log('adjustment.reject', 'adjustment', $adjId,
                    'Adjustment ' . $a['adj_no'] . ' rejected: ' . $note, null, null, 'warning');
            });
            flash_ok('Adjustment rejected. No stock moved.');
        }
    } catch (SodViolation $e) {
        flash_err($e->getMessage());
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect('inventory/adjustments');
}

$status = one_of((string) get('status'), ['open', 'all', 'draft', 'pending', 'posted', 'rejected'], 'open');
$where  = ['a.store_id = ?'];
$params = [$storeId];

if ($status === 'open') {
    $where[] = "a.status IN ('draft','pending')";
} elseif ($status !== 'all') {
    $where[]  = 'a.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);

$rows = qa(
    "SELECT a.*, cb.full_name AS created_by_name, ab.full_name AS approved_by_name,
            COALESCE(rc.label, a.reason_code) AS reason_label,
            (SELECT COUNT(*) FROM stock_adjustment_lines al WHERE al.adj_id = a.id) AS line_count,
            (SELECT COALESCE(SUM(al.qty_delta * al.unit_cost),0) FROM stock_adjustment_lines al
              WHERE al.adj_id = a.id) AS draft_value
       FROM stock_adjustments a
       JOIN users cb ON cb.id = a.created_by
       LEFT JOIN users ab ON ab.id = a.approved_by
       LEFT JOIN reason_codes rc ON rc.code = a.reason_code
      WHERE $whereSql
      ORDER BY FIELD(a.status,'pending','draft','posted','rejected'), a.created_at DESC
      LIMIT 150",
    $params
);

$posted = q1(
    'SELECT COUNT(*) AS n, COALESCE(SUM(total_value),0) AS value,
            COALESCE(SUM(CASE WHEN total_value < 0 THEN total_value ELSE 0 END),0) AS write_downs
       FROM stock_adjustments
      WHERE store_id = ? AND status = \'posted\'
        AND posted_at >= (CURDATE() - INTERVAL 90 DAY)',
    [$storeId]
);

$reasons   = qpairs('SELECT code, label FROM reason_codes WHERE applies_to = \'adjustment\' AND is_active = 1 ORDER BY label');
$threshold = setting_num('adjustment_exception_value', 15000.0);

layout_head([
    'title'    => 'Stock adjustments',
    'subtitle' => 'Changes with no physical event behind them',
]);
?>

<div class="alert alert--info">
  An adjustment changes stock without a delivery, a sale or a count behind it. Before raising one,
  check there is no unposted delivery and no transfer still in transit &mdash; those explain most
  apparent discrepancies, and adjusting them away hides a real problem rather than fixing it.
</div>

<div class="tiles">
  <?= tile('Posted, last 90 days', number_format((int) $posted['n']),
        money((float) $posted['value']) . ' net stock value') ?>
  <?= tile('Written down', money(abs((float) $posted['write_downs'])),
        'negative adjustments at cost',
        abs((float) $posted['write_downs']) > 0 ? 'bad' : 'ok') ?>
  <?= tile('Awaiting approval',
        number_format(count(array_filter($rows, fn ($r) => $r['status'] === 'pending'))),
        'submitted, not approved', 'warn') ?>
  <?= tile('Exception threshold', money($threshold),
        'above this an exception is raised', 'info') ?>
</div>

<?= card_open('Start an adjustment') ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-grid">
      <label class="field">
        <span class="field__label">Reason</span>
        <select name="reason_code" required><?= select_options($reasons, '', true, '-- reason --') ?></select>
        <span class="field__hint">Chosen from a closed list so adjustments can be counted by cause.</span>
      </label>
      <label class="field">
        <span class="field__label">Explanation</span>
        <input type="text" name="note" maxlength="255" required
               placeholder="What you checked, and what you concluded">
      </label>
    </div>
    <button class="btn" type="submit">Start draft adjustment</button>
  </form>
<?= card_close() ?>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">Status</span>
    <select name="status">
      <?= select_options(['open' => 'Open', 'all' => 'All', 'draft' => 'Draft',
                          'pending' => 'Awaiting approval', 'posted' => 'Posted',
                          'rejected' => 'Rejected'], $status) ?>
    </select>
  </label>
  <button class="btn" type="submit">Apply</button>
</form>

<?= card_open('Adjustments') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No adjustments match.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Reference</th><th>Raised</th><th>Reason</th><th>Explanation</th>
        <th class="num">Lines</th><th class="num">Value</th>
        <th>Raised by</th><th>Approved by</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $a):
          $isMine = (int) $a['created_by'] === (int) $_SESSION['uid'];
          $value  = $a['status'] === 'posted' ? (float) $a['total_value'] : (float) $a['draft_value'];
          $big    = abs($value) > $threshold;
          $selfApproved = $a['approved_by'] !== null
                          && (int) $a['approved_by'] === (int) $a['created_by']; ?>
        <tr class="<?= $selfApproved ? 'row-bad' : ($a['status'] === 'pending' ? 'row-warn' : '') ?>">
          <td class="mono tiny">
            <a href="<?= e(base_url('inventory/adjustment', ['id' => (int) $a['id']])) ?>">
              <?= e($a['adj_no']) ?></a>
          </td>
          <td class="nowrap tiny"><?= e(date_fmt((string) $a['created_at'])) ?></td>
          <td class="tiny"><?= e($a['reason_label']) ?></td>
          <td class="tiny muted"><?= e($a['note'] ?? '') ?></td>
          <td class="num"><?= (int) $a['line_count'] ?></td>
          <td class="num <?= $value < 0 ? 'neg' : '' ?>">
            <?= e(($value > 0 ? '+' : '') . money($value, false)) ?>
            <?php if ($big): ?><br><?= badge('Large', 'warn') ?><?php endif; ?>
          </td>
          <td class="tiny"><?= e($a['created_by_name']) ?></td>
          <td class="tiny">
            <?= e($a['approved_by_name'] ?? '-') ?>
            <?php if ($selfApproved): ?><br><?= badge('Self-approved', 'bad') ?><?php endif; ?>
          </td>
          <td><?= status_badge((string) $a['status']) ?></td>
          <td class="right nowrap">
            <?php if ($a['status'] === 'draft'): ?>
              <a class="btn btn--sm btn--ghost"
                 href="<?= e(base_url('inventory/adjustment', ['id' => (int) $a['id']])) ?>">Edit</a>
            <?php elseif ($a['status'] === 'pending' && can('stock.adjust.approve')): ?>
              <?php if ($isMine): ?>
                <span class="tiny bad-text" title="Segregation of duties">You raised it</span>
              <?php else: ?>
                <form method="post" class="inline-form"
                      data-confirm="Approve and post this adjustment? Stock will change with no physical event behind it.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="adj_id" value="<?= (int) $a['id'] ?>">
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

<?php layout_foot(); ?>
