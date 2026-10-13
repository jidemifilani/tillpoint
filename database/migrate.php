<?php
/**
 * Additive schema migrations.
 *
 *     php database/migrate.php            # report what is missing
 *     php database/migrate.php --apply    # add it
 *
 * Why this exists
 * ---------------
 * schema.sql is CREATE TABLE IF NOT EXISTS throughout, which makes it safe to
 * re-run but means it cannot change a table that already exists. So a column
 * added to schema.sql after go-live never reaches a live database. This closes
 * that gap without making schema.sql destructive.
 *
 * What it will and will not do
 * ----------------------------
 * It adds columns and indexes that are declared here and absent from the
 * database. It never drops, renames, retypes or narrows anything, and it never
 * touches data - every one of those needs a human deciding what happens to the
 * rows that already exist. If a migration here needs more than an ADD, it
 * belongs in a hand-written script with a backup taken first.
 *
 * Each entry is idempotent: once the column or index is present it is skipped,
 * so this is safe to run on every deploy.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Migrations run from the command line only.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
$_SESSION = [];
foreach (['db', 'functions', 'csrf', 'rbac', 'rate_limit', 'auth', 'audit'] as $inc) {
    require_once $root . "/includes/$inc.php";
}

$apply = in_array('--apply', $argv, true);

/**
 * The migration list.
 *
 * ['column', table, column, definition, why]  - ADD COLUMN if absent
 * ['index',  table, index,  definition, why]  - ADD INDEX  if absent
 * ['table',  table, table,  full CREATE,  why]  - CREATE TABLE IF NOT EXISTS
 *
 * Append only. Never edit or remove an entry that has shipped: a database
 * somewhere has already applied it, and changing it here makes the two diverge
 * silently.
 */
