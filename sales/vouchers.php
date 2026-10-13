<?php
/**
 * Gift vouchers.
 *
 * A voucher is a bearer instrument: whoever holds the code can spend it, so the
 * controls are about issue and cancellation rather than identity. Issuing one
 * creates a liability, so it is permissioned and logged; the balance is only
 * ever moved by sale_complete() redeeming it, never edited here.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('vouchers.manage');

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');

        if ($action === 'issue') {
            $value = post_num('face_value');
            $count = max(1, min(50, post_int('count', 1)));
            if ($value <= 0) {
                throw new RuntimeException('Enter the value of the voucher.');
            }
            $expires = date_or_null(post('expires_at'));

            $issued = db_tx(function () use ($value, $count, $expires): array {
                $codes = [];
                for ($i = 0; $i < $count; $i++) {
                    $code = next_number('voucher');
                    db_insert('vouchers', [
                        'code'       => $code,
                        'face_value' => $value,
                        'balance'    => $value,
                        'status'     => 'active',
                        'issued_by'  => (int) $_SESSION['uid'],
                        'expires_at' => $expires,
                    ]);
                    $codes[] = $code;
                }
                return $codes;
            });

            audit_log('voucher.issue', 'voucher', null,
                sprintf('Issued %d voucher(s) of %s each, total liability %s (%s)',
                    $count, money($value), money($value * $count), implode(', ', $issued)),
                null, ['codes' => $issued, 'value' => $value], 'notice');

            flash_ok(sprintf('%d voucher(s) issued: %s', $count, implode(', ', $issued)));
            redirect('sales/vouchers');
        }

        if ($action === 'cancel') {
            $id = post_int('id');
            $v  = q1('SELECT * FROM vouchers WHERE id = ?', [$id]);
            if (!$v) {
                throw new RuntimeException('That voucher no longer exists.');
            }
            if ($v['status'] !== 'active') {
                throw new RuntimeException('Only an active voucher can be cancelled; this one is ' . $v['status'] . '.');
            }
            $reason = trim((string) post('reason'));
            if ($reason === '') {
                throw new RuntimeException('Say why the voucher is being cancelled.');
            }
            db_update('vouchers', ['status' => 'cancelled'], 'id = :id', ['id' => $id]);

            // Cancelling writes off a liability the holder may still be carrying,
            // so it is logged at warning level with the remaining balance named.
            audit_log('voucher.cancel', 'voucher', $id,
                sprintf('Voucher %s cancelled with %s remaining: %s',
                    $v['code'], money((float) $v['balance']), $reason),
                ['status' => 'active', 'balance' => (float) $v['balance']],
                ['status' => 'cancelled'], 'warning');

            flash_ok('Voucher cancelled.');
            redirect('sales/vouchers');
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('sales/vouchers');
    }
}

// Expire anything past its date on the way in, so the list and the liability
// figure agree with what the till would actually accept.
q("UPDATE vouchers SET status = 'expired'
    WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < CURDATE()");

$status = one_of((string) get('status'), ['all', 'active', 'redeemed', 'expired', 'cancelled'], 'all');
$where  = $status === 'all' ? '1 = 1' : 'v.status = ?';
$params = $status === 'all' ? [] : [$status];

$rows = qa(
    "SELECT v.*, u.full_name AS issued_by_name,
            (SELECT COUNT(*) FROM sale_payments sp WHERE sp.method = 'voucher' AND sp.reference = v.code) AS uses
       FROM vouchers v LEFT JOIN users u ON u.id = v.issued_by
      WHERE $where ORDER BY v.issued_at DESC LIMIT 300",
    $params
);

$summary = q1(
    "SELECT COUNT(*) AS total,
            SUM(status = 'active') AS active,
            COALESCE(SUM(CASE WHEN status = 'active' THEN balance ELSE 0 END),0) AS outstanding,
            COALESCE(SUM(face_value),0) AS issued_value,
            COALESCE(SUM(face_value - balance),0) AS redeemed_value
       FROM vouchers"
);

layout_head([
    'title'    => 'Gift vouchers',
    'subtitle' => money((float) $summary['outstanding']) . ' outstanding across '
                . number_format((int) $summary['active']) . ' active vouchers',
]);
?>

<div class="tiles">
  <?= tile('Outstanding liability', money((float) $summary['outstanding']),
        'what could still be spent', 'warn') ?>
  <?= tile('Active vouchers', number_format((int) $summary['active']),
        'of ' . number_format((int) $summary['total']) . ' ever issued') ?>
  <?= tile('Face value issued', money((float) $summary['issued_value']), 'all time', 'mute') ?>
  <?= tile('Redeemed', money((float) $summary['redeemed_value']), 'spent at the tills', 'ok') ?>
</div>

<?= card_open('Issue vouchers') ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="issue">
    <div class="form-grid">
      <label class="field">
        <span class="field__label">Face value each</span>
        <input type="text" name="face_value" class="num-in" required placeholder="5000.00">
      </label>
      <label class="field">
        <span class="field__label">How many</span>
        <input type="number" name="count" class="num-in" value="1" min="1" max="50">
      </label>
      <label class="field">
        <span class="field__label">Expires (optional)</span>
        <input type="date" name="expires_at" value="<?= e(date('Y-m-d', strtotime('+1 year'))) ?>">
      </label>
    </div>
    <p class="small muted">
      Each voucher issued is money the shop owes. The codes are generated here &mdash; write them
      on the cards before handing them out, because there is no way to look up which card
      carries which code afterwards.
    </p>
    <button class="btn" type="submit">Issue</button>
  </form>
<?= card_close() ?>

<form class="filters" method="get">
  <label class="field">
    <span class="field__label">Status</span>
    <select name="status">
      <?= select_options([
          'all'       => 'All',
          'active'    => 'Active',
          'redeemed'  => 'Fully redeemed',
          'expired'   => 'Expired',
          'cancelled' => 'Cancelled',
      ], $status) ?>
    </select>
  </label>
  <button class="btn" type="submit">Apply</button>
</form>

<?= card_open('Vouchers') ?>
  <?php if (!$rows): ?>
    <?= empty_state('No vouchers match.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Code</th><th class="num">Face value</th><th class="num">Balance left</th>
        <th>Issued</th><th>By</th><th>Expires</th><th class="num">Times used</th>
        <th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $v):
          $expiringSoon = $v['expires_at'] !== null && $v['status'] === 'active'
                          && strtotime((string) $v['expires_at']) < strtotime('+30 days'); ?>
        <tr class="<?= $v['status'] === 'cancelled' ? 'row-warn' : ($expiringSoon ? 'row-warn' : '') ?>">
          <td class="mono"><?= e($v['code']) ?></td>
          <?= num_cell($v['face_value']) ?>
          <?= num_cell($v['balance']) ?>
          <td class="tiny nowrap"><?= e(date_fmt((string) $v['issued_at'])) ?></td>
          <td class="tiny"><?= e($v['issued_by_name'] ?? '-') ?></td>
          <td class="tiny nowrap">
            <?= e($v['expires_at'] !== null ? date_fmt((string) $v['expires_at']) : 'never') ?>
            <?php if ($expiringSoon): ?><br><?= badge('Soon', 'warn') ?><?php endif; ?>
          </td>
          <td class="num"><?= (int) $v['uses'] ?></td>
          <td><?= status_badge((string) $v['status']) ?></td>
          <td class="right">
            <?php if ($v['status'] === 'active'): ?>
              <form method="post" class="btn-row" style="gap:.3rem"
                    data-confirm="Cancel this voucher? The holder will not be able to spend the <?= e(money((float) $v['balance'])) ?> left on it.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                <input type="text" name="reason" placeholder="Reason" style="max-width:150px" required>
                <button class="btn btn--sm btn--danger" type="submit">Cancel</button>
              </form>
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
