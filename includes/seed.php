<?php
/**
 * Reference data and demonstration data.
 *
 * Split from install.php so the same code seeds a fresh install and sets up the
 * smoke test's fixtures. Everything here is written with "insert if absent,
 * leave alone if present" semantics, so re-running it on a live database adds
 * what is missing and overwrites nothing an operator has since tuned.
 */
declare(strict_types=1);

/* ================================================================== */
/* REFERENCE DATA - required for the app to function                  */
/* ================================================================== */

function seed_reference(): array
{
    $log = [];
    $log[] = 'Units: ' . seed_units();
    $log[] = 'Permissions: ' . seed_permissions();
    $log[] = 'Roles: ' . seed_roles();
    $log[] = 'Reason codes: ' . seed_reason_codes();
    $log[] = 'Audit rules: ' . seed_audit_rules();
    $log[] = 'Settings: ' . seed_settings();
    return $log;
}

function seed_units(): string
{
    $units = [
        ['EA',   'Each',        0],
        ['PK',   'Pack',        0],
        ['CTN',  'Carton',      0],
        ['CRT',  'Crate',       0],
        ['BTL',  'Bottle',      0],
        ['SACH', 'Sachet',      0],
        ['KG',   'Kilogram',    1],
        ['G',    'Gram',        1],
        ['L',    'Litre',       1],
        ['ML',   'Millilitre',  1],
        ['BAG',  'Bag',         0],
        ['TUB',  'Tub',         0],
        ['TRAY', 'Tray',        0],
        ['ROLL', 'Roll',        0],
    ];
    $n = 0;
    foreach ($units as [$code, $name, $dec]) {
        $n += q('INSERT INTO units (code, name, is_decimal) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), is_decimal = VALUES(is_decimal)',
                [$code, $name, $dec])->rowCount() > 0 ? 1 : 0;
    }
    return count($units) . ' present';
}

/**
 * Sync the permission catalogue from code into the table.
 *
 * Permissions removed from the catalogue are deleted here, which cascades to
 * role_permissions. That is deliberate: a permission the code no longer checks
 * must not keep appearing as a grantable right, because it would read as a
 * restriction that is actually enforcing nothing.
 */
function seed_permissions(): string
{
    $flat = permission_flat();
    foreach ($flat as $code => $meta) {
        q('INSERT INTO permissions (code, module, label) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE module = VALUES(module), label = VALUES(label)',
          [$code, $meta['module'], $meta['label']]);
    }
    $codes  = array_keys($flat);
    $ph     = implode(',', array_fill(0, count($codes), '?'));
    $removed = q("DELETE FROM permissions WHERE code NOT IN ($ph)", $codes)->rowCount();

    return count($flat) . ' synced' . ($removed > 0 ? ", $removed obsolete removed" : '');
}

/**
 * Create the role templates and their grants.
 *
 * On an existing install the grants are re-applied for system roles only, so a
 * new permission reaches the roles that should have it. A role an administrator
 * created themselves is never touched.
 */