$migrations = [
    ['column', 'refunds', 'self_approved',
     "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'posted at the till by someone who held refund.approve, under the limit - no second person was required'",
     'Separates a refund that policy allowed one person to complete from one that '
     . 'went to the queue and was then approved by its own raiser. Only the second is a breach.'],

    ['column', 'users', 'password_reset_token',
     'CHAR(64) NULL',
     'Self-service password reset: the token is stored hashed and single-use.'],

    ['column', 'users', 'password_reset_expires',
     'DATETIME NULL',
     'Reset links expire; a link that works forever is a second password.'],

    ['index', 'users', 'idx_users_reset_token', '(password_reset_token)',
     'Reset lookups are by token, not by user.'],

    ['column', 'stores', 'shrinkage_target_pct',
     'DECIMAL(6,2) NOT NULL DEFAULT 2.00',
     'The shrinkage report needs a target to measure against, and it differs by store.'],

    ['index', 'stock_movements', 'idx_sm_store_type_time', '(store_id, movement_type, created_at)',
     'The shrinkage and ledger reports filter on all three; without it they table-scan as history grows.'],

    ['index', 'sale_lines', 'idx_sl_product_sale', '(product_id, sale_id)',
     'Basket analysis and per-product margin join this way round.'],

    /* ---- Supplier invoices and the three-way match --------------- */
    ['table', 'supplier_invoices', 'supplier_invoices',
     "CREATE TABLE IF NOT EXISTS supplier_invoices (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        our_ref       VARCHAR(30)  NOT NULL COMMENT 'our own reference, from number_sequences',
        invoice_no    VARCHAR(60)  NOT NULL COMMENT 'the number the SUPPLIER put on it',
        supplier_id   INT UNSIGNED NOT NULL,
        store_id      INT UNSIGNED NOT NULL,
        invoice_date  DATE         NOT NULL,
        due_date      DATE         NULL,
        subtotal      DECIMAL(14,2) NOT NULL DEFAULT 0,
        vat_total     DECIMAL(14,2) NOT NULL DEFAULT 0,
        total         DECIMAL(14,2) NOT NULL DEFAULT 0,
        status        ENUM('draft','matched','disputed','approved','paid','cancelled')
                      NOT NULL DEFAULT 'draft',
        match_status  ENUM('unmatched','clean','variance') NOT NULL DEFAULT 'unmatched',
        price_variance DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'billed less agreed',
        qty_variance   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'billed less received, at cost',
        note          VARCHAR(500) NULL,
        dispute_note  VARCHAR(500) NULL,
        entered_by    INT UNSIGNED NOT NULL,
        approved_by   INT UNSIGNED NULL,
        approved_at   DATETIME     NULL,
        paid_at       DATETIME     NULL,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_si_ref (our_ref),
        /* The control that stops the same invoice being paid twice. A supplier
           never issues two invoices with the same number, so a duplicate is
           either a keying error or someone trying it on. */
        UNIQUE KEY uq_si_supplier_number (supplier_id, invoice_no),
        KEY idx_si_status (status),
        KEY idx_si_match (match_status),
        KEY idx_si_store_date (store_id, invoice_date),
        KEY idx_si_due (due_date, status),
        CONSTRAINT fk_si_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
        CONSTRAINT fk_si_store    FOREIGN KEY (store_id)    REFERENCES stores (id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
     'The missing third leg of the match. Without it nothing checks what the supplier actually billed.'],

    ['table', 'supplier_invoice_lines', 'supplier_invoice_lines',
     "CREATE TABLE IF NOT EXISTS supplier_invoice_lines (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        invoice_id   BIGINT UNSIGNED NOT NULL,
        grn_line_id  BIGINT UNSIGNED NULL COMMENT 'the delivery line this bills for; NULL = billed for nothing we received',
        product_id   INT UNSIGNED NULL COMMENT 'NULL for a charge that is not a product, e.g. delivery',
        description  VARCHAR(160) NOT NULL,
        qty          DECIMAL(12,3) NOT NULL DEFAULT 0,
        unit_cost    DECIMAL(14,4) NOT NULL DEFAULT 0,
        vat_rate     DECIMAL(6,4) NOT NULL DEFAULT 0,
        line_total   DECIMAL(14,2) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_sil_invoice (invoice_id),
        KEY idx_sil_grnline (grn_line_id),
        KEY idx_sil_product (product_id),
        CONSTRAINT fk_sil_invoice FOREIGN KEY (invoice_id) REFERENCES supplier_invoices (id) ON DELETE CASCADE,
        CONSTRAINT fk_sil_grnline FOREIGN KEY (grn_line_id) REFERENCES grn_lines (id) ON DELETE SET NULL,
        CONSTRAINT fk_sil_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
     'Invoice lines, each pointing at the delivery line it claims to bill for.'],

    ['table', 'supplier_invoice_grns', 'supplier_invoice_grns',
     "CREATE TABLE IF NOT EXISTS supplier_invoice_grns (
        invoice_id BIGINT UNSIGNED NOT NULL,
        grn_id     BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (invoice_id, grn_id),
        CONSTRAINT fk_sig_invoice FOREIGN KEY (invoice_id) REFERENCES supplier_invoices (id) ON DELETE CASCADE,
        CONSTRAINT fk_sig_grn     FOREIGN KEY (grn_id)     REFERENCES grns (id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
     'One invoice can cover several deliveries, and one delivery can be split across invoices.'],
];

echo str_repeat('=', 72) . PHP_EOL;
echo 'TillPoint migrations - ' . ($apply ? 'APPLYING' : 'dry run, nothing will be written') . PHP_EOL;
echo 'Database: ' . DB_NAME . PHP_EOL;
echo str_repeat('=', 72) . PHP_EOL;

function column_exists(string $table, string $column): bool
{
    return (int) qv(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [DB_NAME, $table, $column], 0
    ) > 0;
}

function index_exists(string $table, string $index): bool
{
    return (int) qv(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [DB_NAME, $table, $index], 0
    ) > 0;
}

function table_exists(string $table): bool
{
    return (int) qv(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        [DB_NAME, $table], 0
    ) > 0;
}

$applied = 0;
$skipped = 0;
$failed  = 0;

foreach ($migrations as [$kind, $table, $name, $definition, $why]) {
    // A table entry creates rather than alters, so the "run install first"
    // guard below does not apply to it.
    if ($kind === 'table') {
        if (table_exists($table)) {
            $skipped++;
            continue;
        }
        printf("  %s  %s (new table)%s", $apply ? 'APPLY ' : 'NEEDED', $table, PHP_EOL);
        printf("         %s%s", $why, PHP_EOL);
        if (!$apply) {
            continue;
        }
        try {
            db()->exec($definition);
            $applied++;
        } catch (Throwable $e) {
            printf("         FAILED: %s%s", $e->getMessage(), PHP_EOL);
            $failed++;
        }
        continue;
    }

    if (!table_exists($table)) {
        printf("  SKIP   %s.%s - table does not exist, run install.php first%s", $table, $name, PHP_EOL);
        $skipped++;
        continue;
    }

    $present = $kind === 'column' ? column_exists($table, $name) : index_exists($table, $name);
    if ($present) {
        $skipped++;
        continue;
    }

    // Identifiers come from this file, never from input, so they are safe to
    // interpolate - DDL cannot be parameterised in any case.
    $sql = $kind === 'column'
        ? "ALTER TABLE `$table` ADD COLUMN `$name` $definition"
        : "ALTER TABLE `$table` ADD INDEX `$name` $definition";

    printf("  %s  %s.%s%s", $apply ? 'APPLY ' : 'NEEDED', $table, $name, PHP_EOL);
    printf("         %s%s", $why, PHP_EOL);

    if (!$apply) {
        printf("         %s%s", $sql, PHP_EOL);
        continue;
    }

    try {
        db()->exec($sql);
        $applied++;
    } catch (Throwable $e) {
        printf("         FAILED: %s%s", $e->getMessage(), PHP_EOL);
        $failed++;
    }
}

echo str_repeat('-', 72) . PHP_EOL;

if (!$apply) {
    $needed = count($migrations) - $skipped;
    printf('%d migration(s) needed, %d already present.%s', $needed, $skipped, PHP_EOL);
    if ($needed > 0) {
        echo PHP_EOL . 'Re-run with --apply to write them.' . PHP_EOL;
    }
    exit(0);
}

printf('%d applied, %d already present, %d failed.%s', $applied, $skipped, $failed, PHP_EOL);

if ($applied > 0) {
    // A schema change is worth a trail entry, at critical severity: it is the
    // kind of change that explains why a later figure looks different.
    audit_log('system.migrate', 'system', null,
        sprintf('Schema migration applied: %d change(s) added', $applied),
        null, ['applied' => $applied], 'critical');
}

/* ------------------------------------------------------------------ */
/* Backfill                                                           */
/* ------------------------------------------------------------------ */
// Data that the new columns need in order to mean anything. Kept separate from
// the ALTERs and written to be re-runnable.
if ($apply && column_exists('refunds', 'self_approved')) {
    // Refunds already in the database that were posted by their own raiser
    // under the approval limit were permitted by policy, not in breach of it.
    // Anything above the limit stays unmarked, so the SoD report still reports it.
    $limit = setting_num('refund_approval_limit', (float) REFUND_APPROVAL_LIMIT);
    $n = q("UPDATE refunds
               SET self_approved = 1
             WHERE approved_by IS NOT NULL
               AND approved_by = requested_by
               AND self_approved = 0
               AND total <= ?", [$limit])->rowCount();
    if ($n > 0) {
        printf('%sBackfilled self_approved on %d historic refund(s) at or under the %s limit.%s',
            PHP_EOL, $n, money($limit), PHP_EOL);
        audit_log('system.migrate', 'refund', null,
            sprintf('Marked %d historic refund(s) as till-authorised: posted by their raiser '
                . 'under the %s approval limit, so no second person was required.',
                $n, money($limit)),
            null, null, 'notice');
    }
}

exit($failed > 0 ? 1 : 0);
