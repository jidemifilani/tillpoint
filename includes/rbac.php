<?php
/**
 * Role-based access control, plus the segregation-of-duties rules that sit on
 * top of it.
 *
 * Two different questions, deliberately answered by two different functions:
 *
 *   can('refund.approve')            - may this role ever do this?
 *   sod_assert_different($a, $b, ..) - may THIS person do it to THIS document?
 *
 * A permission check alone would let a supervisor approve the refund they
 * themselves raised, which is the single commonest way a till is robbed. Every
 * approval path therefore passes both gates.
 */
declare(strict_types=1);

/**
 * The permission catalogue. Code is the source of truth; install.php syncs it
 * into the `permissions` table so role editing has something to list, and so a
 * permission that is removed from here stops being grantable.
 */
function permission_catalogue(): array
{
    return [
        'pos' => [
            'pos.operate'           => 'Operate a till',
            'pos.session.open'      => 'Open a till session',
            'pos.session.close'     => 'Close own till session',
            'pos.session.reconcile' => 'Reconcile a till variance',
            'pos.void'              => 'Void a sale',
            'pos.discount'          => 'Apply a line discount',
            'pos.price_override'    => 'Override a shelf price',
            'pos.cash_movement'     => 'Record pay-in, pay-out or drop',
            'pos.no_sale'           => 'Open the drawer without a sale',
            'refund.request'        => 'Raise a refund',
            'refund.approve'        => 'Approve a refund',
        ],
        'sales' => [
            'sales.view'         => 'View sales and receipts',
            'promotions.view'    => 'View promotions',
            'promotions.manage'  => 'Create and edit promotions',
            'customers.view'     => 'View customers and loyalty',
            'customers.manage'   => 'Create and edit customers',
            'vouchers.manage'    => 'Issue and cancel vouchers',
        ],
        'inventory' => [
            'inventory.view'      => 'View stock positions',
            'products.view'       => 'View the product file',
            'products.manage'     => 'Create and edit products',
            'products.price'      => 'Change cost or shelf price',
            'stock.adjust'        => 'Raise a stock adjustment',
            'stock.adjust.approve'=> 'Approve a stock adjustment',
            'wastage.log'         => 'Log wastage',
            'wastage.approve'     => 'Approve wastage',
            'count.create'        => 'Open a stock count',
            'count.enter'         => 'Enter counted quantities',
            'count.approve'       => 'Approve and post a count variance',
            'transfer.create'     => 'Raise a stock transfer',
            'transfer.dispatch'   => 'Dispatch a transfer',
            'transfer.receive'    => 'Receive a transfer',
        ],
        'purchasing' => [
            'purchasing.view'   => 'View purchase orders',
            'po.create'         => 'Raise a purchase order',
            'po.approve'        => 'Approve a purchase order',
            'grn.create'        => 'Record goods received',
            'grn.post'          => 'Post a goods receipt to stock',
            'invoice.enter'     => 'Enter and match a supplier invoice',
            'invoice.approve'   => 'Approve an invoice for payment',
            'suppliers.view'    => 'View suppliers',
            'suppliers.manage'  => 'Create and edit suppliers',
        ],
        'audit' => [
            'audit.view'      => 'View the audit centre',
            'audit.trail'     => 'Read the audit trail',
            'audit.review'    => 'Review and dispose of exceptions',
            'audit.integrity' => 'Run an audit-log integrity check',
            'audit.rules'     => 'Change audit rule thresholds',
            'audit.scan'      => 'Run the exception scan',
        ],
        'reports' => [
            'reports.view'      => 'View operational reports',
            'reports.financial' => 'View margin, valuation and VAT reports',
        ],
        'admin' => [
            'admin.users'    => 'Manage users',
            'admin.roles'    => 'Manage roles and permissions',
            'admin.stores'   => 'Manage stores and terminals',
            'admin.settings' => 'Change system settings',
            'admin.security' => 'View sign-in security',
            'admin.dayend'   => 'Close a trading day',
        ],
    ];
}

function permission_flat(): array
{
    $out = [];
    foreach (permission_catalogue() as $module => $perms) {
        foreach ($perms as $code => $label) {
            $out[$code] = ['module' => $module, 'label' => $label];
        }
    }
    return $out;
}

/**
 * Role templates used by install.php. The auditor row is the interesting one:
 * it has read access to everything and review rights, and no permission that
 * creates or approves a transaction. An auditor who can post stock is not an
 * auditor, so the restriction is in the role rather than left to discipline.
 */
