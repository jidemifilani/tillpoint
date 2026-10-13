<?php
/**
 * The cash drawer: pay-ins, pay-outs, safe drops and no-sale opens.
 *
 * Pay-outs and drops take money out of a till, so both need a supervisor's PIN
 * at the terminal. A no-sale carries no amount at all and exists purely so
 * opening the drawer leaves a trace - which is what makes EXCESSIVE_NO_SALE a
 * usable signal rather than a guess.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('pos.operate');

$session = session_current();
if ($session === null) {
    flash_info('Open your till before recording a cash movement.');
    redirect('pos/');
}
$sessionId = (int) $session['id'];

if (is_post()) {
    csrf_require();
    try {
        $type = one_of((string) post('type'), ['pay_in', 'pay_out', 'drop', 'no_sale', 'float_in'], 'pay_in');

        if ($type === 'no_sale') {
            require_can('pos.no_sale');
        } else {
            require_can('pos.cash_movement');
        }

        $authorisedBy = null;
        if (in_array($type, ['pay_out', 'drop'], true)) {
            [$ok, $message, $supervisorId] = pin_authorise(
                (string) post('supervisor_staff'),
                (string) ($_POST['supervisor_pin'] ?? ''),
                'pos.cash_movement'
            );
            if (!$ok) {
                throw new PosError($message);
            }
            $authorisedBy = $supervisorId;
            flash_info($message);
        }

        cash_movement_add(
            $sessionId, $type, post_num('amount'), (string) post('reason_code'),
            (string) post('note'), (int) $_SESSION['uid'], $authorisedBy
        );

        flash_ok($type === 'no_sale'
            ? 'Drawer open recorded.'
            : ucfirst(str_replace('_', ' ', $type)) . ' of ' . money(post_num('amount')) . ' recorded.');
        redirect('pos/cash');
    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('pos/cash');
    }
}

$cash      = session_expected_cash($sessionId);
$movements = qa(
    'SELECT cm.*, u.full_name AS who, a.full_name AS authorised_by_name,
            COALESCE(rc.label, cm.reason_code) AS reason_label
       FROM cash_movements cm
       JOIN users u ON u.id = cm.user_id
       LEFT JOIN users a ON a.id = cm.authorised_by
       LEFT JOIN reason_codes rc ON rc.code = cm.reason_code
      WHERE cm.session_id = ? ORDER BY cm.created_at DESC',
    [$sessionId]
);
$noSaleCount = 0;
foreach ($movements as $m) {
    if ($m['type'] === 'no_sale') {
        $noSaleCount++;
    }
}
$noSaleThreshold = (int) audit_rule_threshold('EXCESSIVE_NO_SALE', 10.0);
$cashReasons = qpairs('SELECT code, label FROM reason_codes WHERE applies_to = \'cash\' AND is_active = 1 ORDER BY label');
$noSaleReasons = qpairs('SELECT code, label FROM reason_codes WHERE applies_to = \'no_sale\' AND is_active = 1 ORDER BY label');

layout_head([
    'title'    => 'Cash drawer',
    'subtitle' => $session['session_no'] . ' on ' . $session['terminal_code'],
    'bare'     => true,
    'actions'  => '<a class="btn btn--ghost" href="' . e(base_url('pos/')) . '">Back to the till</a>',
]);
?>

<div class="tiles">
  <?= tile('In the drawer now', money((float) $cash['expected']), 'float plus takings, less what has left') ?>
  <?= tile('Paid in', money((float) $cash['pay_in']), 'top-ups during the shift', 'ok') ?>
  <?= tile('Paid out', money((float) $cash['pay_out'] + (float) $cash['drops']),
        'pay-outs and safe drops', 'warn') ?>
  <?= tile('Drawer opened without a sale', (string) $noSaleCount,
        'flagged above ' . $noSaleThreshold . ' per shift',
        $noSaleCount > $noSaleThreshold ? 'bad' : 'mute') ?>
</div>

<?php if ($noSaleCount > $noSaleThreshold): ?>
  <div class="alert alert--warning">
    This shift has opened the drawer <?= $noSaleCount ?> times without a transaction, which is above
    the <?= $noSaleThreshold ?> the audit rules flag. That is not an accusation &mdash; a busy till
    making change does it legitimately &mdash; but it will appear in the exception queue for review.
  </div>
<?php endif; ?>

<div class="grid-2">
  <?php if (can('pos.cash_movement')): ?>
    <?= card_open('Money into the drawer') ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="pay_in">
        <label class="field">
          <span class="field__label">Amount</span>
          <input type="text" name="amount" class="num-in" inputmode="decimal" required>
        </label>
        <label class="field">
          <span class="field__label">Reason</span>
          <select name="reason_code" required><?= select_options($cashReasons, 'FLOAT_TOP', true, '-- reason --') ?></select>
        </label>
        <label class="field">
          <span class="field__label">Note</span>
          <input type="text" name="note" maxlength="255">
        </label>
        <button class="btn" type="submit">Record pay-in</button>
      </form>
    <?= card_close() ?>

    <?= card_open('Money out of the drawer') ?>
      <p class="small muted">
        A pay-out or a safe drop is the only legitimate way cash leaves a till, so both need a
        supervisor's PIN. Whose PIN authorised it is recorded against the movement.
      </p>
      <form method="post">
        <?= csrf_field() ?>
        <label class="field">
          <span class="field__label">Type</span>
          <select name="type" required>
            <option value="pay_out">Pay-out (petty cash, refund float)</option>
            <option value="drop">Drop to safe</option>
          </select>
        </label>
        <label class="field">
          <span class="field__label">Amount</span>
          <input type="text" name="amount" class="num-in" inputmode="decimal" required>
          <span class="field__hint">Cannot exceed the <?= e(money((float) $cash['expected'])) ?> in the drawer.</span>
        </label>
        <label class="field">
          <span class="field__label">Reason</span>
          <select name="reason_code" required><?= select_options($cashReasons, '', true, '-- reason --') ?></select>
        </label>
        <label class="field">
          <span class="field__label">Note</span>
          <input type="text" name="note" maxlength="255">
        </label>
        <div class="form-grid">
          <label class="field">
            <span class="field__label">Supervisor staff no.</span>
            <input type="text" name="supervisor_staff" required autocomplete="off">
          </label>
          <label class="field">
            <span class="field__label">Supervisor PIN</span>
            <input type="password" name="supervisor_pin" required inputmode="numeric"
                   maxlength="<?= PIN_LENGTH ?>" autocomplete="off">
          </label>
        </div>
        <button class="btn btn--warn" type="submit">Record money out</button>
      </form>
    <?= card_close() ?>
  <?php endif; ?>

  <?php if (can('pos.no_sale')): ?>
    <?= card_open('Open the drawer without a sale') ?>
      <p class="small muted">
        Recorded with no amount. The point is the trace, not the money &mdash; a till that opens
        repeatedly between transactions is a pattern worth asking about.
      </p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="no_sale">
        <input type="hidden" name="amount" value="0">
        <label class="field">
          <span class="field__label">Reason</span>
          <select name="reason_code" required><?= select_options($noSaleReasons, 'CHANGE', true, '-- reason --') ?></select>
        </label>
        <label class="field">
          <span class="field__label">Note</span>
          <input type="text" name="note" maxlength="255">
        </label>
        <button class="btn btn--ghost" type="submit">Record drawer open</button>
      </form>
    <?= card_close() ?>
  <?php endif; ?>
</div>

<?= card_open('This shift\'s movements (' . count($movements) . ')') ?>
  <?php if (!$movements): ?>
    <?= empty_state('No cash movements recorded on this shift.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data data--tight">
      <thead><tr>
        <th>When</th><th>Type</th><th class="num">Amount</th><th>Reason</th>
        <th>Authorised by</th><th>Note</th>
      </tr></thead>
      <tbody>
      <?php foreach ($movements as $m): ?>
        <tr>
          <td class="nowrap tiny"><?= e(date('H:i', strtotime((string) $m['created_at']))) ?></td>
          <td><?= e(ucfirst(str_replace('_', ' ', (string) $m['type']))) ?></td>
          <?= num_cell($m['amount']) ?>
          <td class="tiny"><?= e($m['reason_label'] ?? '-') ?></td>
          <td class="tiny"><?= e($m['authorised_by_name'] ?? '-') ?></td>
          <td class="tiny muted"><?= e($m['note'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<?php layout_foot(); ?>
