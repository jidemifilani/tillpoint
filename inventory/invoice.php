<?php
/**
 * Enter, match and action one supplier invoice.
 *
 * Lifecycle:  draft → (match) → matched → (approve/dispute) → approved|disputed → paid
 *                                                ↑ re-match
 *
 * The match is deliberately a separate step: opening the page never writes to the
 * database, so anyone can review the comparison without triggering exception raises.
 * "Run match" is the explicit action that stores the result.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('invoice.enter');

$storeId = current_store_id();
$id      = get_int('id');

// Pre-fill from GET when arriving via "Enter invoice" from a delivery row.
$prefillSupplierId = get_int('supplier_id');
$prefillGrnId      = get_int('grn_id');

if (is_post()) {
    csrf_require();
    try {
        $action = (string) post('action');
        $uid    = (int) $_SESSION['uid'];

        /* ---- Create a new draft ------------------------------------ */
        if ($action === 'create') {
            require_can('invoice.enter');
            $supplierId  = post_int('supplier_id');
            $invoiceNo   = trim((string) post('invoice_no'));
            $invoiceDate = date_or_null(post('invoice_date')) ?? date('Y-m-d');
            $dueDate     = date_or_null(post('due_date'));
            $note        = trim((string) post('note'));

            if ($supplierId === 0) {
                throw new InvoiceError('Choose the supplier.');
            }
            if ($invoiceNo === '') {
                throw new InvoiceError('Enter the supplier\'s invoice number.');
            }
            if (!q1('SELECT id FROM suppliers WHERE id = ? AND is_active = 1', [$supplierId])) {
                throw new InvoiceError('That supplier is not active.');
            }
            // Duplicate check - the same supplier+number is the most common overpayment route.
            if (q1('SELECT id FROM supplier_invoices WHERE supplier_id = ? AND invoice_no = ?',
                    [$supplierId, $invoiceNo])) {
                throw new InvoiceError('An invoice with number "' . $invoiceNo
                    . '" from this supplier already exists. Check you are not entering it twice.');
            }

            $id = db_tx(function () use ($storeId, $supplierId, $invoiceNo, $invoiceDate, $dueDate,
                                         $note, $uid): int {
                return db_insert('supplier_invoices', [
                    'our_ref'      => next_number('supplier_invoice', $storeId),
                    'invoice_no'   => $invoiceNo,
                    'supplier_id'  => $supplierId,
                    'store_id'     => $storeId,
                    'invoice_date' => $invoiceDate,
                    'due_date'     => $dueDate,
                    'note'         => $note !== '' ? $note : null,
                    'entered_by'   => $uid,
                ]);
            });

            audit_log('invoice.create', 'supplier_invoice', $id,
                sprintf('Invoice %s from %s entered as a draft',
                    $invoiceNo,
                    (string) qv('SELECT name FROM suppliers WHERE id = ?', [$supplierId], '')),
                null, null, 'notice', $storeId);

            flash_ok('Draft created. Pull in deliveries to add lines, then run the match.');
            redirect('inventory/invoice', ['id' => $id]);
        }

        /* ---- All other actions require an existing invoice --------- */
        if ($id === 0) {
            throw new InvoiceError('No invoice specified.');
        }
        $inv = q1('SELECT * FROM supplier_invoices WHERE id = ? AND store_id = ?', [$id, $storeId]);
        if (!$inv) {
            throw new InvoiceError('That invoice does not exist in this store.');
        }

        /* ---- Pull lines from a delivery ---------------------------- */
        if ($action === 'pull_from_grn') {
            $grnId = post_int('grn_id');
            if ($grnId === 0) {
                throw new InvoiceError('Choose a delivery to pull from.');
            }
            $added = invoice_pull_from_grn($id, $grnId);
            flash_ok("$added line(s) added from the delivery. Correct quantities and prices to "
                . "match the paper invoice, then run the match.");
            redirect('inventory/invoice', ['id' => $id]);
        }

        /* ---- Save header edits ------------------------------------ */
        if ($action === 'save_header') {
            if ($inv['status'] !== 'draft') {
                throw new InvoiceError('Only a draft invoice can be edited.');
            }
            $invoiceNo   = trim((string) post('invoice_no'));
            $invoiceDate = date_or_null(post('invoice_date')) ?? date('Y-m-d');
            $dueDate     = date_or_null(post('due_date'));
            $note        = trim((string) post('note'));
            if ($invoiceNo === '') {
                throw new InvoiceError('Invoice number cannot be empty.');
            }
            // Check the new number is not already in use on a different record.
            if (q1('SELECT id FROM supplier_invoices WHERE supplier_id = ? AND invoice_no = ? AND id <> ?',
                    [(int) $inv['supplier_id'], $invoiceNo, $id])) {
                throw new InvoiceError('That invoice number is already used on another record for this supplier.');
            }
            db_update('supplier_invoices', [
                'invoice_no'   => $invoiceNo,
                'invoice_date' => $invoiceDate,
                'due_date'     => $dueDate,
                'note'         => $note !== '' ? $note : null,
            ], 'id = :id', ['id' => $id]);
            flash_ok('Header saved.');
            redirect('inventory/invoice', ['id' => $id]);
        }

        /* ---- Update a single line ---------------------------------- */
        if ($action === 'save_line') {
            if ($inv['status'] !== 'draft') {
                throw new InvoiceError('Only a draft invoice can be edited.');
            }
            $lineId   = post_int('line_id');
            $qty      = (float) post('qty');
            $unitCost = (float) post('unit_cost');
            $vatRate  = (float) post('vat_rate');
            $desc     = trim((string) post('description'));

            if (!q1('SELECT id FROM supplier_invoice_lines WHERE id = ? AND invoice_id = ?', [$lineId, $id])) {
                throw new InvoiceError('That line does not exist on this invoice.');
            }
            if ($qty <= 0) {
                throw new InvoiceError('Quantity must be positive.');
            }
            if ($unitCost < 0) {
                throw new InvoiceError('Unit cost cannot be negative.');
            }
            if ($desc === '') {
                throw new InvoiceError('Description cannot be empty.');
            }

            db_update('supplier_invoice_lines', [
                'qty'         => $qty,
                'unit_cost'   => $unitCost,
                'vat_rate'    => $vatRate,
                'description' => substr($desc, 0, 160),
            ], 'id = :id', ['id' => $lineId]);
            invoice_recalculate($id);
            redirect('inventory/invoice', ['id' => $id]);
        }

        /* ---- Add a manual line (charges, freight, etc.) ----------- */
        if ($action === 'add_line') {
            if ($inv['status'] !== 'draft') {
                throw new InvoiceError('Only a draft invoice can be edited.');
            }
            $desc     = trim((string) post('description'));
            $qty      = (float) post('qty');
            $unitCost = (float) post('unit_cost');
            $vatRate  = (float) post('vat_rate');

            if ($desc === '') {
                throw new InvoiceError('Enter a description for the extra charge.');
            }
            if ($qty <= 0 || $unitCost < 0) {
                throw new InvoiceError('Quantity must be positive and cost non-negative.');
            }

            db_insert('supplier_invoice_lines', [
                'invoice_id'  => $id,
                'grn_line_id' => null,
                'product_id'  => null,
                'description' => substr($desc, 0, 160),
                'qty'         => $qty,
                'unit_cost'   => $unitCost,
                'vat_rate'    => $vatRate,
            ]);
            invoice_recalculate($id);
            redirect('inventory/invoice', ['id' => $id]);
        }

        /* ---- Remove a line ---------------------------------------- */
        if ($action === 'remove_line') {
            if ($inv['status'] !== 'draft') {
                throw new InvoiceError('Only a draft invoice can be edited.');
            }
            $lineId = post_int('line_id');
            if (!q1('SELECT id FROM supplier_invoice_lines WHERE id = ? AND invoice_id = ?', [$lineId, $id])) {
                throw new InvoiceError('That line does not exist on this invoice.');
            }
            q('DELETE FROM supplier_invoice_lines WHERE id = ?', [$lineId]);
            invoice_recalculate($id);
            redirect('inventory/invoice', ['id' => $id]);
        }

        /* ---- Run the three-way match ------------------------------ */
        if ($action === 'match') {
            if ($inv['status'] === 'paid') {
                throw new InvoiceError('A paid invoice cannot be re-matched.');
            }
            $m = invoice_match_apply($id);
            if ($m['clean']) {
                flash_ok('Match complete: no variances found. This invoice can now be approved.');
            } else {
                flash_warn(sprintf('Match complete: %s total variance (%s price, %s qty, %d unmatched line(s)). '
                    . 'Review below, then approve with an explanation or dispute with the supplier.',
                    money($m['total_variance']),
                    money($m['price_variance']),
                    money($m['qty_variance']),
                    $m['unmatched_lines']));
            }
            redirect('inventory/invoice', ['id' => $id]);
        }

        /* ---- Approve ---------------------------------------------- */
        if ($action === 'approve') {
            require_can('invoice.approve');
            $acceptVariance = (string) post('accept_variance') === '1';
            $note = trim((string) post('note'));
            invoice_approve($id, $uid, $acceptVariance, $note);
            flash_ok('Invoice approved for payment.');
            redirect('inventory/invoice', ['id' => $id]);
        }

        /* ---- Dispute ---------------------------------------------- */
        if ($action === 'dispute') {
            $reason = trim((string) post('reason'));
            invoice_dispute($id, $uid, $reason);
            flash_warn('Invoice marked as disputed.');
            redirect('inventory/invoice', ['id' => $id]);
        }

        /* ---- Mark paid -------------------------------------------- */
        if ($action === 'mark_paid') {
            require_can('invoice.approve');
            invoice_mark_paid($id, $uid);
            flash_ok('Invoice marked as paid.');
            redirect('inventory/invoice', ['id' => $id]);
        }

        /* ---- Cancel ----------------------------------------------- */
        if ($action === 'cancel') {
            if (in_array($inv['status'], ['paid', 'cancelled'], true)) {
                throw new InvoiceError('This invoice cannot be cancelled.');
            }
            $reason = trim((string) post('reason'));
            if ($reason === '') {
                throw new InvoiceError('Give a reason for cancelling.');
            }
            db_update('supplier_invoices', ['status' => 'cancelled',
                'note' => substr(($inv['note'] ?? '') . ' | Cancelled: ' . $reason, 0, 500),
            ], 'id = :id', ['id' => $id]);
            audit_log('invoice.cancel', 'supplier_invoice', $id,
                sprintf('Invoice %s cancelled: %s', (string) $inv['invoice_no'], $reason),
                ['status' => $inv['status']], ['status' => 'cancelled'], 'warning', $storeId);
            flash_ok('Invoice cancelled.');
            redirect('inventory/invoices');
        }

    } catch (Throwable $e) {
        flash_err($e->getMessage());
        redirect('inventory/invoice' . ($id > 0 ? '?id=' . $id : ''));
    }
}

