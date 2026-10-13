<?php
/**
 * Product search for the till's keyboard lookup.
 *
 * JSON, same-origin, session-authenticated and permission-checked like any page.
 * It returns only what the till needs to put an item in the basket - no cost and
 * no margin, because the till screen is visible to whoever is standing at it.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in.', 'items' => []]);
    exit;
}
if (!can('pos.operate') && !can('products.view')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not permitted.', 'items' => []]);
    exit;
}

// A till types a few characters per basket; 120 lookups a minute is far more
// than any cashier generates and far less than it takes to walk the product
// file. Refusing is deliberately soft - the scan path does not use this
// endpoint, so a throttled lookup never stops a sale going through.
if (!throttle('product_lookup', 120, 60)) {
    http_response_code(429);
    header('Retry-After: 30');
    echo json_encode([
        'ok'    => false,
        'error' => 'Too many lookups. Wait a moment, or scan the barcode instead.',
        'items' => [],
    ]);
    exit;
}

$term    = trim((string) get('q'));
$storeId = current_store_id();

if (mb_strlen($term) < 2 || $storeId === null) {
    echo json_encode(['ok' => true, 'items' => []]);
    exit;
}

$items = [];
foreach (pos_search_products($term, $storeId, 20) as $p) {
    $onHand = (float) $p['qty_on_hand'];
    $items[] = [
        // The code the till will submit: the barcode where there is one, so the
        // scan path and the click path resolve through exactly the same lookup.
        'code'  => (string) ($p['barcode'] ?? $p['sku']),
        'sku'   => (string) $p['sku'],
        'name'  => (string) $p['name'] . ((int) $p['age_restricted'] === 1 ? ' (age restricted)' : ''),
        'price' => money((float) $p['sell_price']),
        'stock' => $onHand > 0
            ? qty_fmt($onHand) . ' ' . strtolower((string) $p['unit_code'])
            : 'none on hand',
    ];
}

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
