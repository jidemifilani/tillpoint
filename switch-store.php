<?php
/**
 * Change the store a head-office user is working in.
 *
 * The target is checked against my_stores() rather than trusted: the select on
 * the topbar is a convenience, and the authorisation has to be here.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
csrf_require();

$storeId = post_int('store_id');
if (switch_store($storeId)) {
    audit_log('store.switch', 'store', $storeId, 'Switched working store');
    flash_ok('Now working in ' . (string) qv('SELECT name FROM stores WHERE id = ?', [$storeId], '') . '.');
} else {
    flash_err('You are not assigned to that store.');
}
redirect($_SERVER['HTTP_REFERER'] ?? '');