/* ------------------------------------------------------------------ */
/* Read data                                                         */
/* ------------------------------------------------------------------ */

$inv = $id > 0
    ? q1('SELECT si.*, s.name AS supplier_name, u.full_name AS entered_by_name,
                  au.full_name AS approved_by_name
            FROM supplier_invoices si
            JOIN suppliers s ON s.id = si.supplier_id
            JOIN users u ON u.id = si.entered_by
            LEFT JOIN users au ON au.id = si.approved_by
           WHERE si.id = ? AND si.store_id = ?', [$id, $storeId])
    : null;

if ($id > 0 && !$inv) {
    flash_err('That invoice does not exist in this store.');
    redirect('inventory/invoices');
}

// Lines for an existing invoice.
$lines = $inv ? qa(
    'SELECT sil.*,
            gl.qty AS received_qty, gl.unit_cost AS received_cost,
            g.grn_no, g.received_date,
            pol.unit_cost AS agreed_cost,
            po.po_no
       FROM supplier_invoice_lines sil
       LEFT JOIN grn_lines gl ON gl.id = sil.grn_line_id
       LEFT JOIN grns g ON g.id = gl.grn_id
       LEFT JOIN purchase_order_lines pol ON pol.id = gl.po_line_id
       LEFT JOIN purchase_orders po ON po.id = pol.po_id
      WHERE sil.invoice_id = ?
      ORDER BY sil.id',
    [$id]
) : [];

// The last match result (read-only, for display).
$matchResult = $inv && in_array($inv['status'], ['matched','disputed','approved','paid'], true)
    ? invoice_match($id)
    : null;

// Deliveries from this supplier not yet on this invoice.
$unbilledGrns = $inv ? invoice_unbilled_grns((int) $inv['supplier_id'], $storeId) : [];
// Also exclude deliveries already linked to this invoice.
$linkedGrns = $inv ? array_fill_keys(
    qcol('SELECT grn_id FROM supplier_invoice_grns WHERE invoice_id = ?', [$id]), true
) : [];
$pullableGrns = array_filter($unbilledGrns, fn ($g) => !isset($linkedGrns[(int) $g['id']]));

// Form data for the create form.
$suppliers   = qpairs('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name');

// Pre-fill values for the create form.
$createGrn = $prefillGrnId > 0
    ? q1('SELECT g.*, s.name AS supplier_name FROM grns g
            JOIN suppliers s ON s.id = g.supplier_id
           WHERE g.id = ? AND g.store_id = ?', [$prefillGrnId, $storeId])
    : null;

layout_head([
    'title' => $inv ? 'Invoice ' . $inv['our_ref'] : 'Enter invoice',
    'subtitle' => $inv
        ? ($inv['supplier_name'] . ' · ' . e($inv['invoice_no']))
        : 'New supplier invoice',
    'breadcrumb' => ['Inventory', '<a href="' . e(base_url('inventory/invoices')) . '">Supplier invoices</a>'],
    'actions' => $inv ? (
        can('invoice.approve') && $inv['status'] === 'approved'
            ? '<form method="post" class="inline-form" data-confirm="Mark invoice ' . e($inv['our_ref']) . ' as paid?">'
              . csrf_field()
              . '<input type="hidden" name="action" value="mark_paid">'
              . '<button class="btn" type="submit">Mark paid</button>'
              . '</form>'
            : ''
    ) : '',
]);
?>

<?php if (!$inv): ?>
  <?= card_open('Enter a new supplier invoice') ?>
    <?php if ($createGrn): ?>
      <div class="alert alert--info">
        Starting from delivery <strong><?= e($createGrn['grn_no']) ?></strong>
        from <?= e($createGrn['supplier_name']) ?>.
        The lines from that delivery will be pulled in automatically.
      </div>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <?php if ($createGrn): ?>
        <input type="hidden" name="grn_id" value="<?= (int) $createGrn['id'] ?>">
      <?php endif; ?>
      <div class="form-grid">
        <label class="field">
          <span class="field__label">Supplier</span>
          <select name="supplier_id" required>
            <option value="">— select —</option>
            <?= select_options($suppliers, (string) ($prefillSupplierId ?: ($createGrn ? $createGrn['supplier_id'] : ''))) ?>
          </select>
        </label>
        <label class="field">
          <span class="field__label">Supplier invoice no.</span>
          <input type="text" name="invoice_no" required maxlength="60"
                 placeholder="e.g. INV-20241001-0042">
          <span class="field__hint">The number printed on the paper invoice.</span>
        </label>
        <label class="field">
          <span class="field__label">Invoice date</span>
          <input type="date" name="invoice_date" value="<?= e(date('Y-m-d')) ?>">
        </label>
        <label class="field">
          <span class="field__label">Due date</span>
          <input type="date" name="due_date">
        </label>
        <label class="field field--wide">
          <span class="field__label">Note (optional)</span>
          <input type="text" name="note" maxlength="500"
                 placeholder="Any note visible to approvers">
        </label>
      </div>
      <button class="btn" type="submit">Create draft</button>
      <a class="btn btn--ghost" href="<?= e(base_url('inventory/invoices')) ?>">Cancel</a>
    </form>
  <?= card_close() ?>

<?php else: // Existing invoice ?>

<?php
// Status colour
$statusColor = match($inv['status']) {
    'paid'      => 'ok',
    'approved'  => 'info',
    'disputed'  => 'bad',
    'cancelled' => 'muted',
    default     => 'warn',
};
$matchColor = match($inv['match_status']) {
    'clean'    => 'ok',
    'variance' => 'bad',
    default    => 'warn',
};
?>

<!-- Summary header -->
<div class="tiles">
  <?= tile('Status', ucfirst((string) $inv['status']), '', $statusColor) ?>
  <?= tile('Match', ucfirst(str_replace('_', ' ', (string) $inv['match_status'])), '', $matchColor) ?>
  <?= tile('Subtotal', money((float) $inv['subtotal'])) ?>
  <?= tile('VAT', money((float) $inv['vat_total'])) ?>
  <?= tile('Total', money((float) $inv['total'])) ?>
</div>

<?php if ($inv['status'] === 'disputed' && $inv['dispute_note']): ?>
  <div class="alert alert--error">
    <strong>Disputed:</strong> <?= e((string) $inv['dispute_note']) ?>
  </div>
<?php endif; ?>

<!-- Header details / editable in draft -->
<?= card_open('Invoice details',
    $inv['status'] === 'draft' ? '' : badge(ucfirst((string) $inv['status']), $statusColor)) ?>
<?php if ($inv['status'] === 'draft'): ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_header">
    <div class="form-grid">
      <label class="field">
        <span class="field__label">Our reference</span>
        <input type="text" value="<?= e($inv['our_ref']) ?>" disabled>
      </label>
      <label class="field">
        <span class="field__label">Supplier</span>
        <input type="text" value="<?= e($inv['supplier_name']) ?>" disabled>
      </label>
      <label class="field">
        <span class="field__label">Supplier invoice no.</span>
        <input type="text" name="invoice_no" required maxlength="60"
               value="<?= e($inv['invoice_no']) ?>">
      </label>
      <label class="field">
        <span class="field__label">Invoice date</span>
        <input type="date" name="invoice_date" value="<?= e((string) $inv['invoice_date']) ?>">
      </label>
      <label class="field">
        <span class="field__label">Due date</span>
        <input type="date" name="due_date"
               value="<?= e((string) ($inv['due_date'] ?? '')) ?>">
      </label>
      <label class="field field--wide">
        <span class="field__label">Note</span>
        <input type="text" name="note" maxlength="500"
               value="<?= e((string) ($inv['note'] ?? '')) ?>">
      </label>
    </div>
    <button class="btn btn--ghost" type="submit">Save changes</button>
  </form>
<?php else: ?>
  <table class="data data--tight">
    <tbody>
      <tr><th style="width:9rem">Our ref</th><td class="mono"><?= e($inv['our_ref']) ?></td>
          <th style="width:9rem">Supplier inv.</th><td><?= e($inv['invoice_no']) ?></td></tr>
      <tr><th>Supplier</th><td><?= e($inv['supplier_name']) ?></td>
          <th>Entered by</th><td><?= e($inv['entered_by_name']) ?></td></tr>
      <tr><th>Invoice date</th><td><?= e(date_fmt((string) $inv['invoice_date'])) ?></td>
          <th>Due</th><td><?= $inv['due_date'] ? e(date_fmt((string) $inv['due_date'])) : '—' ?></td></tr>
      <?php if ($inv['approved_by_name']): ?>
        <tr><th>Approved by</th><td><?= e($inv['approved_by_name']) ?></td>
            <th>Approved at</th><td><?= e(date_fmt((string) $inv['approved_at'])) ?></td></tr>
      <?php endif; ?>
      <?php if ($inv['note']): ?>
        <tr><th>Note</th><td colspan="3"><?= e((string) $inv['note']) ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
<?php endif; ?>
<?= card_close() ?>

<!-- Pull from GRN (only in draft) -->
<?php if ($inv['status'] === 'draft' && $pullableGrns): ?>
  <?= card_open('Pull lines from a delivery') ?>
    <p class="small muted">
      Seeded from what was received. Correct quantities and prices to match the paper invoice,
      then run the match to see what differs.
    </p>
    <form method="post" class="filters">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="pull_from_grn">
      <label class="field">
        <span class="field__label">Delivery</span>
        <select name="grn_id" required>
          <option value="">— select —</option>
          <?php foreach ($pullableGrns as $g): ?>
            <option value="<?= (int) $g['id'] ?>"
              <?= $prefillGrnId === (int) $g['id'] ? 'selected' : '' ?>>
              <?= e($g['grn_no']) ?>
              (<?= e(date_fmt((string) $g['received_date'])) ?>,
              <?= (int) $g['line_count'] ?> line(s),
              <?= money_short((float) $g['total']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn" type="submit">Pull lines</button>
    </form>
  <?= card_close() ?>
<?php endif; ?>

<!-- Lines -->
<?= card_open('Invoice lines') ?>
<?php if (!$lines): ?>
  <p class="muted small">No lines yet. Pull from a delivery or add a manual charge below.</p>
<?php else: ?>
  <div class="table-wrap">
  <table class="data">
    <thead><tr>
      <th>Description</th><th>GRN</th>
      <th class="num">Billed qty</th><th class="num">Billed cost</th>
      <?php if ($matchResult): ?>
        <th class="num">Recv. qty</th><th class="num">Agreed cost</th>
        <th>Match issues</th>
      <?php endif; ?>
      <th class="num">VAT%</th><th class="num">Line total</th>
      <?php if ($inv['status'] === 'draft'): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php
    // Index match detail by line id for quick lookup.
    $matchByLine = [];
    if ($matchResult) {
        foreach ($matchResult['detail'] as $d) {
            $matchByLine[(int) $d['line']['id']] = $d;
        }
    }
    foreach ($lines as $l):
        $lineId = (int) $l['id'];
        $md     = $matchByLine[$lineId] ?? null;
        $rowBad = $md && !$md['clean'];
    ?>
      <tr class="<?= $rowBad ? 'row-bad' : '' ?>">
        <td>
          <?php if ($inv['status'] === 'draft'): ?>
            <form method="post" class="inline-form" id="line-form-<?= $lineId ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_line">
              <input type="hidden" name="line_id" value="<?= $lineId ?>">
              <input class="input input--sm" type="text" name="description"
                     value="<?= e((string) $l['description']) ?>" maxlength="160" required>
            </form>
          <?php else: ?>
            <?= e((string) $l['description']) ?>
          <?php endif; ?>
          <?php if ($l['grn_line_id'] === null): ?>
            <?= badge('No delivery', 'bad') ?>
          <?php elseif ($l['grn_no']): ?>
            <br><span class="muted tiny"><?= e($l['grn_no']) ?>
              — <?= e(date_fmt((string) $l['received_date'])) ?></span>
          <?php endif; ?>
        </td>
        <td class="mono tiny"><?= $l['grn_no'] ? e($l['grn_no']) : '<span class="muted">—</span>' ?></td>
        <?php if ($inv['status'] === 'draft'): ?>
          <td class="num">
            <input class="input input--sm input--num" form="line-form-<?= $lineId ?>"
                   type="number" name="qty" step="0.001" min="0.001"
                   value="<?= e(qty_fmt((float) $l['qty'])) ?>" required>
          </td>
          <td class="num">
            <input class="input input--sm input--num" form="line-form-<?= $lineId ?>"
                   type="number" name="unit_cost" step="0.01" min="0"
                   value="<?= e(number_format((float) $l['unit_cost'], 4)) ?>" required>
          </td>
        <?php else: ?>
          <td class="num"><?= e(qty_fmt((float) $l['qty'])) ?></td>
          <td class="num"><?= money((float) $l['unit_cost']) ?></td>
        <?php endif; ?>
        <?php if ($matchResult && $md): ?>
          <td class="num <?= abs((float)($md['qty_diff'] ?? 0)) > 0.0005 ? 'bad-text' : '' ?>">
            <?= $md['received_qty'] !== null ? e(qty_fmt((float) $md['received_qty'])) : '<span class="muted">—</span>' ?>
          </td>
          <td class="num <?= abs((float)($md['price_diff'] ?? 0)) > 0.0001 ? 'bad-text' : '' ?>">
            <?= $md['agreed_cost'] !== null ? money((float) $md['agreed_cost'])
                : ($md['received_cost'] !== null ? money((float) $md['received_cost']) : '<span class="muted">—</span>') ?>
          </td>
          <td class="small">
            <?php if ($md['clean']): ?>
              <span class="ok-text">✓</span>
            <?php else: ?>
              <?php foreach ($md['issues'] as [$kind, $msg]): ?>
                <div class="<?= $kind === 'unmatched' ? 'bad-text' : 'warn-text' ?>"><?= e($msg) ?></div>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>
        <?php endif; ?>
        <?php if ($inv['status'] === 'draft'): ?>
          <td class="num">
            <input class="input input--sm input--num" form="line-form-<?= $lineId ?>"
                   type="number" name="vat_rate" step="0.01" min="0" max="1"
                   value="<?= e(number_format((float) $l['vat_rate'], 4)) ?>">
          </td>
        <?php else: ?>
          <td class="num"><?= pct_fmt((float) $l['vat_rate'] * 100) ?></td>
        <?php endif; ?>
        <?= num_cell($l['line_total']) ?>
        <?php if ($inv['status'] === 'draft'): ?>
          <td class="right nowrap">
            <button class="btn btn--sm" type="submit" form="line-form-<?= $lineId ?>">Save</button>
            <form method="post" class="inline-form"
                  data-confirm="Remove this line from the invoice?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove_line">
              <input type="hidden" name="line_id" value="<?= $lineId ?>">
              <button class="btn btn--sm btn--danger" type="submit">×</button>
            </form>
          </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <th colspan="<?= ($matchResult ? 7 : 4) + ($inv['status'] === 'draft' ? 1 : 0) ?>">Subtotal</th>
        <?= num_cell($inv['subtotal']) ?>
        <?php if ($inv['status'] === 'draft'): ?><td></td><?php endif; ?>
      </tr>
      <tr>
        <th colspan="<?= ($matchResult ? 7 : 4) + ($inv['status'] === 'draft' ? 1 : 0) ?>">VAT</th>
        <?= num_cell($inv['vat_total']) ?>
        <?php if ($inv['status'] === 'draft'): ?><td></td><?php endif; ?>
      </tr>
      <tr>
        <th colspan="<?= ($matchResult ? 7 : 4) + ($inv['status'] === 'draft' ? 1 : 0) ?>"><strong>Total</strong></th>
        <td class="num"><strong><?= money((float) $inv['total']) ?></strong></td>
        <?php if ($inv['status'] === 'draft'): ?><td></td><?php endif; ?>
      </tr>
    </tfoot>
  </table>
  </div>
<?php endif; ?>

<?php if ($inv['status'] === 'draft'): ?>
  <hr>
  <details>
    <summary class="small">Add a manual line (freight, handling charges, etc.)</summary>
    <form method="post" style="margin-top:0.75rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_line">
      <div class="form-grid">
        <label class="field field--wide">
          <span class="field__label">Description</span>
          <input type="text" name="description" required maxlength="160"
                 placeholder="e.g. Delivery charge">
        </label>
        <label class="field">
          <span class="field__label">Qty</span>
          <input type="number" name="qty" step="0.001" min="0.001" value="1" required>
        </label>
        <label class="field">
          <span class="field__label">Unit cost</span>
          <input type="number" name="unit_cost" step="0.01" min="0" required>
        </label>
        <label class="field">
          <span class="field__label">VAT rate (decimal, e.g. 0.075)</span>
          <input type="number" name="vat_rate" step="0.001" min="0" max="1" value="0.075">
        </label>
      </div>
      <button class="btn btn--ghost" type="submit">Add line</button>
    </form>
  </details>
<?php endif; ?>
<?= card_close() ?>

<!-- Match result summary -->
<?php if ($matchResult): ?>
  <?= card_open('Match result',
      badge($matchResult['clean'] ? 'Clean' : 'Variance', $matchResult['clean'] ? 'ok' : 'bad')) ?>
    <div class="tiles">
      <?= tile('Price variance', money($matchResult['price_variance']),
            'billed vs agreed', abs($matchResult['price_variance']) > $matchResult['tolerance_value'] ? 'bad' : 'ok') ?>
      <?= tile('Qty variance', money($matchResult['qty_variance']),
            'billed vs received', abs($matchResult['qty_variance']) > $matchResult['tolerance_value'] ? 'bad' : 'ok') ?>
      <?= tile('Total variance', money($matchResult['total_variance']),
            'tolerance ' . money($matchResult['tolerance_value']),
            $matchResult['clean'] ? 'ok' : 'bad') ?>
      <?= tile('Unmatched lines', (string) $matchResult['unmatched_lines'],
            'billed for nothing received',
            $matchResult['unmatched_lines'] > 0 ? 'bad' : 'ok') ?>
    </div>
  <?= card_close() ?>
<?php endif; ?>

<!-- Actions -->
<?php if ($inv['status'] !== 'cancelled' && $inv['status'] !== 'paid'): ?>
  <?= card_open('Actions') ?>
    <div class="btn-row">

      <?php if (in_array($inv['status'], ['draft', 'matched', 'disputed'], true)): ?>
        <form method="post" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="match">
          <button class="btn" type="submit">
            <?= $inv['status'] === 'draft' ? 'Run match' : 'Re-run match' ?>
          </button>
        </form>
      <?php endif; ?>

      <?php if (can('invoice.approve') && in_array($inv['status'], ['matched', 'disputed'], true)): ?>
        <button class="btn btn--ghost" type="button"
                onclick="document.getElementById('approve-panel').hidden=false">Approve for payment</button>
        <button class="btn btn--ghost" type="button"
                onclick="document.getElementById('dispute-panel').hidden=false">Dispute</button>
      <?php elseif (in_array($inv['status'], ['matched', 'disputed'], true)): ?>
        <button class="btn btn--ghost" type="button"
                onclick="document.getElementById('dispute-panel').hidden=false">Dispute</button>
      <?php endif; ?>

      <button class="btn btn--danger btn--ghost" type="button"
              onclick="document.getElementById('cancel-panel').hidden=false">Cancel invoice</button>
    </div>

    <!-- Approve panel -->
    <div id="approve-panel" hidden style="margin-top:1rem;padding:1rem;border:1px solid var(--border);border-radius:4px">
      <strong>Approve for payment</strong>
      <form method="post" style="margin-top:0.5rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="approve">
        <?php if ($inv['match_status'] === 'variance'): ?>
          <div class="alert alert--warning" style="margin-bottom:0.75rem">
            This invoice has variances. You must explicitly accept them and give a reason.
          </div>
          <label class="field">
            <span class="field__label">Accept variances?</span>
            <label>
              <input type="checkbox" name="accept_variance" value="1"> Yes, I accept the variances
            </label>
          </label>
        <?php else: ?>
          <input type="hidden" name="accept_variance" value="0">
        <?php endif; ?>
        <label class="field">
          <span class="field__label">Note <?= $inv['match_status'] === 'variance' ? '(required)' : '(optional)' ?></span>
          <input type="text" name="note" maxlength="400"
                 <?= $inv['match_status'] === 'variance' ? 'required' : '' ?>
                 placeholder="Reason for approving<?= $inv['match_status'] === 'variance' ? ' with variance' : '' ?>">
        </label>
        <button class="btn" type="submit">Confirm approval</button>
        <button class="btn btn--ghost" type="button"
                onclick="document.getElementById('approve-panel').hidden=true">Cancel</button>
      </form>
    </div>

    <!-- Dispute panel -->
    <div id="dispute-panel" hidden style="margin-top:1rem;padding:1rem;border:1px solid var(--border);border-radius:4px">
      <strong>Dispute this invoice</strong>
      <form method="post" style="margin-top:0.5rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="dispute">
        <label class="field">
          <span class="field__label">Reason (required)</span>
          <input type="text" name="reason" required maxlength="500"
                 placeholder="What you are disputing with the supplier">
        </label>
        <button class="btn btn--warn" type="submit">Mark as disputed</button>
        <button class="btn btn--ghost" type="button"
                onclick="document.getElementById('dispute-panel').hidden=true">Cancel</button>
      </form>
    </div>

    <!-- Cancel panel -->
    <div id="cancel-panel" hidden style="margin-top:1rem;padding:1rem;border:1px solid var(--border);border-radius:4px">
      <strong>Cancel invoice</strong>
      <form method="post" style="margin-top:0.5rem"
            data-confirm="Cancel invoice <?= e($inv['our_ref']) ?>? This cannot be undone.">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <label class="field">
          <span class="field__label">Reason (required)</span>
          <input type="text" name="reason" required maxlength="500">
        </label>
        <button class="btn btn--danger" type="submit">Cancel invoice</button>
        <button class="btn btn--ghost" type="button"
                onclick="document.getElementById('cancel-panel').hidden=true">Close</button>
      </form>
    </div>

  <?= card_close() ?>
<?php endif; ?>

<?php endif; // end $inv exists ?>

<?php layout_foot(); ?>
