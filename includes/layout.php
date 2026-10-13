<?php
/**
 * Page chrome: sidebar, topbar, flash messages, footer.
 * Pages call layout_head([...]) then layout_foot().
 */
declare(strict_types=1);

/**
 * The navigation tree. Each entry names the permission that reveals it, so the
 * menu a user sees is derived from what they may actually do - a link to a page
 * that will 403 is worse than no link.
 */
function nav_modules(): array
{
    return [
        [
            'key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'grid',
            'url' => base_url(''), 'cap' => null, 'children' => [],
        ],
        [
            'key' => 'pos', 'label' => 'Till', 'icon' => 'till', 'cap' => 'pos.operate',
            'url' => base_url('pos/'),
            'children' => [
                ['label' => 'Serve a customer', 'url' => base_url('pos/')],
                ['label' => 'My till session',  'url' => base_url('pos/session')],
                ['label' => 'Cash drawer',      'url' => base_url('pos/cash')],
                ['label' => 'Session history',  'url' => base_url('pos/sessions')],
            ],
        ],
        [
            'key' => 'sales', 'label' => 'Sales', 'icon' => 'receipt', 'cap' => 'sales.view',
            'url' => base_url('sales/'),
            'children' => [
                ['label' => 'Sales register', 'url' => base_url('sales/')],
                ['label' => 'Refunds',        'url' => base_url('sales/refunds'),    'cap' => 'refund.request'],
                ['label' => 'Promotions',     'url' => base_url('sales/promotions'), 'cap' => 'promotions.view'],
                ['label' => 'Customers',      'url' => base_url('sales/customers'),  'cap' => 'customers.view'],
                ['label' => 'Vouchers',       'url' => base_url('sales/vouchers'),   'cap' => 'vouchers.manage'],
            ],
        ],
        [
            'key' => 'inventory', 'label' => 'Inventory', 'icon' => 'box', 'cap' => 'inventory.view',
            'url' => base_url('inventory/'),
            'children' => [
                ['label' => 'Overview',        'url' => base_url('inventory/')],
                ['label' => 'Products',        'url' => base_url('inventory/products'),  'cap' => 'products.view'],
                ['label' => 'Stock positions', 'url' => base_url('inventory/stock')],
                ['label' => 'Batches & expiry','url' => base_url('inventory/batches')],
                ['label' => 'Stock ledger',    'url' => base_url('inventory/movements')],
                ['label' => 'Replenishment',   'url' => base_url('inventory/reorder')],
                ['label' => 'Purchase orders', 'url' => base_url('inventory/purchase-orders'), 'cap' => 'purchasing.view'],
                ['label' => 'Goods received',  'url' => base_url('inventory/grns'),      'cap' => 'grn.create'],
                ['label' => 'Supplier invoices','url' => base_url('inventory/invoices'), 'cap' => 'invoice.enter'],
                ['label' => 'Transfers',       'url' => base_url('inventory/transfers'), 'cap' => 'transfer.create'],
                ['label' => 'Stock counts',    'url' => base_url('inventory/counts'),    'cap' => 'count.enter'],
                ['label' => 'Wastage',         'url' => base_url('inventory/wastage'),   'cap' => 'wastage.log'],
                ['label' => 'Adjustments',     'url' => base_url('inventory/adjustments'), 'cap' => 'stock.adjust'],
                ['label' => 'Suppliers',       'url' => base_url('inventory/suppliers'), 'cap' => 'suppliers.view'],
                ['label' => 'Categories',      'url' => base_url('inventory/categories'),'cap' => 'products.manage'],
            ],
        ],
        [
            'key' => 'audit', 'label' => 'Audit', 'icon' => 'shield', 'cap' => 'audit.view',
            'url' => base_url('audit/'),
            'children' => [
                ['label' => 'Audit centre',      'url' => base_url('audit/')],
                ['label' => 'Exception queue',   'url' => base_url('audit/exceptions')],
                ['label' => 'Cashier scorecard', 'url' => base_url('audit/cashier-scorecard')],
                ['label' => 'Till variances',    'url' => base_url('audit/till-variances')],
                ['label' => 'Shrinkage',         'url' => base_url('audit/shrinkage')],
                ['label' => 'Duty segregation',  'url' => base_url('audit/sod')],
                ['label' => 'Audit trail',       'url' => base_url('audit/trail'),     'cap' => 'audit.trail'],
                ['label' => 'Trail integrity',   'url' => base_url('audit/integrity'), 'cap' => 'audit.integrity'],
                ['label' => 'Rule thresholds',   'url' => base_url('audit/rules'),     'cap' => 'audit.rules'],
            ],
        ],
        [
            'key' => 'reports', 'label' => 'Reports', 'icon' => 'chart', 'cap' => 'reports.view',
            'url' => base_url('reports/'),
            'children' => [
                ['label' => 'Report index',     'url' => base_url('reports/')],
                ['label' => 'Daily sales (Z)',  'url' => base_url('reports/daily-sales')],
                ['label' => 'Margin analysis',  'url' => base_url('reports/margin'),     'cap' => 'reports.financial'],
                ['label' => 'Stock valuation',  'url' => base_url('reports/valuation'),  'cap' => 'reports.financial'],
                ['label' => 'VAT summary',      'url' => base_url('reports/vat'),        'cap' => 'reports.financial'],
                ['label' => 'Expiring stock',   'url' => base_url('reports/expiring')],
                ['label' => 'Slow movers',      'url' => base_url('reports/slow-movers')],
                ['label' => 'Supplier scorecard','url' => base_url('reports/suppliers')],
                ['label' => 'Hourly trade',     'url' => base_url('reports/hourly')],
            ],
        ],
        [
            'key' => 'admin', 'label' => 'Administration', 'icon' => 'cog',
            'url' => base_url('admin/'),
            'cap_any' => ['admin.users', 'admin.stores', 'admin.settings', 'admin.dayend', 'admin.security'],
            'children' => [
                ['label' => 'Overview',       'url' => base_url('admin/')],
                ['label' => 'Users',          'url' => base_url('admin/users'),     'cap' => 'admin.users'],
                ['label' => 'Roles',          'url' => base_url('admin/roles'),     'cap' => 'admin.roles'],
                ['label' => 'Stores & tills', 'url' => base_url('admin/stores'),    'cap' => 'admin.stores'],
                ['label' => 'Reason codes',   'url' => base_url('admin/reason-codes'), 'cap' => 'admin.settings'],
                ['label' => 'Settings',       'url' => base_url('admin/settings'),  'cap' => 'admin.settings'],
                ['label' => 'Sign-in security','url' => base_url('admin/security'), 'cap' => 'admin.security'],
                ['label' => 'Day end',        'url' => base_url('admin/day-end'),   'cap' => 'admin.dayend'],
            ],
        ],
    ];
}

