<?php
/**
 * Suppliers.
 *
 * The figures shown are earned from documents - orders raised, goods received,
 * fill rate, days late - rather than a subjective rating, so the scorecard
 * survives a change of buyer and cannot be talked up.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('suppliers.view');

if (is_post()) {
    csrf_require();
    require_can('suppliers.manage');
    try {
        $action = (string) post('action');

        if ($action === 'create' || $action === 'update') {
            $name = trim((string) post('name'));
            if ($name === '') {
                throw new RuntimeException('Enter the supplier name.');
            }
            $data = [
                'name'               => $name,
                'contact_name'       => (string) post('contact_name') !== '' ? (string) post('contact_name') : null,
                'phone'              => (string) post('phone') !== '' ? (string) post('phone') : null,
                'email'              => (string) post('email') !== '' ? (string) post('email') : null,
                'address'            => (string) post('address') !== '' ? (string) post('address') : null,
                'payment_terms_days' => max(0, post_int('payment_terms_days', 30)),
                'lead_time_days'     => max(0, post_int('lead_time_days', 7)),
                'is_active'          => post('is_active') === '1' ? 1 : 0,
            ];

            if ($action === 'create') {
                $code = strtoupper(trim((string) post('code')));
                if ($code === '') {
                    throw new RuntimeException('Give the supplier a short code.');
                }
                if (qv('SELECT id FROM suppliers WHERE code = ?', [$code])) {
                    throw new RuntimeException('That code is already in use.');
                }
                $data['code'] = $code;
                $id = db_insert('suppliers', $data);
                audit_log('supplier.create', 'supplier', $id,
                    'Supplier added: ' . $name . ' (' . $code . ')', null, $data, 'notice');
                flash_ok('Supplier added.');
            } else {
                $id     = post_int('id');
                $before = q1('SELECT * FROM suppliers WHERE id = ?', [$id]);
                if (!$before) {
                    throw new RuntimeException('That supplier no longer exists.');
                }
                db_update('suppliers', $data, 'id = :id', ['id' => $id]);
                audit_log('supplier.update', 'supplier', $id,
                    'Supplier updated: ' . $name, $before, $data, 'info');
                flash_ok('Supplier updated.');
            }
        }
    } catch (Throwable $e) {
        flash_err($e->getMessage());
    }
    redirect('inventory/suppliers');
}

$editId  = get_int('edit');
$editing = $editId > 0 ? q1('SELECT * FROM suppliers WHERE id = ?', [$editId]) : null;
$showAll = (string) get('all') === '1';

$rows = qa(
    'SELECT s.*,
            (SELECT COUNT(*) FROM products p WHERE p.supplier_id = s.id AND p.is_active = 1) AS products,
            (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id
               AND po.status <> \'cancelled\') AS orders,
            (SELECT COALESCE(SUM(g.total),0) FROM grns g
               WHERE g.supplier_id = s.id AND g.status = \'posted\'
                 AND g.received_date >= (CURDATE() - INTERVAL 365 DAY)) AS spend_year,
            (SELECT COALESCE(SUM(pol.qty_ordered),0) FROM purchase_order_lines pol
               JOIN purchase_orders po ON po.id = pol.po_id
              WHERE po.supplier_id = s.id AND po.status IN (\'received\',\'partly_received\')) AS ordered,
            (SELECT COALESCE(SUM(pol.qty_received),0) FROM purchase_order_lines pol
               JOIN purchase_orders po ON po.id = pol.po_id
              WHERE po.supplier_id = s.id AND po.status IN (\'received\',\'partly_received\')) AS received,
            (SELECT MAX(g.received_date) FROM grns g
              WHERE g.supplier_id = s.id AND g.status = \'posted\') AS last_delivery
       FROM suppliers s
      WHERE ' . ($showAll ? '1 = 1' : 's.is_active = 1') . '
      ORDER BY spend_year DESC, s.name'
);

layout_head([
    'title'    => 'Suppliers',
    'subtitle' => count($rows) . ' ' . ($showAll ? 'on file' : 'active'),
    'actions'  => can('suppliers.manage') && $editing === null
        ? '<a class="btn" href="' . e(url_with(['edit' => -1])) . '">New supplier</a>' : '',
]);
?>

<?php if (can('suppliers.manage') && ($editing !== null || $editId === -1)):
    $s = $editing ?? [
        'id' => 0, 'code' => '', 'name' => '', 'contact_name' => '', 'phone' => '',
        'email' => '', 'address' => '', 'payment_terms_days' => 30,
        'lead_time_days' => 7, 'is_active' => 1,
    ]; ?>
  <?= card_open($editing !== null ? 'Edit ' . $s['name'] : 'New supplier') ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $editing !== null ? 'update' : 'create' ?>">
      <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
      <div class="form-grid">
        <?php if ($editing === null): ?>
          <label class="field">
            <span class="field__label">Code</span>
            <input type="text" name="code" required maxlength="20" placeholder="SUP012">
          </label>
        <?php endif; ?>
        <label class="field">
          <span class="field__label">Name</span>
          <input type="text" name="name" required maxlength="140" value="<?= e((string) $s['name']) ?>">
        </label>
        <label class="field">
          <span class="field__label">Contact</span>
          <input type="text" name="contact_name" maxlength="120" value="<?= e((string) ($s['contact_name'] ?? '')) ?>">
        </label>
        <label class="field">
          <span class="field__label">Phone</span>
          <input type="tel" name="phone" maxlength="40" value="<?= e((string) ($s['phone'] ?? '')) ?>">
        </label>
        <label class="field">
          <span class="field__label">Email</span>
          <input type="email" name="email" maxlength="160" value="<?= e((string) ($s['email'] ?? '')) ?>">
        </label>
        <label class="field">
          <span class="field__label">Payment terms (days)</span>
          <input type="number" name="payment_terms_days" class="num-in" min="0"
                 value="<?= (int) $s['payment_terms_days'] ?>">
        </label>
        <label class="field">
          <span class="field__label">Lead time (days)</span>
          <input type="number" name="lead_time_days" class="num-in" min="0"
                 value="<?= (int) $s['lead_time_days'] ?>">
          <span class="field__hint">Used by replenishment to size an order and to flag it overdue.</span>
        </label>
      </div>
      <label class="field">
        <span class="field__label">Address</span>
        <input type="text" name="address" maxlength="255" value="<?= e((string) ($s['address'] ?? '')) ?>">
      </label>
      <div class="check">
        <input type="checkbox" name="is_active" value="1" id="sup-active"
               <?= (int) $s['is_active'] === 1 ? 'checked' : '' ?>>
        <label for="sup-active">Active &mdash; can be chosen on new orders</label>
      </div>
      <div class="btn-row">
        <button class="btn" type="submit"><?= $editing !== null ? 'Save changes' : 'Add supplier' ?></button>
        <a class="btn btn--ghost" href="<?= e(base_url('inventory/suppliers')) ?>">Cancel</a>
      </div>
    </form>
  <?= card_close() ?>
<?php endif; ?>

<?= card_open('Suppliers',
      '<a class="btn btn--sm btn--ghost" href="' . e(url_with(['all' => $showAll ? null : '1'])) . '">'
      . ($showAll ? 'Active only' : 'Include inactive') . '</a>'
      . (can('reports.view')
          ? '<a class="btn btn--sm btn--ghost" href="' . e(base_url('reports/suppliers')) . '">Scorecard</a>'
          : '')) ?>
  <?php if (!$rows): ?>
    <?= empty_state('No suppliers on file.') ?>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data">
      <thead><tr>
        <th>Code</th><th>Name</th><th>Contact</th>
        <th class="num">Products</th><th class="num">Orders</th>
        <th class="num">Fill rate</th><th class="num">Spend, 12 months</th>
        <th class="num">Terms</th><th class="num">Lead</th><th>Last delivery</th>
        <?= can('suppliers.manage') ? '<th></th>' : '' ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $s):
          $ordered = (float) $s['ordered'];
          $recvd   = (float) $s['received'];
          // Fill rate: what arrived against what was ordered, on orders that
          // have actually been delivered against. An order nobody has received
          // yet would otherwise drag a good supplier's figure down.
          $fill    = $ordered > 0 ? ($recvd / $ordered) * 100 : null; ?>
        <tr class="<?= (int) $s['is_active'] === 0 ? 'row-warn' : '' ?>">
          <td class="mono tiny"><?= e($s['code']) ?></td>
          <td>
            <?= e($s['name']) ?>
            <?php if ((int) $s['is_active'] === 0): ?> <?= badge('Inactive', 'neutral') ?><?php endif; ?>
          </td>
          <td class="tiny">
            <?= e($s['contact_name'] ?? '-') ?>
            <?php if ($s['phone']): ?><br><span class="muted"><?= e((string) $s['phone']) ?></span><?php endif; ?>
          </td>
          <td class="num"><?= number_format((int) $s['products']) ?></td>
          <td class="num"><?= number_format((int) $s['orders']) ?></td>
          <td class="num <?= $fill !== null && $fill < 90 ? 'neg' : '' ?>">
            <?= e($fill !== null ? pct_fmt($fill) : '-') ?>
          </td>
          <?= num_cell($s['spend_year']) ?>
          <td class="num"><?= (int) $s['payment_terms_days'] ?>d</td>
          <td class="num"><?= (int) $s['lead_time_days'] ?>d</td>
          <td class="tiny nowrap">
            <?= e($s['last_delivery'] !== null ? date_fmt((string) $s['last_delivery']) : 'never') ?>
          </td>
          <?php if (can('suppliers.manage')): ?>
            <td class="right">
              <a class="btn btn--sm btn--ghost" href="<?= e(url_with(['edit' => (int) $s['id']])) ?>">Edit</a>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?= card_close() ?>

<p class="small muted">
  Fill rate is quantity received against quantity ordered on orders that have been delivered
  against &mdash; so an order still in transit does not count against the supplier. Anything under
  90% is marked.
</p>

<?php layout_foot(); ?>
