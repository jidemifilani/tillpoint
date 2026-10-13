<?php
/**
 * Reason codes.
 *
 * These drive every void, write-off, refund, override and cash-movement
 * dropdown in the application. They are a closed list on purpose: free-text
 * reasons cannot be counted, and "why did stock leave this shop" is a question
 * that only has a useful answer if the answers are comparable.
 *
 * A code in use is never deleted - thousands of historic rows reference it and
 * the reports group by it. Deactivating removes it from the dropdowns while
 * keeping the history readable.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('admin.settings');

/** Where each list appears, so the page can say what it affects. */
function reason_usage(): array
{
    return [
        'wastage'        => ['Write-offs', 'Chosen whenever stock is written off. The grouping behind the wastage-by-reason report.'],
        'adjustment'     => ['Stock adjustments', 'Mandatory on an adjustment, which is the only document that changes stock with no physical event behind it.'],
        'void'           => ['Voided baskets', 'Chosen at the till when a basket is abandoned before payment.'],
        'refund'         => ['Refunds', 'Why goods came back. Drives the refund-reason breakdown.'],
        'price_override' => ['Price overrides', 'Why a cashier charged something other than the shelf price.'],
        'discount'       => ['Line discounts', 'Why a discount was given.'],
        'no_sale'        => ['Drawer opened without a sale', 'Why the drawer was opened with no transaction.'],
        'cash'           => ['Cash movements', 'Pay-ins, pay-outs and safe drops.'],
    ];
}

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');

        if ($action === 'create') {
            $code = strtoupper(trim((string) post('code')));
            $label = trim((string) post('label'));
            if (!preg_match('/^[A-Z][A-Z0-9_]{1,19}$/', $code)) {
                throw new RuntimeException('The code must start with a letter and contain only '
                    . 'capitals, digits and underscores, up to 20 characters.');
            }
            if ($label === '') {
                throw new RuntimeException('Give the code a label - that is what staff will read.');
            }
            if (qv('SELECT code FROM reason_codes WHERE code = ?', [$code])) {
                throw new RuntimeException('That code already exists.');
            }
            $appliesTo = one_of((string) post('applies_to'),
                array_keys(reason_usage()), 'wastage');

            db_insert('reason_codes', [
                'code'           => $code,
                'label'          => $label,
                'applies_to'     => $appliesTo,
                'needs_approval' => post('needs_approval') === '1' ? 1 : 0,
                'is_active'      => 1,
            ]);
            audit_log('reason_code.create', 'reason_code', null,
                sprintf('Reason code added: %s "%s" for %s', $code, $label, $appliesTo),
                null, null, 'notice');
            flash_ok('Reason code added. It will appear in the ' . $appliesTo . ' dropdown.');
        }

        if ($action === 'save') {
            $changed = 0;
            db_tx(function () use (&$changed): void {
                foreach ((array) ($_POST['reason'] ?? []) as $code => $vals) {
                    $code = (string) $code;
                    $row  = q1('SELECT * FROM reason_codes WHERE code = ?', [$code]);
                    if (!$row) {
                        continue;
                    }
                    $label  = trim((string) ($vals['label'] ?? ''));
                    $active = isset($vals['is_active']) ? 1 : 0;
                    $needs  = isset($vals['needs_approval']) ? 1 : 0;

                    if ($label === '') {
                        throw new RuntimeException('A reason code cannot have an empty label ('
                            . $code . ').');
                    }
                    if ($label === (string) $row['label']
                        && $active === (int) $row['is_active']
                        && $needs === (int) $row['needs_approval']) {
                        continue;
                    }

                    db_update('reason_codes', [
                        'label'          => $label,
                        'is_active'      => $active,
                        'needs_approval' => $needs,
                    ], 'code = :c', ['c' => $code]);

                    audit_log('reason_code.update', 'reason_code', null,
                        sprintf('Reason code %s: %s%s', $code, $label,
                            $active !== (int) $row['is_active']
                                ? ($active === 1 ? ' - switched on' : ' - withdrawn from the dropdowns')
                                : ''),
                        (array) $row,
                        ['label' => $label, 'is_active' => $active, 'needs_approval' => $needs],
                        'notice');
                    $changed++;
                }
            });
            flash_ok($changed === 0 ? 'Nothing changed.' : "$changed code(s) updated.");
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect('admin/reason-codes');
}

// Usage counts per code, so nothing in active use gets switched off by accident.
$usageCounts = [];
foreach ([
    ['wastage',        'SELECT reason_code, COUNT(*) FROM wastage GROUP BY reason_code'],
    ['adjustment',     'SELECT reason_code, COUNT(*) FROM stock_adjustments GROUP BY reason_code'],
    ['void',           'SELECT void_reason, COUNT(*) FROM sales WHERE void_reason IS NOT NULL GROUP BY void_reason'],
    ['refund',         'SELECT reason_code, COUNT(*) FROM refunds GROUP BY reason_code'],
    ['discount',       'SELECT discount_reason, COUNT(*) FROM sale_lines WHERE discount_reason IS NOT NULL GROUP BY discount_reason'],
    ['cash',           "SELECT reason_code, COUNT(*) FROM cash_movements WHERE reason_code IS NOT NULL AND type <> 'no_sale' GROUP BY reason_code"],
    ['no_sale',        "SELECT reason_code, COUNT(*) FROM cash_movements WHERE reason_code IS NOT NULL AND type = 'no_sale' GROUP BY reason_code"],
] as [$scope, $sql]) {
    foreach (qpairs($sql) as $code => $n) {
        $usageCounts[(string) $code] = ($usageCounts[(string) $code] ?? 0) + (int) $n;
    }
}
// price_override codes are recorded on the line as a discount reason too, so
// they are already counted above.