function role_templates(): array
{
    $all = array_keys(permission_flat());

    $cashier = [
        'pos.operate', 'pos.session.open', 'pos.session.close', 'pos.no_sale',
        'refund.request', 'sales.view', 'customers.view', 'products.view',
        'inventory.view', 'promotions.view',
    ];

    $supervisor = array_merge($cashier, [
        'pos.void', 'pos.discount', 'pos.price_override', 'pos.cash_movement',
        'refund.approve', 'pos.session.reconcile', 'customers.manage',
        'wastage.log', 'count.enter', 'transfer.receive', 'reports.view',
        'vouchers.manage',
    ]);

    $stock = [
        'inventory.view', 'products.view', 'products.manage', 'stock.adjust',
        'wastage.log', 'count.create', 'count.enter', 'transfer.create',
        'transfer.dispatch', 'transfer.receive', 'purchasing.view', 'po.create',
        'grn.create', 'grn.post', 'invoice.enter', 'suppliers.view', 'suppliers.manage',
        'reports.view', 'sales.view',
    ];

    $manager = array_values(array_unique(array_merge($supervisor, $stock, [
        'products.price', 'stock.adjust.approve', 'wastage.approve',
        'count.approve', 'po.approve', 'invoice.approve', 'promotions.manage',
        'reports.financial', 'audit.view', 'audit.trail', 'audit.review',
        'admin.dayend', 'admin.security',
    ])));

    $auditor = [
        'sales.view', 'inventory.view', 'products.view', 'purchasing.view',
        'suppliers.view', 'customers.view', 'promotions.view',
        'reports.view', 'reports.financial',
        'audit.view', 'audit.trail', 'audit.review', 'audit.integrity',
        'audit.scan', 'admin.security',
    ];

    $buyer = [
        'purchasing.view', 'po.create', 'suppliers.view', 'suppliers.manage',
        'products.view', 'inventory.view', 'reports.view',
    ];

    return [
        'admin' => [
            'name' => 'System Administrator',
            'description' => 'Full access, including users, roles and settings.',
            'permissions' => $all,
        ],
        'manager' => [
            'name' => 'Store Manager',
            'description' => 'Runs a store: approvals, pricing, day end and exception review.',
            'permissions' => $manager,
        ],
        'supervisor' => [
            'name' => 'Till Supervisor',
            'description' => 'Authorises overrides, voids and refunds at the till.',
            'permissions' => $supervisor,
        ],
        'cashier' => [
            'name' => 'Cashier',
            'description' => 'Operates a till. Cannot discount, void or approve.',
            'permissions' => $cashier,
        ],
        'stock' => [
            'name' => 'Stock Controller',
            'description' => 'Goods in, transfers, counts and the product file.',
            'permissions' => $stock,
        ],
        'buyer' => [
            'name' => 'Buyer',
            'description' => 'Raises purchase orders. Cannot approve them or receive goods.',
            'permissions' => $buyer,
        ],
        'auditor' => [
            'name' => 'Internal Auditor',
            'description' => 'Reads everything, reviews exceptions, posts nothing.',
            'permissions' => $auditor,
        ],
    ];
}

/* ------------------------------------------------------------------ */
/* Runtime checks                                                     */
/* ------------------------------------------------------------------ */

/**
 * Permission codes held by the signed-in user, loaded once per user.
 *
 * Keyed by user id rather than cached flat, because the seeder and the CLI
 * tools act as several users in one process. A flat cache would answer the
 * first user's permissions for everyone after them, which in a file full of
 * authorisation checks is the worst possible place for a stale read.
 */
function my_permissions(): array
{
    static $cache = [];
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if ($uid === 0) {
        return [];
    }
    if (isset($cache[$uid])) {
        return $cache[$uid];
    }
    $rows = qcol(
        'SELECT rp.permission_code
           FROM role_permissions rp
           JOIN users u ON u.role_id = rp.role_id
          WHERE u.id = ?',
        [$uid]
    );
    return $cache[$uid] = array_fill_keys($rows, true);
}

function can(string $permission): bool
{
    return isset(my_permissions()[$permission]);
}

/** True if the user holds any one of the listed permissions. */
function can_any(array $permissions): bool
{
    foreach ($permissions as $p) {
        if (can($p)) {
            return true;
        }
    }
    return false;
}

/** Stop the request with a 403 unless the permission is held. */
function require_can(string $permission): void
{
    if (!can($permission)) {
        deny_403('You do not have the "' . $permission . '" permission.');
    }
}

function deny_403(string $reason): never
{
    http_response_code(403);
    $GLOBALS['__deny_reason'] = $reason;
    require APP_ROOT . '/403.php';
    exit;
}

/* ------------------------------------------------------------------ */
/* Segregation of duties                                              */
/* ------------------------------------------------------------------ */

/**
 * Refuse when the same person would stand on both sides of a control.
 *
 * Throws rather than returning false: every caller is a posting routine inside
 * a transaction, and a control that can be ignored by not checking the return
 * value is not a control. The thrown message is shown to the user as-is.
 */
function sod_assert_different(?int $actorId, ?int $originatorId, string $what): void
{
    if ($actorId !== null && $originatorId !== null && $actorId === $originatorId) {
        throw new SodViolation(
            "Segregation of duties: you cannot $what, because you raised it. "
            . 'Another authorised user must act on this.'
        );
    }
}

/**
 * The one exception, made explicit rather than by omission: a sole-manager store
 * where nobody else holds the permission would otherwise deadlock. Allowing it
 * silently would hide the breach, so it is allowed, logged as a warning and
 * raised as an audit exception for review.
 */
function sod_allow_with_override(?int $actorId, ?int $originatorId, string $what, string $entity, ?int $entityId): bool
{
    if ($actorId === null || $originatorId === null || $actorId !== $originatorId) {
        return false;
    }
    audit_log('sod.override', $entity, $entityId,
        "Self-approval permitted under override: $what", null, null, 'critical');
    audit_raise_exception('SOD_SELF_APPROVAL', [
        'entity'       => $entity,
        'entity_id'    => $entityId,
        'subject_user' => $actorId,
        'summary'      => "Self-approval of $entity #$entityId: $what",
        'metric'       => 1,
    ]);
    return true;
}

class SodViolation extends RuntimeException
{
}
