<?php
/**
 * TillPoint smoke test.
 *
 *     php tests/smoke.php
 *
 * Asserts the invariants the whole application rests on. Read-only except for
 * the engine section at the end, which posts a sale and a refund into a scratch
 * store and then checks the books still balance.
 *
 * The point is not coverage, it is the handful of equalities that must never be
 * false. If any of these fail, no figure in the system means anything:
 *
 *   - every stock position equals the sum of its own ledger movements
 *   - every batch balance adds up to its product's position
 *   - every sale total equals its lines, and its VAT is derived not added
 *   - no refund exceeds what the original sale actually sold
 *   - the audit trail's hash chain verifies end to end
 *   - the Internal Auditor role holds no permission that posts a transaction
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("tests/smoke.php runs from the command line only.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
$_SESSION = [];
foreach (['db', 'functions', 'csrf', 'rbac', 'rate_limit', 'auth', 'audit', 'numbering',
          'inventory', 'stock_docs', 'promotions', 'pos', 'audit_rules', 'reports'] as $inc) {
    require_once $root . "/includes/$inc.php";
}

/* ------------------------------------------------------------------ */
/* Harness                                                            */
/* ------------------------------------------------------------------ */
$passed = 0;
$failed = 0;
$failures = [];
$section = '';

function section(string $name): void
{
    global $section;
    $section = $name;
    echo PHP_EOL . "\033[1m" . $name . "\033[0m" . PHP_EOL . str_repeat('-', strlen($name)) . PHP_EOL;
}

function ok(bool $condition, string $what, string $detail = ''): void
{
    global $passed, $failed, $failures, $section;
    if ($condition) {
        $passed++;
        echo "  \033[32mPASS\033[0m  $what" . ($detail !== '' ? "  ($detail)" : '') . PHP_EOL;
    } else {
        $failed++;
        $failures[] = "[$section] $what" . ($detail !== '' ? " - $detail" : '');
        echo "  \033[31mFAIL\033[0m  $what" . ($detail !== '' ? "  ($detail)" : '') . PHP_EOL;
    }
}

/** Assert a query returns no rows; prints the first few when it does. */
function ok_empty(string $what, string $sql, array $params = [], int $show = 3): void
{
    try {
        $rows = qa($sql, $params);
    } catch (Throwable $e) {
        ok(false, $what, 'query failed: ' . $e->getMessage());
        return;
    }
    $detail = '';
    if ($rows) {
        $parts = [];
        foreach (array_slice($rows, 0, $show) as $r) {
            $parts[] = implode('/', array_map(
                fn ($v) => is_null($v) ? 'NULL' : (string) $v,
                array_slice($r, 0, 4)
            ));
        }
        $detail = count($rows) . ' offending row(s): ' . implode('; ', $parts);
    }
    ok($rows === [], $what, $detail);
}

function near(float $a, float $b, float $tolerance = 0.005): bool
{
    return abs($a - $b) <= $tolerance;
}

echo str_repeat('=', 72) . PHP_EOL;
echo 'TillPoint smoke test - ' . date('d M Y H:i:s') . PHP_EOL;
echo 'Database: ' . DB_NAME . ' on ' . DB_HOST . ':' . DB_PORT . PHP_EOL;
echo str_repeat('=', 72) . PHP_EOL;

/* ================================================================== */
section('Schema and reference data');
/* ================================================================== */

$tableCount = (int) qv(
    'SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\'', [DB_NAME], 0
);
ok($tableCount >= 45, 'The schema is installed', "$tableCount tables");