function seed_roles(): string
{
    $created = 0;
    $updated = 0;
    foreach (role_templates() as $code => $tpl) {
        $existing = q1('SELECT * FROM roles WHERE code = ?', [$code]);
        if ($existing) {
            $roleId = (int) $existing['id'];
            $updated++;
        } else {
            $roleId = db_insert('roles', [
                'code'        => $code,
                'name'        => $tpl['name'],
                'description' => $tpl['description'],
                'is_system'   => 1,
            ]);
            $created++;
        }
        // Rebuild the grant set for this template role.
        q('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
        foreach ($tpl['permissions'] as $perm) {
            q('INSERT IGNORE INTO role_permissions (role_id, permission_code) VALUES (?, ?)',
              [$roleId, $perm]);
        }
    }
    return "$created created, $updated refreshed";
}

function seed_reason_codes(): string
{
    $codes = [
        // wastage
        ['EXPIRED',    'Expired / past use-by',          'wastage',        0],
        ['DAMAGED',    'Damaged in store',               'wastage',        0],
        ['SPOILED',    'Spoiled / cold chain failure',   'wastage',        1],
        ['BREAKAGE',   'Broken by customer or staff',    'wastage',        0],
        ['PEST',       'Pest or contamination',          'wastage',        1],
        ['RECALL',     'Supplier recall',                'wastage',        1],
        ['SAMPLING',   'In-store sampling / tasting',    'wastage',        0],
        ['STAFF_USE',  'Consumed in store operations',   'wastage',        1],
        // adjustment
        ['RECOUNT',    'Recount correction',             'adjustment',     1],
        ['DATA_ENTRY', 'Data entry error',               'adjustment',     1],
        ['FOUND',      'Stock found / mislaid',          'adjustment',     1],
        ['THEFT',      'Known theft',                    'adjustment',     1],
        ['OPENING',    'Opening balance',                'adjustment',     1],
        ['REPACK',     'Repacked or split pack',         'adjustment',     1],
        // void
        ['CUST_LEFT',  'Customer changed their mind',     'void',           0],
        ['WRONG_ITEM', 'Wrong item rung up',             'void',           0],
        ['TILL_ERROR', 'Till or operator error',         'void',           0],
        ['NO_PAYMENT', 'Customer could not pay',         'void',           0],
        // refund
        ['FAULTY',     'Faulty or spoiled goods',        'refund',         0],
        ['NOT_WANTED', 'Change of mind',                 'refund',         0],
        ['WRONG_SIZE', 'Wrong item or size sold',        'refund',         0],
        ['OVERCHARGE', 'Overcharged at the till',        'refund',         1],
        ['DUPLICATE',  'Duplicate transaction',          'refund',         1],
        // price override
        ['SHELF_WRONG','Shelf price differs from system','price_override', 0],
        ['MANAGER_OK', 'Manager approved price',         'price_override', 1],
        ['MARKDOWN',   'Near-expiry markdown',           'price_override', 0],
        ['DAMAGED_PKG','Damaged packaging',              'price_override', 0],
        // discount
        ['STAFF_DISC', 'Staff discount',                 'discount',       1],
        ['GOODWILL',   'Goodwill gesture',               'discount',       1],
        ['BULK',       'Bulk purchase',                  'discount',       0],
        ['LOYALTY',    'Loyalty scheme',                 'discount',       0],
        // no sale
        ['CHANGE',     'Making change',                  'no_sale',        0],
        ['DROP_PREP',  'Preparing a safe drop',          'no_sale',        0],
        ['MISCOUNT',   'Recounting the drawer',          'no_sale',        0],
        // cash
        ['PETTY',      'Petty cash purchase',            'cash',           1],
        ['SAFE_DROP',  'Drop to safe',                   'cash',           1],
        ['FLOAT_TOP',  'Float top-up',                   'cash',           1],
        ['BANKING',    'Cash to bank',                   'cash',           1],
    ];
    foreach ($codes as [$code, $label, $applies, $needs]) {
        q('INSERT INTO reason_codes (code, label, applies_to, needs_approval) VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE label = VALUES(label), applies_to = VALUES(applies_to)',
          [$code, $label, $applies, $needs]);
    }
    return count($codes) . ' present';
}

/**
 * Sync the audit rule catalogue. Thresholds are the editable part, so an
 * existing row keeps the threshold an auditor set; only the wording and
 * classification are refreshed from code.
 */
function seed_audit_rules(): string
{
    $created = 0;
    foreach (audit_rule_catalogue() as $code => $r) {
        $existing = q1('SELECT code FROM audit_rules WHERE code = ?', [$code]);
        if ($existing) {
            db_update('audit_rules', [
                'name'            => $r['name'],
                'description'     => $r['description'],
                'module'          => $r['module'],
                'threshold_label' => $r['threshold_label'],
            ], 'code = :c', ['c' => $code]);
        } else {
            db_insert('audit_rules', [
                'code'            => $code,
                'name'            => $r['name'],
                'description'     => $r['description'],
                'module'          => $r['module'],
                'severity'        => $r['severity'],
                'threshold'       => $r['threshold'],
                'threshold_label' => $r['threshold_label'],
                'is_active'       => 1,
            ]);
            $created++;
        }
    }
    return count(audit_rule_catalogue()) . " in catalogue, $created new";
}

/**
 * The tunable thresholds. Seeded from the config.php constants, after which the
 * table wins - so changing a limit in the admin screen takes effect everywhere
 * and survives the next deploy.
 */
function seed_settings(): string
{
    $defaults = [
        // security
        ['max_login_attempts',          (string) MAX_LOGIN_ATTEMPTS,     'int',     'Failed sign-ins before lockout',        'security'],
        ['lockout_minutes',             (string) LOCKOUT_MINUTES,        'int',     'Lockout length (minutes)',              'security'],
        ['idle_timeout_minutes',        (string) IDLE_TIMEOUT_MINUTES,   'int',     'Sign out after inactivity (minutes)',   'security'],
        ['password_min_length',         (string) PASSWORD_MIN_LENGTH,    'int',     'Minimum password length',               'security'],
        ['login_ip_window_minutes',     (string) LOGIN_IP_WINDOW_MINUTES,'int',     'Sign-in throttle window (minutes)',     'security'],
        ['login_ip_max_fails',          (string) LOGIN_IP_MAX_FAILS,     'int',     'Failed attempts allowed per address',   'security'],
        ['login_ip_max_identities',     (string) LOGIN_IP_MAX_IDENTITIES,'int',     'Distinct accounts per address',         'security'],
        ['login_attempt_retention_days',(string) LOGIN_ATTEMPT_RETENTION_DAYS, 'int', 'Keep sign-in attempts (days)',        'security'],

        // till
        ['till_variance_tolerance',     (string) TILL_VARIANCE_TOLERANCE,'decimal', 'Cash variance accepted without review', 'till'],
        ['refund_approval_limit',       (string) REFUND_APPROVAL_LIMIT,  'decimal', 'Refund above this needs approval',      'till'],
        ['discount_max_pct',            (string) DISCOUNT_MAX_PCT,       'decimal', 'Cashier discount ceiling (%)',          'till'],
        ['price_override_max_pct',      (string) PRICE_OVERRIDE_MAX_PCT, 'decimal', 'Maximum price override (% off list)',   'till'],
        ['trading_hour_open',           '7',                             'int',     'Trading day starts (hour, 0-23)',      'till'],
        ['trading_hour_close',          '22',                            'int',     'Trading day ends (hour, 0-23)',        'till'],

        // stock
        ['negative_stock_allowed',      NEGATIVE_STOCK_ALLOWED ? '1' : '0', 'bool', 'Allow stock to go negative',            'stock'],
        ['wastage_approval_limit',      (string) WASTAGE_APPROVAL_LIMIT, 'decimal', 'Wastage above this needs approval',     'stock'],
        ['expiry_warn_days',            (string) EXPIRY_WARN_DAYS,       'int',     'Flag stock expiring within (days)',     'stock'],
        ['default_reorder_lead_days',   (string) DEFAULT_REORDER_LEAD_DAYS, 'int',  'Default supplier lead time (days)',     'stock'],
        ['slow_mover_days',             (string) SLOW_MOVER_DAYS,        'int',     'No sale in this many days = slow mover','stock'],
        ['low_margin_warn_pct',         (string) LOW_MARGIN_WARN_PCT,    'decimal', 'Warn when margin falls below (%)',      'stock'],
        ['shrinkage_exception_value',   '20000',                         'decimal', 'Count shortfall that raises an exception','stock'],
        ['adjustment_exception_value',  '15000',                         'decimal', 'Adjustment value that raises an exception','stock'],
        ['stale_count_min_value',       '50000',                         'decimal', 'Line value worth chasing for a count',  'stock'],

        // purchasing / invoices
        ['invoice_price_tolerance_pct', '2',    'decimal', 'Invoice price variance tolerance (%)',       'purchasing'],
        ['invoice_value_tolerance',     '200',  'decimal', 'Invoice total variance accepted without review', 'purchasing'],
        ['invoice_overdue_days',        '45',   'int',     'Flag unbilled deliveries older than (days)', 'purchasing'],

        // loyalty
        ['loyalty_points_per_unit',     (string) LOYALTY_POINTS_PER_UNIT,'decimal', 'Spend per 1 point earned',              'loyalty'],
        ['loyalty_point_value',         (string) LOYALTY_POINT_VALUE,    'decimal', 'Redemption value of 1 point',           'loyalty'],
        // Measured from the customer's last visit, not from when each point was
        // earned: someone who shops monthly should never lose points, and the
        // liability should not grow forever on accounts nobody uses. Zero is off.
        ['loyalty_expiry_months',       '24',                            'int',     'Expire points after months of no visits','loyalty'],

        // receipts
        ['receipt_footer',              RECEIPT_FOOTER,                  'string',  'Receipt footer message',                'receipt'],
        ['store_tagline',               SITE_TAGLINE,                    'string',  'Tagline on printed documents',          'receipt'],
    ];
    foreach ($defaults as [$key, $value, $type, $label, $cat]) {
        // INSERT IGNORE, not upsert: a value an operator has changed must not be
        // reset to the shipped default by a later install run.
        q('INSERT IGNORE INTO settings (skey, svalue, value_type, label, category)
                VALUES (?, ?, ?, ?, ?)',
          [$key, $value, $type, $label, $cat]);
        // The label and grouping are presentation, so those do get refreshed.
        q('UPDATE settings SET label = ?, category = ?, value_type = ? WHERE skey = ?',
          [$label, $cat, $type, $key]);
    }
    return count($defaults) . ' present';
}

/* ================================================================== */
/* DEMONSTRATION DATA                                                 */
/* ================================================================== */

/**
 * A working supermarket: two branches, staff in every role, a product file with
 * real categories, opening stock, and a fortnight of trading history so the
 * dashboards, margin reports and exception rules all have something to show.
 *
 * Every figure is posted through the ordinary routines - stock_receive(),
 * sale_complete() - rather than written straight into the tables. That takes
 * longer to run and is the only way the demo data obeys the same invariants as
 * real data, which is what makes the smoke test meaningful.
 */
function seed_demo(array $opts = []): array
{
    $log = [];

    /* ---- Stores and terminals ---------------------------------- */
    $stores = [
        ['IKJ', 'Ikeja Branch',  '14 Obafemi Awolowo Way, Ikeja, Lagos', '0801 234 5678', 0],
        ['LEK', 'Lekki Branch',  'Admiralty Way, Lekki Phase 1, Lagos',  '0801 234 5679', 0],
        ['WH',  'Central Depot', 'Plot 7 Acme Road, Ogba, Lagos',        '0801 234 5670', 1],
    ];
    $storeIds = [];
    foreach ($stores as [$code, $name, $addr, $phone, $isWh]) {
        $existing = qv('SELECT id FROM stores WHERE code = ?', [$code]);
        if ($existing) {
            $storeIds[$code] = (int) $existing;
            continue;
        }
        $storeIds[$code] = db_insert('stores', [
            'code' => $code, 'name' => $name, 'address' => $addr,
            'phone' => $phone, 'is_warehouse' => $isWh,
            'tin' => 'TIN-' . strtoupper($code) . '-0041',
        ]);
    }
    $log[] = count($storeIds) . ' stores';

    $terminals = [
        ['IKJ-T1', 'Ikeja Till 1', 'IKJ'], ['IKJ-T2', 'Ikeja Till 2', 'IKJ'],
        ['IKJ-T3', 'Ikeja Till 3', 'IKJ'], ['LEK-T1', 'Lekki Till 1', 'LEK'],
        ['LEK-T2', 'Lekki Till 2', 'LEK'],
    ];
    foreach ($terminals as [$code, $name, $store]) {
        q('INSERT IGNORE INTO terminals (code, name, store_id) VALUES (?, ?, ?)',
          [$code, $name, $storeIds[$store]]);
    }
    $log[] = count($terminals) . ' terminals';

    /* ---- Staff -------------------------------------------------- */
    // PINs are set only for the roles that authorise overrides; a cashier with
    // an override PIN would defeat the point of having one.
    $people = [
        ['EMP001', 'Adaeze Nwosu',     'adaeze@tillpoint.test',  'manager',    'IKJ', '7391'],
        ['EMP002', 'Tunde Bakare',     'tunde@tillpoint.test',   'supervisor', 'IKJ', '4826'],
        ['EMP003', 'Chioma Eze',       'chioma@tillpoint.test',  'cashier',    'IKJ', null],
        ['EMP004', 'Musa Abdullahi',   'musa@tillpoint.test',    'cashier',    'IKJ', null],
        ['EMP005', 'Blessing Okafor',  'blessing@tillpoint.test','cashier',    'IKJ', null],
        ['EMP006', 'Segun Adeyemi',    'segun@tillpoint.test',   'stock',      'IKJ', null],
        ['EMP007', 'Funke Adebayo',    'funke@tillpoint.test',   'manager',    'LEK', '5172'],
        ['EMP008', 'Ibrahim Yusuf',    'ibrahim@tillpoint.test', 'supervisor', 'LEK', '8364'],
        ['EMP009', 'Ngozi Okonkwo',    'ngozi@tillpoint.test',   'cashier',    'LEK', null],
        ['EMP010', 'Emeka Obi',        'emeka@tillpoint.test',   'cashier',    'LEK', null],
        ['EMP011', 'Yemi Olatunji',    'yemi@tillpoint.test',    'buyer',      null,  null],
        ['EMP012', 'Hauwa Bello',      'hauwa@tillpoint.test',   'auditor',    null,  null],
    ];
    $userIds = [];
    $pw      = hash_password('Tillpoint#2026');
    foreach ($people as [$staff, $name, $email, $role, $store, $pin]) {
        $existing = qv('SELECT id FROM users WHERE staff_no = ?', [$staff]);
        if ($existing) {
            $userIds[$staff] = (int) $existing;
            continue;
        }
        $roleId = (int) qv('SELECT id FROM roles WHERE code = ?', [$role], 0);
        if ($roleId === 0) {
            continue;
        }
        $userIds[$staff] = db_insert('users', [
            'staff_no'      => $staff,
            'full_name'     => $name,
            'email'         => $email,
            'password_hash' => $pw,
            'pin_hash'      => $pin !== null ? hash_password($pin) : null,
            'role_id'       => $roleId,
            'store_id'      => $store !== null ? $storeIds[$store] : null,
            'is_active'     => 1,
        ]);
    }
    $log[] = count($userIds) . ' staff accounts';

    /* ---- Categories --------------------------------------------- */
    $categories = [
        ['FRESH',  'Fresh Produce',       1, 28],
        ['BAKERY', 'Bakery',              1, 32],
        ['DAIRY',  'Dairy & Chilled',     1, 22],
        ['MEAT',   'Meat & Poultry',      1, 24],
        ['FROZEN', 'Frozen Foods',        1, 25],
        ['GROCERY','Grocery & Staples',   1, 18],
        ['DRINKS', 'Soft Drinks & Water', 1, 20],
        ['ALCOHOL','Beer, Wine & Spirits',1, 26],
        ['SNACKS', 'Snacks & Confectionery', 1, 30],
        ['HOME',   'Household & Cleaning',1, 24],
        ['BABY',   'Baby & Infant',       1, 20],
        ['PERSON', 'Personal Care',       1, 30],
        ['STAPLE', 'Rice, Beans & Grains',1, 14],
        ['TOILETRY','Paper & Toiletries', 1, 26],
    ];
    $catIds = [];
    foreach ($categories as [$code, $name, $vat, $margin]) {
        $existing = qv('SELECT id FROM categories WHERE code = ?', [$code]);
        $catIds[$code] = $existing
            ? (int) $existing
            : db_insert('categories', [
                'code' => $code, 'name' => $name,
                'vat_applicable' => $vat, 'target_margin_pct' => $margin,
            ]);
    }
    $log[] = count($catIds) . ' categories';

    /* ---- Suppliers ---------------------------------------------- */
    $suppliers = [
        ['SUP001', 'Lagos Fresh Farms Ltd',      'Olu Bamidele',   '0802 111 2200', 14, 2],
        ['SUP002', 'Golden Crust Bakeries',      'Amina Sule',     '0802 111 2201', 7,  1],
        ['SUP003', 'Dairyland Nigeria Plc',      'Kelechi Umeh',   '0802 111 2202', 30, 3],
        ['SUP004', 'Prime Protein Distributors', 'Sade Fashola',   '0802 111 2203', 21, 2],
        ['SUP005', 'Arctic Cold Chain Ltd',      'Bashir Lawal',   '0802 111 2204', 30, 4],
        ['SUP006', 'Unity Wholesale Grocers',    'Chinedu Nnaji',  '0802 111 2205', 45, 7],
        ['SUP007', 'Hydra Beverages Nigeria',    'Tolu Agboola',   '0802 111 2206', 30, 3],
        ['SUP008', 'Crown Wines & Spirits',      'Grace Otu',      '0802 111 2207', 30, 5],
        ['SUP009', 'SnackCo West Africa',        'Peter Danjuma',  '0802 111 2208', 30, 4],
        ['SUP010', 'HomeCare Products Ltd',      'Zainab Hassan',  '0802 111 2209', 45, 6],
        ['SUP011', 'Naija Grains & Mills',       'Ifeanyi Chukwu', '0802 111 2210', 30, 10],
    ];
    $supIds = [];
    foreach ($suppliers as [$code, $name, $contact, $phone, $terms, $lead]) {
        $existing = qv('SELECT id FROM suppliers WHERE code = ?', [$code]);
        $supIds[$code] = $existing
            ? (int) $existing
            : db_insert('suppliers', [
                'code' => $code, 'name' => $name, 'contact_name' => $contact,
                'phone' => $phone, 'email' => strtolower(explode(' ', $contact)[0]) . '@'
                    . strtolower(preg_replace('/[^a-z]/i', '', explode(' ', $name)[0])) . '.test',
                'payment_terms_days' => $terms, 'lead_time_days' => $lead,
            ]);
    }
    $log[] = count($supIds) . ' suppliers';

    /* ---- Products ----------------------------------------------- */
    // [sku, name, category, supplier, unit, cost, price, perishable, batches,
    //  shelf-life days, reorder level, reorder qty, weighed, age-restricted]
    $products = [
        ['FRS-001', 'Tomatoes (loose)',               'FRESH',  'SUP001', 'KG', 520,  850,  1, 1, 7,  25, 60, 1, 0],
        ['FRS-002', 'Onions (red, loose)',            'FRESH',  'SUP001', 'KG', 430,  700,  1, 1, 21, 30, 80, 1, 0],
        ['FRS-003', 'Sweet Potatoes',                 'FRESH',  'SUP001', 'KG', 380,  620,  1, 1, 21, 20, 50, 1, 0],
        ['FRS-004', 'Plantain (bunch)',               'FRESH',  'SUP001', 'EA', 900,  1450, 1, 1, 10, 15, 40, 0, 0],
        ['FRS-005', 'Green Pepper',                   'FRESH',  'SUP001', 'KG', 760,  1250, 1, 1, 10, 10, 25, 1, 0],
        ['FRS-006', 'Spinach (ugwu) bundle',          'FRESH',  'SUP001', 'EA', 240,  420,  1, 1, 4,  20, 50, 0, 0],
        ['FRS-007', 'Carrots',                        'FRESH',  'SUP001', 'KG', 600,  980,  1, 1, 14, 15, 35, 1, 0],
        ['FRS-008', 'Watermelon (whole)',             'FRESH',  'SUP001', 'EA', 1100, 1800, 1, 1, 10, 8,  20, 0, 0],

        ['BAK-001', 'Sliced White Bread 600g',        'BAKERY', 'SUP002', 'EA', 650,  950,  1, 1, 4,  30, 90, 0, 0],
        ['BAK-002', 'Wholemeal Bread 600g',           'BAKERY', 'SUP002', 'EA', 780,  1150, 1, 1, 4,  20, 60, 0, 0],
        ['BAK-003', 'Burger Buns 6-pack',             'BAKERY', 'SUP002', 'PK', 700,  1050, 1, 1, 5,  15, 40, 0, 0],
        ['BAK-004', 'Meat Pie',                       'BAKERY', 'SUP002', 'EA', 380,  600,  1, 1, 2,  25, 70, 0, 0],

        ['DRY-001', 'Fresh Milk 1L',                  'DAIRY',  'SUP003', 'L',  1150, 1650, 1, 1, 10, 24, 72, 0, 0],
        ['DRY-002', 'Greek Yoghurt 500g',             'DAIRY',  'SUP003', 'TUB',1350, 1950, 1, 1, 21, 18, 48, 0, 0],
        ['DRY-003', 'Cheddar Cheese 250g',            'DAIRY',  'SUP003', 'PK', 2100, 3100, 1, 1, 45, 12, 30, 0, 0],
        ['DRY-004', 'Butter 250g',                    'DAIRY',  'SUP003', 'PK', 2400, 3400, 1, 1, 60, 10, 24, 0, 0],
        ['DRY-005', 'Eggs (crate of 30)',             'DAIRY',  'SUP003', 'CRT',4200, 5600, 1, 1, 21, 10, 30, 0, 0],

        ['MET-001', 'Chicken (whole, frozen)',        'MEAT',   'SUP004', 'KG', 2600, 3600, 1, 1, 90, 20, 60, 1, 0],
        ['MET-002', 'Beef (boneless)',                'MEAT',   'SUP004', 'KG', 4200, 5700, 1, 1, 5,  15, 40, 1, 0],
        ['MET-003', 'Goat Meat',                      'MEAT',   'SUP004', 'KG', 4600, 6200, 1, 1, 5,  10, 25, 1, 0],
        ['MET-004', 'Titus Sardines 125g',            'MEAT',   'SUP006', 'EA', 820,  1150, 0, 0, 0,  36, 96, 0, 0],

        ['FRZ-001', 'Frozen Mixed Vegetables 400g',   'FROZEN', 'SUP005', 'PK', 1250, 1800, 1, 1, 180,18, 48, 0, 0],
        ['FRZ-002', 'Fish Fillet (frozen) 500g',      'FROZEN', 'SUP005', 'PK', 2300, 3200, 1, 1, 120,15, 40, 0, 0],
        ['FRZ-003', 'Ice Cream Vanilla 1L',           'FROZEN', 'SUP005', 'TUB',1900, 2750, 1, 1, 150,12, 30, 0, 0],
        ['FRZ-004', 'Frozen Chips 1kg',               'FROZEN', 'SUP005', 'PK', 1600, 2300, 1, 1, 180,15, 40, 0, 0],

        ['GRO-001', 'Vegetable Oil 2L',               'GROCERY','SUP006', 'BTL',4500, 5600, 0, 0, 0,  20, 48, 0, 0],
        ['GRO-002', 'Tomato Paste 400g',              'GROCERY','SUP006', 'EA', 950,  1300, 0, 0, 0,  36, 96, 0, 0],
        ['GRO-003', 'Granulated Sugar 1kg',           'GROCERY','SUP006', 'PK', 1250, 1650, 0, 0, 0,  24, 60, 0, 0],
        ['GRO-004', 'Table Salt 500g',                'GROCERY','SUP006', 'PK', 320,  480,  0, 0, 0,  30, 72, 0, 0],
        ['GRO-005', 'Indomie Noodles (40-pack)',      'GROCERY','SUP006', 'CTN',7800, 9600, 0, 0, 0,  12, 30, 0, 0],
        ['GRO-006', 'Milo Refill 500g',               'GROCERY','SUP006', 'PK', 3400, 4400, 0, 0, 0,  18, 48, 0, 0],
        ['GRO-007', 'Peak Milk Powder 400g',          'GROCERY','SUP003', 'PK', 3900, 5000, 0, 0, 0,  18, 48, 0, 0],
        ['GRO-008', 'Golden Penny Spaghetti 500g',    'GROCERY','SUP011', 'PK', 620,  880,  0, 0, 0,  40, 120,0, 0],

        ['STP-001', 'Long Grain Rice 5kg',            'STAPLE', 'SUP011', 'BAG',8900, 10900,0, 0, 0,  15, 40, 0, 0],
        ['STP-002', 'Long Grain Rice 25kg',           'STAPLE', 'SUP011', 'BAG',42000,49500,0, 0, 0,  6,  15, 0, 0],
        ['STP-003', 'Brown Beans 2kg',                'STAPLE', 'SUP011', 'BAG',4400, 5600, 0, 0, 0,  12, 30, 0, 0],
        ['STP-004', 'Garri (white) 2kg',              'STAPLE', 'SUP011', 'BAG',2200, 2900, 0, 0, 0,  15, 40, 0, 0],
        ['STP-005', 'Semovita 1kg',                   'STAPLE', 'SUP011', 'PK', 1400, 1850, 0, 0, 0,  18, 48, 0, 0],
        ['STP-006', 'Yam Flour (elubo) 2kg',          'STAPLE', 'SUP011', 'BAG',3100, 4000, 0, 0, 0,  12, 30, 0, 0],

        ['DRK-001', 'Coca-Cola 50cl (pack of 12)',    'DRINKS', 'SUP007', 'PK', 3600, 4500, 0, 0, 0,  20, 60, 0, 0],
        ['DRK-002', 'Bottled Water 75cl',             'DRINKS', 'SUP007', 'BTL',180,  300,  0, 0, 0,  120,360,0, 0],
        ['DRK-003', 'Orange Juice 1L',                'DRINKS', 'SUP007', 'BTL',1450, 2000, 1, 1, 120,24, 60, 0, 0],
        ['DRK-004', 'Malt Drink 33cl',                'DRINKS', 'SUP007', 'BTL',430,  650,  0, 0, 0,  48, 144,0, 0],
        ['DRK-005', 'Energy Drink 25cl',              'DRINKS', 'SUP007', 'EA', 650,  950,  0, 0, 0,  36, 96, 0, 0],

        ['ALC-001', 'Lager Beer 60cl',                'ALCOHOL','SUP008', 'BTL',620,  950,  0, 0, 0,  48, 144,0, 1],
        ['ALC-002', 'Stout 60cl',                     'ALCOHOL','SUP008', 'BTL',700,  1050, 0, 0, 0,  36, 96, 0, 1],
        ['ALC-003', 'Red Wine 75cl',                  'ALCOHOL','SUP008', 'BTL',4800, 6900, 0, 0, 0,  12, 30, 0, 1],
        ['ALC-004', 'Dry Gin 75cl',                   'ALCOHOL','SUP008', 'BTL',6200, 8900, 0, 0, 0,  8,  20, 0, 1],

        ['SNK-001', 'Potato Crisps 120g',             'SNACKS', 'SUP009', 'PK', 580,  880,  0, 0, 0,  36, 96, 0, 0],
        ['SNK-002', 'Chocolate Bar 50g',              'SNACKS', 'SUP009', 'EA', 320,  500,  0, 0, 0,  48, 144,0, 0],
        ['SNK-003', 'Digestive Biscuits 250g',        'SNACKS', 'SUP009', 'PK', 740,  1100, 0, 0, 0,  30, 72, 0, 0],
        ['SNK-004', 'Groundnuts (roasted) 200g',      'SNACKS', 'SUP009', 'PK', 460,  720,  0, 0, 0,  30, 72, 0, 0],
        ['SNK-005', 'Chin Chin 300g',                 'SNACKS', 'SUP009', 'PK', 680,  1000, 0, 0, 0,  24, 60, 0, 0],

        ['HOM-001', 'Laundry Detergent 1kg',          'HOME',   'SUP010', 'PK', 1850, 2500, 0, 0, 0,  24, 60, 0, 0],
        ['HOM-002', 'Dishwashing Liquid 500ml',       'HOME',   'SUP010', 'BTL',980,  1400, 0, 0, 0,  24, 60, 0, 0],
        ['HOM-003', 'Bleach 1L',                      'HOME',   'SUP010', 'BTL',760,  1100, 0, 0, 0,  24, 60, 0, 0],
        ['HOM-004', 'Insecticide Spray 400ml',        'HOME',   'SUP010', 'EA', 2100, 2900, 0, 0, 0,  18, 48, 0, 0],
        ['HOM-005', 'Scouring Sponge 3-pack',         'HOME',   'SUP010', 'PK', 310,  500,  0, 0, 0,  36, 96, 0, 0],

        ['TOI-001', 'Toilet Tissue 9-roll',           'TOILETRY','SUP010','PK', 1900, 2600, 0, 0, 0,  30, 72, 0, 0],
        ['TOI-002', 'Kitchen Towel 2-roll',           'TOILETRY','SUP010','PK', 980,  1400, 0, 0, 0,  24, 60, 0, 0],
        ['TOI-003', 'Facial Tissue Box',              'TOILETRY','SUP010','EA', 620,  900,  0, 0, 0,  30, 72, 0, 0],

        ['PER-001', 'Bath Soap 100g',                 'PERSON', 'SUP010', 'EA', 340,  550,  0, 0, 0,  48, 144,0, 0],
        ['PER-002', 'Toothpaste 150g',                'PERSON', 'SUP010', 'EA', 890,  1300, 0, 0, 0,  30, 72, 0, 0],
        ['PER-003', 'Shampoo 400ml',                  'PERSON', 'SUP010', 'BTL',1750, 2500, 0, 0, 0,  18, 48, 0, 0],
        ['PER-004', 'Roll-on Deodorant 50ml',         'PERSON', 'SUP010', 'EA', 1200, 1750, 0, 0, 0,  24, 60, 0, 0],
        ['PER-005', 'Hand Sanitiser 250ml',           'PERSON', 'SUP010', 'BTL',880,  1300, 0, 0, 0,  24, 60, 0, 0],

        ['BAB-001', 'Infant Formula 400g',            'BABY',   'SUP003', 'PK', 5600, 7000, 1, 1, 365,12, 30, 0, 0],
        ['BAB-002', 'Nappies Size 3 (44-pack)',       'BABY',   'SUP010', 'PK', 6400, 8200, 0, 0, 0,  12, 30, 0, 0],
        ['BAB-003', 'Baby Wipes 80-pack',             'BABY',   'SUP010', 'PK', 1100, 1600, 0, 0, 0,  24, 60, 0, 0],
    ];

    $prodIds   = [];
    $vatRate   = VAT_RATE;
    $barcodeSeq = 6001;
    foreach ($products as $p) {
        [$sku, $name, $cat, $sup, $unit, $cost, $price, $perish, $batches,
         $shelf, $reorder, $reorderQty, $weighed, $age] = $p;

        $existing = qv('SELECT id FROM products WHERE sku = ?', [$sku]);
        if ($existing) {
            $prodIds[$sku] = (int) $existing;
            continue;
        }
        // A real EAN-13 with a correct check digit, so barcode validation and the
        // till's scan path can both be exercised against the demo data.
        $twelve  = '615' . str_pad((string) $barcodeSeq++, 9, '0', STR_PAD_LEFT);
        $barcode = $twelve . ean13_check_digit($twelve);

        $prodIds[$sku] = db_insert('products', [
            'sku'             => $sku,
            'barcode'         => $barcode,
            'name'            => $name,
            'category_id'     => $catIds[$cat],
            'unit_code'       => $unit,
            'supplier_id'     => $supIds[$sup],
            'pack_size'       => in_array($unit, ['CTN', 'PK', 'CRT'], true) ? 12 : 1,
            'cost_price'      => $cost,
            'sell_price'      => $price,
            'vat_rate'        => $vatRate,
            'is_weighed'      => $weighed,
            'is_perishable'   => $perish,
            'track_batches'   => $batches,
            'shelf_life_days' => $shelf > 0 ? $shelf : null,
            'reorder_level'   => $reorder,
            'reorder_qty'     => $reorderQty,
            'age_restricted'  => $age,
            'is_active'       => 1,
        ]);
        db_insert('product_suppliers', [
            'product_id'   => $prodIds[$sku],
            'supplier_id'  => $supIds[$sup],
            'supplier_sku' => 'S-' . $sku,
            'cost_price'   => $cost,
            'is_preferred' => 1,
        ]);
    }
    $log[] = count($prodIds) . ' products';

    /* ---- Customers ---------------------------------------------- */
    $customers = [
        ['Rotimi Johnson',  '0803 400 1001', 2400],
        ['Amaka Udeh',      '0803 400 1002', 860],
        ['Bilkisu Garba',   '0803 400 1003', 1570],
        ['Kunle Afolabi',   '0803 400 1004', 320],
        ['Ngozi Maduka',    '0803 400 1005', 4100],
        ['Samuel Etim',     '0803 400 1006', 0],
        ['Halima Mohammed', '0803 400 1007', 1290],
        ['Chidi Anyanwu',   '0803 400 1008', 650],
    ];
    $custIds = [];
    foreach ($customers as $i => [$name, $phone, $points]) {
        $code = 'CUS-' . str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT);
        $existing = qv('SELECT id FROM customers WHERE code = ?', [$code]);
        if ($existing) {
            $custIds[] = (int) $existing;
            continue;
        }
        $custIds[] = db_insert('customers', [
            'code'           => $code,
            'name'           => $name,
            'phone'          => $phone,
            'loyalty_card'   => 'LC' . str_pad((string) (90000 + $i), 8, '0', STR_PAD_LEFT),
            'points_balance' => $points,
            'credit_limit'   => $i < 3 ? 50000 : 0,
        ]);
    }
    $log[] = count($custIds) . ' loyalty customers';

    /* ---- Promotions --------------------------------------------- */
    $promos = [
        ['PROMO-WATER', 'Bottled water: buy 5 get 1 free', 'bogo', 'product', 'DRK-002', null, 0, 5, 1, 100],
        ['PROMO-BREAD', '10% off all bakery before noon',  'pct_off', 'category', null, 'BAKERY', 10, 1, 0, 90],
        ['PROMO-RICE',  'Rice 5kg at a fixed 9,900',       'fixed_price', 'product', 'STP-001', null, 9900, 1, 0, 80],
        ['PROMO-SNACK', '15% off snacks when you buy 3+',  'bundle_pct', 'category', null, 'SNACKS', 15, 3, 0, 95],
    ];
    foreach ($promos as [$code, $name, $type, $scope, $sku, $cat, $value, $minQty, $freeQty, $prio]) {
        if (qv('SELECT id FROM promotions WHERE code = ?', [$code])) {
            continue;
        }
        db_insert('promotions', [
            'code'        => $code,
            'name'        => $name,
            'promo_type'  => $type,
            'scope'       => $scope,
            'product_id'  => $sku !== null ? $prodIds[$sku] : null,
            'category_id' => $cat !== null ? $catIds[$cat] : null,
            'value'       => $value,
            'min_qty'     => $minQty,
            'free_qty'    => $freeQty,
            'starts_at'   => date('Y-m-d 00:00:00', strtotime('-30 days')),
            'ends_at'     => date('Y-m-d 23:59:59', strtotime('+60 days')),
            'hour_from'   => $code === 'PROMO-BREAD' ? 0 : null,
            'hour_to'     => $code === 'PROMO-BREAD' ? 12 : null,
            'priority'    => $prio,
            'is_active'   => 1,
            'created_by'  => $userIds['EMP001'] ?? null,
            'approved_by' => $userIds['EMP001'] ?? null,
        ]);
    }
    $log[] = count($promos) . ' promotions';

    /* ---- Vouchers ----------------------------------------------- */
    for ($i = 1; $i <= 5; $i++) {
        $code = 'VCH-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);
        if (qv('SELECT id FROM vouchers WHERE code = ?', [$code])) {
            continue;
        }
        db_insert('vouchers', [
            'code'       => $code,
            'face_value' => 5000 * $i,
            'balance'    => 5000 * $i,
            'status'     => 'active',
            'issued_by'  => $userIds['EMP001'] ?? null,
            'expires_at' => date('Y-m-d', strtotime('+180 days')),
        ]);
    }
    $log[] = '5 gift vouchers';

    return ['log' => $log, 'stores' => $storeIds, 'users' => $userIds, 'products' => $prodIds,
            'suppliers' => $supIds, 'categories' => $catIds, 'customers' => $custIds];
}