$rows = qa('SELECT * FROM reason_codes ORDER BY applies_to, label');
$byScope = [];
foreach ($rows as $r) {
    $byScope[(string) $r['applies_to']][] = $r;
}

$usage    = reason_usage();
$inactive = count(array_filter($rows, fn ($r) => (int) $r['is_active'] === 0));

layout_head([
    'title'    => 'Reason codes',
    'subtitle' => count($rows) . ' codes across ' . count($byScope) . ' lists'
                . ($inactive > 0 ? ', ' . $inactive . ' withdrawn' : ''),
]);
?>

<div class="alert alert--info">
  These are a closed list on purpose. Free-text reasons cannot be counted, and the whole value of
  the wastage, refund and void reports is that the answers are comparable &mdash; "damaged in
  store" running at three times "expired" on a chilled range tells you something, and forty
  different spellings of it tells you nothing.
</div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <?php foreach ($usage as $scope => [$title, $blurb]):
      $scopeRows = $byScope[$scope] ?? []; ?>
    <?= card_open($title) ?>
      <p class="small muted"><?= e($blurb) ?></p>
      <?php if (!$scopeRows): ?>
        <?= empty_state('No codes in this list.',
              'The dropdown it feeds will be empty, which will stop that action being recorded.') ?>
      <?php else: ?>
        <div class="table-wrap">
        <table class="data data--tight">
          <thead><tr>
            <th>Code</th><th>Label staff will read</th>
            <th class="num">Times used</th><th>Needs approval</th><th>In the dropdown</th>
          </tr></thead>
          <tbody>
          <?php foreach ($scopeRows as $r):
              $code = (string) $r['code'];
              $used = (int) ($usageCounts[$code] ?? 0); ?>
            <tr class="<?= (int) $r['is_active'] === 0 ? 'row-warn' : '' ?>">
              <td class="mono tiny"><?= e($code) ?></td>
              <td>
                <input type="text" name="reason[<?= e($code) ?>][label]"
                       value="<?= e((string) $r['label']) ?>" maxlength="120"
                       style="max-width:340px">
              </td>
              <td class="num">
                <?= number_format($used) ?>
                <?php if ($used > 0): ?>
                  <br><span class="tiny muted">in the history</span>
                <?php endif; ?>
              </td>
              <td class="center">
                <input type="checkbox" name="reason[<?= e($code) ?>][needs_approval]" value="1"
                       <?= (int) $r['needs_approval'] === 1 ? 'checked' : '' ?>
                       aria-label="Needs approval">
              </td>
              <td class="center">
                <input type="checkbox" name="reason[<?= e($code) ?>][is_active]" value="1"
                       <?= (int) $r['is_active'] === 1 ? 'checked' : '' ?>
                       aria-label="Show in the dropdown">
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    <?= card_close() ?>
  <?php endforeach; ?>

  <div class="btn-row mb1 no-print">
    <button class="btn btn--lg" type="submit">Save reason codes</button>
  </div>
</form>

<?= card_open('Add a reason code') ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-grid">
      <label class="field">
        <span class="field__label">Code</span>
        <input type="text" name="code" required maxlength="20" placeholder="SUPPLIER_FAULT">
        <span class="field__hint">
          Permanent: historic records will reference it and the reports group by it.
        </span>
      </label>
      <label class="field">
        <span class="field__label">Label</span>
        <input type="text" name="label" required maxlength="120"
               placeholder="Arrived damaged from the supplier">
        <span class="field__hint">What appears in the dropdown.</span>
      </label>
      <label class="field">
        <span class="field__label">Which list</span>
        <select name="applies_to" required>
          <?= select_options(array_map(fn ($u) => $u[0], $usage), 'wastage') ?>
        </select>
      </label>
    </div>
    <div class="check">
      <input type="checkbox" name="needs_approval" value="1" id="rc-approve">
      <label for="rc-approve">
        Flag as needing approval
        <br><span class="small muted">
          Advisory only: the approval thresholds under Settings are what actually decide whether a
          second person is required. This marks the reason as one a manager should look at.
        </span>
      </label>
    </div>
    <button class="btn" type="submit">Add code</button>
  </form>
<?= card_close() ?>

<?= card_open('Withdrawing rather than deleting') ?>
  <p class="small">
    There is no delete here. A code used on a thousand historic write-offs is referenced by every
    one of them and by the reports that group them; removing the row would turn years of
    categorised records into blanks.
  </p>
  <p class="small muted">
    Unticking "in the dropdown" withdraws it from new transactions immediately, which is the part
    that matters. The history keeps its label and keeps grouping correctly. A withdrawn code with
    a usage count above zero is working exactly as intended.
  </p>
<?= card_close() ?>

<?php layout_foot(); ?>