foreach (['stores', 'users', 'roles', 'permissions', 'products', 'stock_levels',
          'stock_movements', 'sales', 'sale_lines', 'audit_log', 'audit_rules'] as $t) {
    ok((int) qv('SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [DB_NAME, $t], 0) === 1,
        "Table `$t` exists");
}

// Every permission the code checks must exist in the table, or a require_can()
// is silently guarding nothing.
$catalogue = array_keys(permission_flat());
$inDb      = qcol('SELECT code FROM permissions');
$missing   = array_diff($catalogue, $inDb);
ok($missing === [], 'Every permission in the code catalogue is in the database',
    $missing ? 'missing: ' . implode(', ', $missing) : count($catalogue) . ' permissions');

$orphaned = array_diff($inDb, $catalogue);
ok($orphaned === [], 'No permission in the database is unknown to the code',
    $orphaned ? 'orphaned: ' . implode(', ', $orphaned) : '');

$ruleCodes = array_keys(audit_rule_catalogue());
$rulesInDb = qcol('SELECT code FROM audit_rules');
$missingRules = array_diff($ruleCodes, $rulesInDb);
ok($missingRules === [], 'Every audit rule in the catalogue is in the database',
    $missingRules ? 'missing: ' . implode(', ', $missingRules) : count($ruleCodes) . ' rules');

ok((int) qv('SELECT COUNT(*) FROM reason_codes', [], 0) > 20, 'Reason codes are seeded',
    qv('SELECT COUNT(*) FROM reason_codes', [], 0) . ' codes');
ok((int) qv('SELECT COUNT(*) FROM units', [], 0) >= 10, 'Units of measure are seeded');

/* ================================================================== */
section('Segregation of duties by design');
/* ================================================================== */

// The auditor role is the load-bearing one: an auditor who can post stock is
// not an auditor. This asserts the role template, not just the documentation.
$auditorPosting = qcol(
    "SELECT rp.permission_code FROM role_permissions rp
       JOIN roles r ON r.id = rp.role_id
      WHERE r.code = 'auditor'
        AND rp.permission_code IN (
          'pos.operate','pos.void','pos.discount','pos.price_override','pos.cash_movement',
          'refund.request','refund.approve','products.manage','products.price',
          'stock.adjust','stock.adjust.approve','wastage.log','wastage.approve',
          'count.create','count.enter','count.approve','transfer.create','transfer.dispatch',
          'transfer.receive','po.create','po.approve','grn.create','grn.post',
          'promotions.manage','customers.manage','vouchers.manage','admin.users','admin.roles'
        )"
);
ok($auditorPosting === [], 'The Internal Auditor role holds no permission that posts a transaction',
    $auditorPosting ? 'holds: ' . implode(', ', $auditorPosting) : 'read and review only');

$cashierPrivileged = qcol(
    "SELECT rp.permission_code FROM role_permissions rp
       JOIN roles r ON r.id = rp.role_id
      WHERE r.code = 'cashier'
        AND rp.permission_code IN ('pos.void','pos.discount','pos.price_override',
                                   'refund.approve','pos.session.reconcile','products.price')"
);
ok($cashierPrivileged === [], 'The Cashier role cannot void, discount, override or approve',
    $cashierPrivileged ? 'holds: ' . implode(', ', $cashierPrivileged) : '');

$buyerApproves = qcol(
    "SELECT rp.permission_code FROM role_permissions rp
       JOIN roles r ON r.id = rp.role_id
      WHERE r.code = 'buyer' AND rp.permission_code IN ('po.approve','grn.post')"
);
ok($buyerApproves === [], 'The Buyer role cannot approve its own orders or receive goods',
    $buyerApproves ? 'holds: ' . implode(', ', $buyerApproves) : '');

/* ================================================================== */
section('Stock ledger integrity');
/* ================================================================== */

// THE invariant. Every position must equal the sum of its own movements.
$discrepancies = stock_ledger_discrepancies();
ok($discrepancies === [],
    'Every stock position equals the sum of its ledger movements',
    $discrepancies
        ? count($discrepancies) . ' line(s) out, worst: ' . $discrepancies[0]['name']
          . ' off by ' . qty_fmt((float) $discrepancies[0]['difference'])
        : (string) qv('SELECT COUNT(*) FROM stock_levels', [], 0) . ' positions checked');

// Batch balances must add up to the product position for batch-tracked lines.
ok_empty('Batch balances reconcile to their product position',
    "SELECT p.sku, p.name, sl.qty_on_hand, COALESCE(b.total, 0) AS batch_total,
            sl.qty_on_hand - COALESCE(b.total, 0) AS diff
       FROM stock_levels sl
       JOIN products p ON p.id = sl.product_id
       LEFT JOIN (SELECT product_id, store_id, SUM(qty_on_hand) AS total
                    FROM stock_batches GROUP BY product_id, store_id) b
              ON b.product_id = sl.product_id AND b.store_id = sl.store_id
      WHERE p.track_batches = 1
      HAVING ABS(diff) > 0.0005");

ok_empty('No batch holds a negative quantity',
    'SELECT id, batch_no, qty_on_hand FROM stock_batches WHERE qty_on_hand < -0.0005');

ok_empty('No batch holds more than was received into it',
    'SELECT id, batch_no, qty_received, qty_on_hand FROM stock_batches
      WHERE qty_on_hand > qty_received + 0.0005');

ok_empty('Every ledger row records the balance it produced',
    'SELECT id, product_id, movement_type FROM stock_movements WHERE balance_after IS NULL');

ok_empty('No ledger row has a zero quantity',
    'SELECT id, product_id, movement_type FROM stock_movements
      WHERE ABS(qty_delta) < 0.0005');

ok_empty('Reservations are never negative',
    'SELECT product_id, store_id, qty_reserved FROM stock_levels WHERE qty_reserved < -0.0005');

// A reservation larger than the position means a parked basket is holding stock
// that is not there, which would let the till refuse a sale it should allow.
ok_empty('No reservation exceeds the stock on hand',
    'SELECT sl.product_id, sl.store_id, sl.qty_on_hand, sl.qty_reserved
       FROM stock_levels sl
      WHERE sl.qty_reserved > sl.qty_on_hand + 0.0005 AND sl.qty_on_hand >= 0');

// Reservations should match what parked baskets actually hold.
ok_empty('Reservations match the quantity on parked baskets',
    "SELECT sl.product_id, sl.store_id, sl.qty_reserved, COALESCE(pk.qty, 0) AS parked
       FROM stock_levels sl
       LEFT JOIN (SELECT sline.product_id, s.store_id, SUM(sline.qty) AS qty
                    FROM sale_lines sline JOIN sales s ON s.id = sline.sale_id
                   WHERE s.status = 'parked'
                   GROUP BY sline.product_id, s.store_id) pk
              ON pk.product_id = sl.product_id AND pk.store_id = sl.store_id
      WHERE ABS(sl.qty_reserved - COALESCE(pk.qty, 0)) > 0.0005");

/* ================================================================== */
section('Sale arithmetic');
/* ================================================================== */

ok_empty('Every sale total equals the sum of its lines',
    "SELECT s.id, s.receipt_no, s.total, COALESCE(SUM(sl.line_total), 0) AS line_sum
       FROM sales s LEFT JOIN sale_lines sl ON sl.sale_id = s.id
      WHERE s.status <> 'voided'
      GROUP BY s.id, s.receipt_no, s.total
      HAVING ABS(s.total - COALESCE(SUM(sl.line_total), 0)) > 0.005");

ok_empty('Every sale total equals net plus VAT',
    "SELECT id, receipt_no, subtotal, vat_total, total
       FROM sales
      WHERE status <> 'voided' AND ABS(total - (subtotal + vat_total)) > 0.005");

ok_empty('Every sale VAT figure equals the sum of its line VAT',
    "SELECT s.id, s.receipt_no, s.vat_total, COALESCE(SUM(sl.vat_amount), 0) AS line_vat
       FROM sales s LEFT JOIN sale_lines sl ON sl.sale_id = s.id
      WHERE s.status <> 'voided'
      GROUP BY s.id, s.receipt_no, s.vat_total
      HAVING ABS(s.vat_total - COALESCE(SUM(sl.vat_amount), 0)) > 0.005");

// VAT derived OUT of the inclusive price, not added to it. If this is wrong the
// receipt total will not match the shelf prices the customer added up.
ok_empty('Line VAT is derived out of the VAT-inclusive price',
    'SELECT id, sale_id, line_total, vat_rate, vat_amount,
            ROUND(line_total * vat_rate / (1 + vat_rate), 2) AS expected
       FROM sale_lines
      WHERE vat_rate > 0
        AND ABS(vat_amount - ROUND(line_total * vat_rate / (1 + vat_rate), 2)) > 0.02');

ok_empty('Every completed sale was paid for in full',
    "SELECT id, receipt_no, total, paid_total FROM sales
      WHERE status IN ('completed','refunded','partly_refunded') AND paid_total + 0.005 < total");

ok_empty('Payments recorded equal the amount banked plus change',
    "SELECT s.id, s.receipt_no, s.paid_total, COALESCE(SUM(sp.amount), 0) AS payment_sum
       FROM sales s LEFT JOIN sale_payments sp ON sp.sale_id = s.id
      WHERE s.status IN ('completed','refunded','partly_refunded')
      GROUP BY s.id, s.receipt_no, s.paid_total
      HAVING ABS(s.paid_total - COALESCE(SUM(sp.amount), 0)) > 0.005");

ok_empty('Every sale line count matches its actual lines',
    "SELECT s.id, s.receipt_no, s.line_count, COUNT(sl.id) AS actual
       FROM sales s LEFT JOIN sale_lines sl ON sl.sale_id = s.id
      WHERE s.status <> 'voided'
      GROUP BY s.id, s.receipt_no, s.line_count
      HAVING s.line_count <> COUNT(sl.id)");

ok_empty('No sale line has a negative quantity or price',
    'SELECT id, sale_id, qty, unit_price FROM sale_lines WHERE qty <= 0 OR unit_price < 0');

ok_empty('Receipt numbers are unique',
    'SELECT receipt_no, COUNT(*) AS n FROM sales GROUP BY receipt_no HAVING n > 1');

ok_empty('Every completed sale released its reservation',
    "SELECT s.id, s.receipt_no FROM sales s
      WHERE s.status IN ('completed','refunded','partly_refunded')
        AND NOT EXISTS (SELECT 1 FROM stock_movements sm
                         WHERE sm.ref_type = 'sale' AND sm.ref_id = s.id)
        AND s.line_count > 0");

/* ================================================================== */
section('Refunds');
/* ================================================================== */

// A refund for more than was sold is the classic way cash leaves a till.
ok_empty('No sale line has been refunded for more than it sold',
    'SELECT id, sale_id, description, qty, qty_refunded FROM sale_lines
      WHERE qty_refunded > qty + 0.0005');

ok_empty('No refund exceeds the sale it is against',
    "SELECT r.sale_id, s.receipt_no, s.total, SUM(r.total) AS refunded
       FROM refunds r JOIN sales s ON s.id = r.sale_id
      WHERE r.status = 'posted'
      GROUP BY r.sale_id, s.receipt_no, s.total
      HAVING SUM(r.total) > s.total + 0.005");

ok_empty('Every refund total equals the sum of its lines',
    'SELECT r.id, r.refund_no, r.total, COALESCE(SUM(rl.line_total), 0) AS line_sum
       FROM refunds r LEFT JOIN refund_lines rl ON rl.refund_id = r.id
      GROUP BY r.id, r.refund_no, r.total
      HAVING ABS(r.total - COALESCE(SUM(rl.line_total), 0)) > 0.005');

ok_empty("A sale's refunded total matches its posted refunds",
    "SELECT s.id, s.receipt_no, s.refunded_total, COALESCE(r.total, 0) AS posted
       FROM sales s
       LEFT JOIN (SELECT sale_id, SUM(total) AS total FROM refunds
                   WHERE status = 'posted' GROUP BY sale_id) r ON r.sale_id = s.id
      WHERE ABS(s.refunded_total - COALESCE(r.total, 0)) > 0.005");

// The control that matters: nobody approved their own refund.
// A refund posted at the till by someone holding refund.approve, under the
// limit, needed no second person - self_approved records that. The breach is a
// refund that QUEUED for approval and was then approved by its own raiser.
ok_empty('No queued refund was approved by the person who raised it',
    'SELECT id, refund_no, requested_by, total FROM refunds
      WHERE approved_by IS NOT NULL AND approved_by = requested_by
        AND self_approved = 0');

ok_empty('No till-authorised refund exceeds the approval limit',
    'SELECT id, refund_no, total FROM refunds WHERE self_approved = 1 AND total > ?',
    [setting_num('refund_approval_limit', (float) REFUND_APPROVAL_LIMIT)]);

ok_empty('Every posted refund points at a real completed sale',
    "SELECT r.id, r.refund_no FROM refunds r JOIN sales s ON s.id = r.sale_id
      WHERE r.status = 'posted' AND s.status NOT IN ('completed','refunded','partly_refunded')");

/* ================================================================== */
section('Approvals and duty segregation in practice');
/* ================================================================== */

ok_empty('No purchase order was approved by its author',
    'SELECT id, po_no, created_by FROM purchase_orders
      WHERE approved_by IS NOT NULL AND approved_by = created_by');

ok_empty('No stock count was approved by whoever counted it',
    'SELECT id, count_no, counted_by FROM stock_counts
      WHERE approved_by IS NOT NULL AND counted_by IS NOT NULL
        AND approved_by = counted_by');

ok_empty('No write-off was approved by whoever raised it',
    'SELECT id, waste_no, logged_by FROM wastage
      WHERE approved_by IS NOT NULL AND approved_by = logged_by');

ok_empty('No adjustment was approved by its author',
    'SELECT id, adj_no, created_by FROM stock_adjustments
      WHERE approved_by IS NOT NULL AND approved_by = created_by');

ok_empty('No transfer was dispatched and received by the same person',
    'SELECT id, transfer_no, dispatched_by FROM stock_transfers
      WHERE dispatched_by IS NOT NULL AND received_by IS NOT NULL
        AND dispatched_by = received_by');

ok_empty('No invoice was approved by the person who entered it',
    'SELECT id, our_ref, entered_by FROM supplier_invoices
      WHERE approved_by IS NOT NULL AND approved_by = entered_by');

ok_empty('No paid invoice is missing an approver',
    'SELECT id, our_ref, status FROM supplier_invoices
      WHERE status = \'paid\' AND approved_by IS NULL');

ok_empty('No invoice was paid without going through approved status',
    'SELECT id, our_ref FROM supplier_invoices
      WHERE status = \'paid\' AND paid_at IS NULL');

ok_empty('No approved invoice was approved before being matched',
    'SELECT id, our_ref, match_status FROM supplier_invoices
      WHERE status IN (\'approved\',\'paid\') AND match_status = \'unmatched\'');

ok_empty('No out-of-tolerance till was signed off by its own cashier',
    'SELECT id, session_no, user_id, variance FROM till_sessions
      WHERE reconciled_by IS NOT NULL AND reconciled_by = user_id
        AND ABS(COALESCE(variance, 0)) > ?',
    [setting_num('till_variance_tolerance', (float) TILL_VARIANCE_TOLERANCE)]);

/* ================================================================== */
section('Till sessions and cash');
/* ================================================================== */

ok_empty('Each cashier has at most one open session',
    "SELECT user_id, COUNT(*) AS n FROM till_sessions
      WHERE status = 'open' GROUP BY user_id HAVING n > 1");

ok_empty('Each terminal has at most one open session',
    "SELECT terminal_id, COUNT(*) AS n FROM till_sessions
      WHERE status = 'open' GROUP BY terminal_id HAVING n > 1");

ok_empty('Every closed session records its variance as declared less expected',
    'SELECT id, session_no, expected_cash, declared_cash, variance FROM till_sessions
      WHERE declared_cash IS NOT NULL
        AND ABS(variance - (declared_cash - expected_cash)) > 0.005');

// Catches a raw UPDATE on status directly: session_close() always stamps all
// three, so a row missing them was changed outside the application.
ok_empty('Every non-open session records when and by whom it was closed',
    "SELECT id, session_no, status, closed_at, closed_by FROM till_sessions
      WHERE status <> 'open' AND (closed_at IS NULL OR closed_by IS NULL)");

ok_empty('No closed session still holds a parked basket',
    "SELECT ts.id, ts.session_no, COUNT(s.id) AS parked
       FROM till_sessions ts JOIN sales s ON s.session_id = ts.id
      WHERE ts.status <> 'open' AND s.status = 'parked'
      GROUP BY ts.id, ts.session_no");

ok_empty('Pay-outs and safe drops all carry a supervisor authorisation',
    "SELECT id, type, amount, user_id FROM cash_movements
      WHERE type IN ('pay_out','drop') AND authorised_by IS NULL");

ok_empty('No-sale records carry no amount',
    "SELECT id, amount FROM cash_movements WHERE type = 'no_sale' AND ABS(amount) > 0.005");

// Spot-check the expected-cash arithmetic against a recomputation.
$closedSessions = qa(
    "SELECT id, session_no, expected_cash FROM till_sessions
      WHERE status <> 'open' AND expected_cash IS NOT NULL ORDER BY id DESC LIMIT 20"
);
$cashMismatch = [];
foreach ($closedSessions as $s) {
    $recomputed = session_expected_cash((int) $s['id']);
    if (!near((float) $s['expected_cash'], (float) $recomputed['expected'], 0.02)) {
        $cashMismatch[] = $s['session_no'] . ': stored ' . $s['expected_cash']
            . ' vs recomputed ' . $recomputed['expected'];
    }
}
ok($cashMismatch === [], 'Stored expected cash recomputes to the same figure',
    $cashMismatch ? implode('; ', array_slice($cashMismatch, 0, 3))
                  : count($closedSessions) . ' sessions checked');

/* ================================================================== */
section('Loyalty and vouchers');
/* ================================================================== */

ok_empty('No customer holds a negative points balance',
    'SELECT id, code, points_balance FROM customers WHERE points_balance < -0.005');

// The ledger must explain the balance: the latest running balance is the balance.
ok_empty('The loyalty ledger explains every points balance',
    'SELECT c.id, c.code, c.points_balance, ll.balance_after
       FROM customers c
       JOIN loyalty_ledger ll ON ll.id = (SELECT MAX(id) FROM loyalty_ledger
                                           WHERE customer_id = c.id)
      WHERE ABS(c.points_balance - ll.balance_after) > 0.005');

ok_empty('No customer exceeds their credit limit',
    'SELECT id, code, balance, credit_limit FROM customers
      WHERE balance > credit_limit + 0.005');

ok_empty('No voucher holds more than its face value',
    'SELECT id, code, face_value, balance FROM vouchers WHERE balance > face_value + 0.005');

ok_empty('No voucher holds a negative balance',
    'SELECT id, code, balance FROM vouchers WHERE balance < -0.005');

/* ================================================================== */
section('Purchasing and receiving');
/* ================================================================== */

ok_empty('Every posted goods receipt moved stock',
    "SELECT g.id, g.grn_no FROM grns g
      WHERE g.status = 'posted'
        AND EXISTS (SELECT 1 FROM grn_lines gl WHERE gl.grn_id = g.id)
        AND NOT EXISTS (SELECT 1 FROM stock_movements sm
                         WHERE sm.ref_type = 'grn' AND sm.ref_id = g.id)");

ok_empty('No draft goods receipt has moved stock',
    "SELECT g.id, g.grn_no FROM grns g
      WHERE g.status = 'draft'
        AND EXISTS (SELECT 1 FROM stock_movements sm
                     WHERE sm.ref_type = 'grn' AND sm.ref_id = g.id)");

ok_empty('Every goods receipt total equals the sum of its lines',
    'SELECT g.id, g.grn_no, g.subtotal, COALESCE(SUM(gl.line_total), 0) AS line_sum
       FROM grns g LEFT JOIN grn_lines gl ON gl.grn_id = g.id
      GROUP BY g.id, g.grn_no, g.subtotal
      HAVING ABS(g.subtotal - COALESCE(SUM(gl.line_total), 0)) > 0.05');

ok_empty('No purchase order line shows more received than exists in receipts',
    "SELECT pol.id, pol.po_id, pol.qty_received, COALESCE(g.qty, 0) AS from_grns
       FROM purchase_order_lines pol
       LEFT JOIN (SELECT gl.po_line_id, SUM(gl.qty) AS qty
                    FROM grn_lines gl JOIN grns g2 ON g2.id = gl.grn_id
                   WHERE g2.status = 'posted' GROUP BY gl.po_line_id) g
              ON g.po_line_id = pol.id
      WHERE ABS(pol.qty_received - COALESCE(g.qty, 0)) > 0.0005");

ok_empty('Every posted count variance reached the ledger',
    "SELECT sc.id, sc.count_no FROM stock_counts sc
      WHERE sc.status = 'posted' AND ABS(sc.variance_qty) > 0.0005
        AND NOT EXISTS (SELECT 1 FROM stock_movements sm
                         WHERE sm.ref_type = 'count' AND sm.ref_id = sc.id)");

/* ================================================================== */
section('Audit trail');
/* ================================================================== */

$chain = audit_verify_chain();
ok($chain['result'] !== 'broken', 'The audit trail hash chain verifies',
    $chain['result'] === 'broken'
        ? 'broken at entry #' . $chain['broken_at_id']
        : number_format((int) $chain['rows_checked']) . ' entries, chain unbroken');

ok_empty('No audit entry is missing its hash',
    "SELECT id, action FROM audit_log WHERE row_hash = '' OR row_hash IS NULL");

$genesis = q1('SELECT prev_hash FROM audit_log ORDER BY id ASC LIMIT 1');
if ($genesis !== null) {
    ok((string) $genesis['prev_hash'] === AUDIT_GENESIS_HASH,
        'The first trail entry chains from the genesis hash');
}

// Everything that moves money or stock should have left a trail entry.
foreach ([
    ['sale.complete',     'completed sales',  "SELECT COUNT(*) FROM sales WHERE status IN ('completed','refunded','partly_refunded')"],
    ['grn.post',          'posted receipts',  "SELECT COUNT(*) FROM grns WHERE status = 'posted'"],
    ['refund.post',       'posted refunds',   "SELECT COUNT(*) FROM refunds WHERE status = 'posted'"],
] as [$action, $label, $countSql]) {
    $events  = (int) qv($countSql, [], 0);
    $logged  = (int) qv('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action], 0);
    // Logged can exceed events (a sale later voided still logged its completion),
    // but it must never fall short - that would mean an unlogged posting.
    ok($events === 0 || $logged >= $events,
        "Every one of the $label is in the trail",
        "$events event(s), $logged trail entr(ies) for $action");
}

// Sessions get their own check rather than sharing the loop above: a close can
// legitimately be recorded as session.close by the application or, where a
// session was closed out of band, as an explicit session.force_close. What must
// never happen is a closed session with neither.
ok_empty('Every closed session has a close recorded in the trail',
    "SELECT ts.id, ts.session_no, ts.closed_at FROM till_sessions ts
      WHERE ts.status <> 'open' AND ts.closed_at IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM audit_log al
                         WHERE al.entity = 'till_session' AND al.entity_id = ts.id
                           AND al.action IN ('session.close','session.force_close'))");

ok_empty('No audit entry claims a user that does not exist',
    'SELECT al.id, al.user_id FROM audit_log al
       LEFT JOIN users u ON u.id = al.user_id
      WHERE al.user_id IS NOT NULL AND u.id IS NULL');

/* ================================================================== */
section('Promotion engine');
/* ================================================================== */

// Buy-X-get-Y arithmetic, which is the one promotion type that cannot be
// expressed as a per-unit price and so is the one most likely to be wrong.
ok(near(promo_bogo_total(6, 100, 5, 1), 500.0), 'Buy 5 get 1 free: 6 units cost 5',
    'got ' . promo_bogo_total(6, 100, 5, 1));
ok(near(promo_bogo_total(12, 100, 5, 1), 1000.0), 'Buy 5 get 1 free: 12 units cost 10',
    'got ' . promo_bogo_total(12, 100, 5, 1));
ok(near(promo_bogo_total(5, 100, 5, 1), 500.0), 'Buy 5 get 1 free: 5 units cost 5 (no free unit yet)',
    'got ' . promo_bogo_total(5, 100, 5, 1));
ok(near(promo_bogo_total(7, 100, 5, 1), 600.0), 'Buy 5 get 1 free: 7 units cost 6',
    'got ' . promo_bogo_total(7, 100, 5, 1));
ok(near(promo_bogo_total(3, 100, 2, 1), 200.0), 'Three for two: 3 units cost 2',
    'got ' . promo_bogo_total(3, 100, 2, 1));
ok(near(promo_bogo_total(4, 100, 2, 1), 300.0), 'Three for two: 4 units cost 3',
    'got ' . promo_bogo_total(4, 100, 2, 1));

ok_empty('No promotion ends before it starts',
    'SELECT id, code, starts_at, ends_at FROM promotions WHERE ends_at < starts_at');

ok_empty('No percentage promotion is outside 0-100',
    "SELECT id, code, value FROM promotions
      WHERE promo_type IN ('pct_off','bundle_pct') AND (value <= 0 OR value >= 100)");

ok_empty('Every product-scoped promotion names a product',
    "SELECT id, code FROM promotions WHERE scope = 'product' AND product_id IS NULL");

/* ================================================================== */
section('Data sanity');
/* ================================================================== */

ok_empty('No product has a negative cost or price',
    'SELECT id, sku, cost_price, sell_price FROM products WHERE cost_price < 0 OR sell_price < 0');

ok_empty('Barcodes are unique across products',
    "SELECT barcode, COUNT(*) AS n FROM products
      WHERE barcode IS NOT NULL AND barcode <> '' GROUP BY barcode HAVING n > 1");

ok_empty('No barcode is claimed by both a product and an alternate code',
    'SELECT pb.barcode FROM product_barcodes pb
       JOIN products p ON p.barcode = pb.barcode');

ok_empty('Every weighed product uses a fractional unit',
    'SELECT p.id, p.sku, p.unit_code FROM products p
       JOIN units u ON u.code = p.unit_code
      WHERE p.is_weighed = 1 AND u.is_decimal = 0');

ok_empty('Every user has a role that exists',
    'SELECT u.id, u.staff_no FROM users u LEFT JOIN roles r ON r.id = u.role_id
      WHERE r.id IS NULL');

ok((int) qv("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
              WHERE r.code = 'admin' AND u.is_active = 1", [], 0) > 0,
    'At least one active administrator exists');

ok_empty('No password or PIN is stored unhashed',
    "SELECT id, staff_no FROM users
      WHERE password_hash NOT LIKE '\$2y\$%' AND password_hash NOT LIKE '\$argon2%'");

ok_empty('Every terminal belongs to a store that exists',
    'SELECT t.id, t.code FROM terminals t LEFT JOIN stores s ON s.id = t.store_id
      WHERE s.id IS NULL');

/* ================================================================== */
section('Live engine test');
/* ================================================================== */

// Everything above is read-only. This posts a real sale and refund into a
// scratch store, then re-checks the invariants - so the test exercises the
// write paths rather than only inspecting what the seeder left behind.
$scratchStore = null;
try {
    $admin = q1("SELECT u.id, u.full_name, r.code AS role_code FROM users u
                   JOIN roles r ON r.id = u.role_id
                  WHERE r.code = 'admin' AND u.is_active = 1 ORDER BY u.id LIMIT 1");
    if ($admin === null) {
        throw new RuntimeException('no administrator to act as');
    }
    $_SESSION['uid']       = (int) $admin['id'];
    $_SESSION['user_name'] = (string) $admin['full_name'];
    $_SESSION['role_code'] = 'admin';

    $scratchStore = (int) qv("SELECT id FROM stores WHERE code = 'TEST'", [], 0);
    if ($scratchStore === 0) {
        $scratchStore = db_insert('stores', [
            'code' => 'TEST', 'name' => 'Smoke Test Store', 'is_active' => 0,
        ]);
    }
    $terminalId = (int) qv("SELECT id FROM terminals WHERE code = 'TEST-T1'", [], 0);
    if ($terminalId === 0) {
        $terminalId = db_insert('terminals', [
            'code' => 'TEST-T1', 'name' => 'Smoke Test Till',
            'store_id' => $scratchStore, 'is_active' => 1,
        ]);
    }
    // Clear any session left open on this terminal by an earlier run, through
    // session_close() rather than a raw status UPDATE. An UPDATE would leave
    // closed_at null and write no trail entry, which is precisely the defect
    // the audit-trail assertions above exist to catch - the test must not be
    // the thing creating it.
    foreach (qa("SELECT id FROM till_sessions
                  WHERE (user_id = ? OR terminal_id = ?) AND status = 'open'",
                [(int) $admin['id'], $terminalId]) as $stale) {
        $staleCash = session_expected_cash((int) $stale['id']);
        foreach (qa("SELECT id FROM sales WHERE session_id = ? AND status = 'parked'",
                    [(int) $stale['id']]) as $parked) {
            sale_void((int) $parked['id'], 'TILL_ERROR',
                'Voided by the smoke test while clearing a stale session', (int) $admin['id']);
        }
        session_close((int) $stale['id'], (float) $staleCash['expected'],
            (int) $admin['id'], 'Closed by the smoke test before starting a new run');
    }

    // A throwaway product per run, so "a receipt into an EMPTY position" is
    // actually true. Reusing a real product made three assertions pass only on
    // a freshly seeded database - the test was lying on the second run.
    // Untracked on purpose: this section checks weighted-average costing, and a
    // batch-tracked product would correctly issue at its batch cost instead.
    $testSku = 'SMOKE-' . date('YmdHis');
    $category = (int) qv('SELECT id FROM categories ORDER BY id LIMIT 1', [], 0);
    $productId = db_insert('products', [
        'sku'           => $testSku,
        'name'          => 'Smoke Test Product ' . date('H:i:s'),
        'category_id'   => $category,
        'unit_code'     => 'EA',
        'cost_price'    => 100.0,
        'sell_price'    => 250.0,
        'vat_rate'      => VAT_RATE,
        'track_batches' => 0,
        'is_weighed'    => 0,
        'is_active'     => 1,
        'created_by'    => (int) $admin['id'],
    ]);
    ok(true, 'A throwaway test product was created', $testSku);

    // Receive 10 units at a known cost, so the weighted average is predictable.
    db_tx(function () use ($productId, $scratchStore): void {
        stock_receive([
            'product_id' => $productId, 'store_id' => $scratchStore,
            'qty' => 10, 'unit_cost' => 100.0,
            'ref_type' => 'grn', 'ref_no' => 'SMOKE-IN', 'note' => 'Smoke test receipt',
        ]);
    });
    $avg = stock_avg_cost($productId, $scratchStore);
    ok(near($avg, 100.0, 0.01), 'A receipt into an empty position sets the average cost',
        'average is ' . $avg);

    // A second receipt at a different cost must move the average, not replace it.
    db_tx(function () use ($productId, $scratchStore): void {
        stock_receive([
            'product_id' => $productId, 'store_id' => $scratchStore,
            'qty' => 10, 'unit_cost' => 200.0,
            'ref_type' => 'grn', 'ref_no' => 'SMOKE-IN2', 'note' => 'Smoke test receipt 2',
        ]);
    });
    $avg2 = stock_avg_cost($productId, $scratchStore);
    ok(near($avg2, 150.0, 0.01), 'A second receipt moves the weighted average',
        '10 at 100 plus 10 at 200 gives ' . $avg2 . ', expected 150');

    $before = stock_on_hand($productId, $scratchStore);
    ok(near($before, 20.0), 'Both receipts are on hand', 'on hand ' . qty_fmt($before));

    // Sell 3, check the position, the VAT split and the ledger.
    $session = session_open($scratchStore, $terminalId, (int) $admin['id'], 5000.0);
    $saleId  = sale_open((int) $session['id']);
    sale_add_line($saleId, $productId, 3);
    $sale    = q1('SELECT * FROM sales WHERE id = ?', [$saleId]);
    $total   = (float) $sale['total'];

    $result = sale_complete($saleId, [
        ['method' => 'cash', 'amount' => $total, 'tendered' => ceil($total / 500) * 500],
    ]);
    ok(near((float) $result['total'], $total), 'The sale completed for the basket total');

    $posted = q1('SELECT * FROM sales WHERE id = ?', [$saleId]);
    ok($posted['status'] === 'completed', 'The sale is marked completed');
    ok(near((float) $posted['subtotal'] + (float) $posted['vat_total'], (float) $posted['total']),
        'Net plus VAT equals the total on the posted sale');
    ok(near((float) $posted['cost_total'], 3 * 150.0, 0.5),
        'The sale carried the weighted-average cost',
        'cost ' . $posted['cost_total'] . ', expected ' . (3 * 150.0)
        . ' (3 units at the 150.00 blended average)');

    $after = stock_on_hand($productId, $scratchStore);
    ok(near($after, $before - 3), 'Selling three units reduced the position by three',
        'on hand ' . qty_fmt($after));

    $avgAfterSale = stock_avg_cost($productId, $scratchStore);
    ok(near($avgAfterSale, 150.0, 0.01), 'A sale leaves the weighted average alone',
        'average is still ' . $avgAfterSale);

    // Refund one unit, restocking it.
    $line = q1('SELECT id, qty FROM sale_lines WHERE sale_id = ? LIMIT 1', [$saleId]);
    $refund = refund_create($saleId, [(int) $line['id'] => 1.0], 'FAULTY',
        'Smoke test refund', true, 'cash', (int) $admin['id'], (int) $session['id']);
    ok($refund['posted'] === true, 'The refund posted immediately under the approval limit');

    $afterRefund = stock_on_hand($productId, $scratchStore);
    ok(near($afterRefund, $after + 1), 'A restocked refund put the unit back',
        'on hand ' . qty_fmt($afterRefund));

    $refundedLine = q1('SELECT qty_refunded FROM sale_lines WHERE id = ?', [(int) $line['id']]);
    ok(near((float) $refundedLine['qty_refunded'], 1.0),
        'The sale line records what was refunded');

    // Refunding more than remains must be refused.
    $overRefundRefused = false;
    try {
        refund_create($saleId, [(int) $line['id'] => 99.0], 'FAULTY',
            'Should be refused', true, 'cash', (int) $admin['id'], (int) $session['id']);
    } catch (Throwable) {
        $overRefundRefused = true;
    }
    ok($overRefundRefused, 'Refunding more than was sold is refused');

    // Selling more than exists must be refused while negative stock is off.
    $oversellRefused = false;
    if (!setting_bool('negative_stock_allowed', NEGATIVE_STOCK_ALLOWED)) {
        $saleId2 = sale_open((int) $session['id']);
        try {
            sale_add_line($saleId2, $productId, 9999);
        } catch (Throwable) {
            $oversellRefused = true;
        }
        sale_void($saleId2, 'TILL_ERROR', 'Smoke test cleanup', (int) $admin['id']);
    } else {
        $oversellRefused = true;   // not applicable; the setting permits it
    }
    ok($oversellRefused, 'Selling more than is on hand is refused');

    // Self-approval must be refused by the control, not merely discouraged.
    $sodRefused = false;
    try {
        sod_assert_different((int) $admin['id'], (int) $admin['id'], 'approve this');
    } catch (SodViolation) {
        $sodRefused = true;
    }
    ok($sodRefused, 'Segregation of duties refuses self-approval');

    // Close the till and check the cash arithmetic end to end.
    $cash   = session_expected_cash((int) $session['id']);
    $closed = session_close((int) $session['id'], (float) $cash['expected'],
        (int) $admin['id'], 'Smoke test close');
    ok(near((float) $closed['variance'], 0.0),
        'Declaring the expected cash gives a zero variance',
        'variance ' . $closed['variance']);
    ok($closed['within_tolerance'] === true, 'A zero variance closes within tolerance');

    // And the invariants must still hold after all of that.
    $postDiscrepancies = stock_ledger_discrepancies();
    ok($postDiscrepancies === [],
        'The ledger still reconciles after the live postings',
        $postDiscrepancies ? count($postDiscrepancies) . ' line(s) out' : '');

    $postChain = audit_verify_chain();
    ok($postChain['result'] !== 'broken',
        'The audit chain still verifies after the live postings',
        number_format((int) $postChain['rows_checked']) . ' entries');

    $batchDrift = (int) qv(
        "SELECT COUNT(*) FROM stock_levels sl
           JOIN products p ON p.id = sl.product_id
           LEFT JOIN (SELECT product_id, store_id, SUM(qty_on_hand) AS total
                        FROM stock_batches GROUP BY product_id, store_id) b
                  ON b.product_id = sl.product_id AND b.store_id = sl.store_id
          WHERE p.track_batches = 1 AND sl.qty_on_hand >= 0
            AND ABS(sl.qty_on_hand - COALESCE(b.total, 0)) > 0.0005", [], 0);
    ok($batchDrift === 0, 'The batch sub-ledger still reconciles after the live postings',
        $batchDrift > 0 ? $batchDrift . ' line(s) out of step' : '');

    // Retire the throwaway product. Its stock and movements stay - they are real
    // postings, and deleting them would break the ledger invariant this test
    // exists to prove - but it should not clutter the sellable product file.
    db_update('products', ['is_active' => 0], 'id = :id', ['id' => $productId]);
} catch (Throwable $e) {
    ok(false, 'The live engine test ran to completion',
        get_class($e) . ': ' . $e->getMessage()
        . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
}

/* ================================================================== */
/* Result                                                             */
/* ================================================================== */
echo PHP_EOL . str_repeat('=', 72) . PHP_EOL;
printf("%d assertions: \033[32m%d passed\033[0m, %s%d failed\033[0m%s",
    $passed + $failed, $passed,
    $failed > 0 ? "\033[31m" : "\033[32m", $failed, PHP_EOL);

if ($failed > 0) {
    echo PHP_EOL . "Failures:" . PHP_EOL;
    foreach ($failures as $f) {
        echo '  - ' . $f . PHP_EOL;
    }
}
echo str_repeat('=', 72) . PHP_EOL;

if ($scratchStore !== null) {
    echo 'Note: the live engine test left records in the inactive "Smoke Test Store".' . PHP_EOL
       . 'That is deliberate - they are real postings and deleting them would break' . PHP_EOL
       . 'the very ledger invariant this test exists to prove.' . PHP_EOL;
}

exit($failed > 0 ? 1 : 0);