/**
 * Receive opening stock into the two selling stores and the depot.
 *
 * Goes in through a real posted GRN rather than being written into stock_levels,
 * so the opening position has a document behind it, the weighted-average cost is
 * established the way every later receipt will establish it, and the ledger
 * balances from the very first row.
 */
function seed_opening_stock(array $ctx): array
{
    $created = 0;
    $skipped = 0;

    foreach (['IKJ', 'LEK', 'WH'] as $storeCode) {
        $storeId = $ctx['stores'][$storeCode];
        // Opening stock is posted once per store; a second run must not double it.
        $already = (int) qv('SELECT COUNT(*) FROM grns WHERE store_id = ? AND note LIKE ?',
            [$storeId, 'Opening stock%'], 0);
        if ($already > 0) {
            $skipped++;
            continue;
        }

        // Group the products by supplier so each receipt looks like a real
        // delivery from one supplier rather than an impossible mixed load.
        $bySupplier = [];
        foreach (qa('SELECT id, sku, supplier_id, cost_price, reorder_qty, track_batches,
                           shelf_life_days, is_perishable
                      FROM products WHERE is_active = 1') as $p) {
            $bySupplier[(int) $p['supplier_id']][] = $p;
        }

        foreach ($bySupplier as $supplierId => $items) {
            $grnNo = next_number('grn', $storeId);
            $grnId = db_insert('grns', [
                'grn_no'              => $grnNo,
                'store_id'            => $storeId,
                'supplier_id'         => $supplierId,
                'supplier_invoice_no' => 'OPEN-' . $storeCode . '-' . $supplierId,
                'received_date'       => date('Y-m-d', strtotime('-21 days')),
                'status'              => 'draft',
                'note'                => 'Opening stock on system go-live',
                'received_by'         => $ctx['users']['EMP006'] ?? 1,
            ]);

            foreach ($items as $p) {
                // The depot holds bulk; the branches hold shelf quantities.
                $base = max(4.0, (float) $p['reorder_qty']);
                $qty  = $storeCode === 'WH' ? round($base * 3) : round($base * (0.9 + (mt_rand(0, 60) / 100)));
                if ($qty <= 0) {
                    continue;
                }
                $expiry = null;
                if ((int) $p['track_batches'] === 1 && $p['shelf_life_days'] !== null) {
                    // Spread expiry dates across the shelf life so the expiry
                    // report and FEFO consumption have something real to show -
                    // and a few land in the past, which is the exception the
                    // expired-stock rule exists to find.
                    $life = (int) $p['shelf_life_days'];
                    $off  = mt_rand((int) -min(10, $life), $life);
                    $expiry = date('Y-m-d', strtotime("+$off days"));
                }
                db_insert('grn_lines', [
                    'grn_id'      => $grnId,
                    'product_id'  => (int) $p['id'],
                    'qty'         => $qty,
                    'unit_cost'   => (float) $p['cost_price'],
                    'vat_rate'    => VAT_RATE,
                    'batch_no'    => (int) $p['track_batches'] === 1
                        ? 'B' . date('ymd', strtotime('-21 days')) . '-' . $p['sku'] : null,
                    'expiry_date' => $expiry,
                ]);
            }
            if ((int) qv('SELECT COUNT(*) FROM grn_lines WHERE grn_id = ?', [$grnId], 0) === 0) {
                q('DELETE FROM grns WHERE id = ?', [$grnId]);
                continue;
            }
            grn_post($grnId, $ctx['users']['EMP006'] ?? 1);
            $created++;
        }
    }
    return ["$created opening goods receipts posted" . ($skipped ? ", $skipped store(s) already stocked" : '')];
}