function nav_visible(array $item): bool
{
    if (!empty($item['cap_any'])) {
        return can_any($item['cap_any']);
    }
    return empty($item['cap']) || can($item['cap']);
}

/** Inline SVG icons. No icon font and no external request. */
function nav_icon(string $name): string
{
    $paths = [
        'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'till'    => '<rect x="2" y="9" width="20" height="12" rx="2"/><path d="M6 9V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v4"/><path d="M7 14h4"/>',
        'receipt' => '<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-3-2z"/><path d="M9 8h6M9 12h6"/>',
        'box'     => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'shield'  => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'chart'   => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'cog'     => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
    ];
    $d = $paths[$name] ?? $paths['grid'];
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

/** Which top-level module is the current URL in? Used to open the right branch. */
function nav_active_key(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $rel  = trim(str_replace(BASE_PATH, '', $path), '/');
    $seg  = explode('/', $rel)[0] ?? '';
    return in_array($seg, ['pos', 'sales', 'inventory', 'audit', 'reports', 'admin'], true) ? $seg : 'dashboard';
}

function layout_head(array $opts = []): void
{
    $title    = (string) ($opts['title'] ?? SITE_NAME);
    $subtitle = (string) ($opts['subtitle'] ?? '');
    $actions  = (string) ($opts['actions'] ?? '');
    $wide     = !empty($opts['wide']);
    $bare     = !empty($opts['bare']);     // the till runs without the sidebar
    $u        = current_user();
    $store    = current_store();
    $active   = nav_active_key();
    $notes    = $u ? my_notifications() : [];

    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> &middot; <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('css/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/print.css')) ?>" media="print">
<link rel="icon" href="<?= e(asset_url('img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="<?= $bare ? 'bare' : '' ?>">
<a class="skip" href="#main">Skip to main content</a>
<?php if (!$bare && $u): ?>
<aside class="sidebar" id="sidebar">
  <div class="brand">
    <?= brand_mark() ?>
    <div class="brand__text">
      <strong><?= e(SITE_NAME) ?></strong>
      <span><?= e($store['name'] ?? 'No store') ?></span>
    </div>
  </div>
  <nav class="nav">
    <?php foreach (nav_modules() as $m):
        if (!nav_visible($m)) continue;
        $isActive = $m['key'] === $active;
        $kids = array_filter($m['children'] ?? [], 'nav_visible'); ?>
      <div class="nav__group <?= $isActive ? 'is-open' : '' ?>">
        <a class="nav__top <?= $isActive ? 'is-active' : '' ?>" href="<?= e($m['url']) ?>">
          <?= nav_icon($m['icon']) ?><span><?= e($m['label']) ?></span>
        </a>
        <?php if ($kids && $isActive): ?>
          <div class="nav__kids">
            <?php foreach ($kids as $k): ?>
              <a href="<?= e($k['url']) ?>"><?= e($k['label']) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar__foot">
    <a class="nav__top" href="<?= e(base_url('profile')) ?>">
      <span class="avatar"><?= e(strtoupper(substr((string) $u['full_name'], 0, 1))) ?></span>
      <span class="who"><strong><?= e($u['full_name']) ?></strong><em><?= e($u['role_name']) ?></em></span>
    </a>
    <a class="nav__out" href="<?= e(base_url('logout')) ?>">Sign out</a>
  </div>
</aside>
<?php endif; ?>

<div class="shell <?= $bare ? 'shell--bare' : '' ?>">
<?php if ($u): ?>
  <header class="topbar">
    <div class="topbar__title">
      <h1><?= e($title) ?></h1>
      <?php if ($subtitle !== ''): ?><p><?= e($subtitle) ?></p><?php endif; ?>
    </div>
    <div class="topbar__right">
      <?php if ($bare): ?>
        <a class="btn btn--ghost" href="<?= e(base_url('')) ?>">Leave the till</a>
      <?php endif; ?>
      <?php
      $stores = my_stores();
      if (count($stores) > 1 && $u['store_id'] === null): ?>
        <form method="post" action="<?= e(base_url('switch-store')) ?>" class="storepick">
          <?= csrf_field() ?>
          <select name="store_id" onchange="this.form.submit()" aria-label="Change store">
            <?php foreach ($stores as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === current_store_id() ? 'selected' : '' ?>>
                <?= e($s['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
      <?php if ($notes): ?>
        <details class="bell">
          <summary aria-label="<?= count($notes) ?> unread notifications">
            <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21a2 2 0 0 0 4 0"/></svg>
            <span class="bell__count"><?= count($notes) ?></span>
          </summary>
          <div class="bell__panel">
            <?php foreach ($notes as $n): ?>
              <a class="note note--<?= e($n['severity']) ?>" href="<?= e($n['link'] ?: base_url('')) ?>">
                <strong><?= e($n['title']) ?></strong>
                <?php if ($n['body']): ?><span><?= e($n['body']) ?></span><?php endif; ?>
                <em><?= e(ago((string) $n['created_at'])) ?></em>
              </a>
            <?php endforeach; ?>
            <form method="post" action="<?= e(base_url('notifications-read')) ?>">
              <?= csrf_field() ?>
              <button class="btn btn--ghost btn--sm" type="submit">Mark all read</button>
            </form>
          </div>
        </details>
      <?php endif; ?>
      <?= $actions ?>
    </div>
  </header>
<?php endif; ?>

<main id="main" class="main <?= $wide ? 'main--wide' : '' ?>">
<?php foreach (flash_take() as $f): ?>
  <div class="alert alert--<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<?php
}

function layout_foot(): void
{
    ?>
</main>
<footer class="foot">
  <span><?= e(SITE_NAME) ?> &middot; <?= e(SITE_TAGLINE) ?></span>
  <span><?= e(COMPANY_NAME) ?> &middot; <?= date('d M Y, H:i') ?></span>
</footer>
</div>
<script src="<?= e(asset_url('js/app.js')) ?>"></script>
</body>
</html>
<?php
}

/** The logo. Drawn, not an image file, so it scales and needs no upload. */
function brand_mark(): string
{
    return <<<'SVG'
<svg class="brand__mark" viewBox="0 0 40 40" role="img" aria-label="TillPoint">
  <rect x="2" y="2" width="36" height="36" rx="9" fill="#0d7a5f"/>
  <path d="M10 24h20v6a2 2 0 0 1-2 2H12a2 2 0 0 1-2-2z" fill="#ffffff" opacity=".92"/>
  <path d="M13 24V13a3 3 0 0 1 3-3h8a3 3 0 0 1 3 3v11" fill="none" stroke="#ffffff" stroke-width="2.4" stroke-linejoin="round"/>
  <path d="M17 17h6" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round"/>
  <circle cx="20" cy="28.5" r="1.9" fill="#0d7a5f"/>
</svg>
SVG;
}

/* ------------------------------------------------------------------ */
/* Small building blocks used across pages                            */
/* ------------------------------------------------------------------ */

/** A headline figure. $tone colours the delta line, not the number. */
function tile(string $label, string $value, string $sub = '', string $tone = ''): string
{
    $out  = '<div class="tile ' . ($tone !== '' ? 'tile--' . e($tone) : '') . '">';
    $out .= '<span class="tile__label">' . e($label) . '</span>';
    $out .= '<strong class="tile__value">' . e($value) . '</strong>';
    if ($sub !== '') {
        $out .= '<span class="tile__sub">' . e($sub) . '</span>';
    }
    return $out . '</div>';
}

function card_open(string $title = '', string $actions = ''): string
{
    $out = '<section class="card">';
    if ($title !== '' || $actions !== '') {
        $out .= '<header class="card__head"><h2>' . e($title) . '</h2>'
              . ($actions !== '' ? '<div class="card__actions">' . $actions . '</div>' : '')
              . '</header>';
    }
    return $out . '<div class="card__body">';
}

function card_close(): string
{
    return '</div></section>';
}

function empty_state(string $message, string $hint = ''): string
{
    return '<div class="empty"><p>' . e($message) . '</p>'
         . ($hint !== '' ? '<p class="empty__hint">' . e($hint) . '</p>' : '') . '</div>';
}

/** A labelled form row. */
function field(string $label, string $input, string $hint = ''): string
{
    return '<label class="field"><span class="field__label">' . e($label) . '</span>' . $input
         . ($hint !== '' ? '<span class="field__hint">' . e($hint) . '</span>' : '') . '</label>';
}

function select_options(array $options, $selected, bool $withBlank = false, string $blankLabel = '-- choose --'): string
{
    $out = $withBlank ? '<option value="">' . e($blankLabel) . '</option>' : '';
    foreach ($options as $value => $label) {
        $sel = ((string) $value === (string) $selected) ? ' selected' : '';
        $out .= '<option value="' . e((string) $value) . '"' . $sel . '>' . e((string) $label) . '</option>';
    }
    return $out;
}

/** A compact right-aligned number cell, for money and quantity columns. */
function num_cell($v, bool $isMoney = true, bool $signed = false): string
{
    $f = (float) $v;
    $cls = $f < 0 ? ' class="num neg"' : ' class="num"';
    $txt = $isMoney ? money(abs($f)) : qty_fmt(abs($f));
    if ($f < 0) {
        $txt = '-' . $txt;
    } elseif ($signed && $f > 0) {
        $txt = '+' . $txt;
    }
    return '<td' . $cls . '>' . e($txt) . '</td>';
}
