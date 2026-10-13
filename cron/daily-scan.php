<?php
/**
 * The nightly job.
 *
 *     php cron/daily-scan.php                 # yesterday, every active store
 *     php cron/daily-scan.php --date=2026-10-05
 *     php cron/daily-scan.php --store=IKJ
 *     php cron/daily-scan.php --quiet         # only print problems
 *
 * Does the work that has to happen on a schedule rather than when somebody
 * happens to open a page:
 *
 *   1. runs the audit exception scan over the trading day
 *   2. verifies the audit hash chain and records the head hash externally
 *   3. expires vouchers and loyalty points that have passed their date
 *   4. prunes sign-in attempt records past their retention period
 *   5. reports anything that needs a person
 *
 * Exit code 0 means nothing needs attention, 1 means something does. That makes
 * it usable from a scheduler that alerts on failure.
 *
 * Windows Task Scheduler:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\tillpoint\cron\daily-scan.php
 * cron:
 *   15 2 * * *  /usr/bin/php /var/www/tillpoint/cron/daily-scan.php --quiet
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This job runs from the command line only.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
$_SESSION = [];
foreach (['db', 'functions', 'csrf', 'rbac', 'rate_limit', 'auth', 'audit', 'numbering',
          'inventory', 'stock_docs', 'promotions', 'pos', 'audit_rules', 'reports'] as $inc) {
    require_once $root . "/includes/$inc.php";
}

set_time_limit(1800);

/* ---- Arguments --------------------------------------------------- */
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$quiet = isset($opts['quiet']);
$date  = isset($opts['date']) && is_string($opts['date'])
    ? (date_or_null($opts['date']) ?? date('Y-m-d', strtotime('-1 day')))
    : date('Y-m-d', strtotime('-1 day'));

$storeFilter = isset($opts['store']) && is_string($opts['store'])
    ? (int) qv('SELECT id FROM stores WHERE code = ?', [strtoupper($opts['store'])], 0)
    : null;

$problems = [];
$started  = microtime(true);

function say(string $line = ''): void
{
    global $quiet;
    if (!$quiet) {
        echo $line . PHP_EOL;
    }
}

function problem(string $line): void
{
    global $problems;
    $problems[] = $line;
    echo 'ATTENTION: ' . $line . PHP_EOL;
}

say(str_repeat('=', 72));
say('TillPoint nightly job - trading day ' . date_fmt($date));
say('Started ' . date('d M Y H:i:s'));
say(str_repeat('=', 72));

/**
 * The job acts as itself, not as a person. A null uid means audit_log() records
 * 'system' as the actor, which is honest: nobody clicked anything.
 */
$_SESSION['user_name'] = 'system (nightly job)';

/* ================================================================== */
say(PHP_EOL . '1. Exception scan');
/* ================================================================== */
$stores = $storeFilter !== null
    ? qa('SELECT id, code, name FROM stores WHERE id = ?', [$storeFilter])
    : qa('SELECT id, code, name FROM stores WHERE is_active = 1 AND is_warehouse = 0 ORDER BY name');

if (!$stores) {
    problem('No active selling stores to scan.');
}

$totalFound = 0;
foreach ($stores as $store) {
    $found  = audit_scan($date, (int) $store['id'], 'cron');
    $raised = 0;
    $errors = [];
    foreach ($found as $rule => $outcome) {
        if (is_int($outcome)) {
            $raised += $outcome;
        } elseif (is_string($outcome) && str_starts_with($outcome, 'error')) {
            $errors[] = $rule . ' (' . $outcome . ')';
        }
    }
    $totalFound += $raised;
    say(sprintf('   %-22s %d finding(s)', $store['name'], $raised));

    foreach ($errors as $err) {
        problem('A rule failed during the scan at ' . $store['name'] . ': ' . $err);
    }
}
say(sprintf('   %d finding(s) raised or refreshed in total', $totalFound));

// Anything serious and still unreviewed is worth naming in the output, because
// that is what a scheduler's email will show somebody.
$urgent = qa(
    "SELECT ae.id, ae.summary, ae.severity, ar.name AS rule_name, st.name AS store_name
       FROM audit_exceptions ae
       JOIN audit_rules ar ON ar.code = ae.rule_code
       LEFT JOIN stores st ON st.id = ae.store_id
      WHERE ae.status IN ('open','reviewing') AND ae.severity IN ('high','critical')
      ORDER BY FIELD(ae.severity,'critical','high'), ae.detected_at DESC LIMIT 20"
);
if ($urgent) {
    say(PHP_EOL . '   High and critical findings still open:');
    foreach ($urgent as $u) {
        say(sprintf('     [%s] %s - %s', strtoupper((string) $u['severity']),
            (string) $u['rule_name'], (string) $u['summary']));
    }
    problem(count($urgent) . ' high or critical audit exception(s) are open and unreviewed.');
}

/* ================================================================== */
say(PHP_EOL . '2. Audit trail integrity');
/* ================================================================== */
$check = audit_record_integrity_check(null, 'Nightly verification');
say(sprintf('   %s - %s entries checked',
    strtoupper((string) $check['result']), number_format((int) $check['rows_checked'])));

if ($check['result'] === 'broken') {
    problem('THE AUDIT TRAIL DOES NOT VERIFY. Broken at entry #' . $check['broken_at_id']
        . '. ' . $check['detail']);
} else {
    /**
     * Record the head hash outside the database.
     *
     * The chain proves nothing has been altered only as long as the proof is
     * not itself editable by whoever did the altering. A dated copy of the head
     * hash on the filesystem defeats a full rewrite: a rebuilt chain cannot
     * match a hash written down before the rebuild.
     *
     * Treat this file as evidence. Copy it off the machine periodically.
     */
    $head = q1('SELECT id, created_at, row_hash FROM audit_log ORDER BY id DESC LIMIT 1');
    if ($head !== null) {
        $dir = APP_ROOT . '/database/integrity';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf("%s\tentry=%d\twritten=%s\thash=%s%s",
            date('c'), (int) $head['id'], (string) $head['created_at'],
            (string) $head['row_hash'], PHP_EOL);

        $file = $dir . '/head-hashes.log';
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            problem('Could not write the head hash to ' . $file
                . ' - the external proof of the chain is not being kept.');
        } else {
            say('   Head hash recorded: entry #' . (int) $head['id']
                . ' ' . substr((string) $head['row_hash'], 0, 16) . '...');
        }
    }
}

/* ================================================================== */
say(PHP_EOL . '3. Stock ledger reconciliation');
/* ================================================================== */
$drift = stock_ledger_discrepancies();
if ($drift) {
    problem(count($drift) . ' stock position(s) disagree with their own ledger. '
        . 'Worst: ' . $drift[0]['name'] . ' off by ' . qty_fmt((float) $drift[0]['difference']));
} else {
    say('   Every position equals the sum of its movements.');
}

$batchDrift = qa(
    "SELECT p.sku, p.name, sl.qty_on_hand, COALESCE(b.total, 0) AS batched
       FROM stock_levels sl
       JOIN products p ON p.id = sl.product_id
       LEFT JOIN (SELECT product_id, store_id, SUM(qty_on_hand) AS total
                    FROM stock_batches GROUP BY product_id, store_id) b
              ON b.product_id = sl.product_id AND b.store_id = sl.store_id
      WHERE p.track_batches = 1 AND sl.qty_on_hand >= 0
        AND ABS(sl.qty_on_hand - COALESCE(b.total, 0)) > 0.0005"
);
if ($batchDrift) {
    problem(count($batchDrift) . ' batch-tracked line(s) do not total their batches. '
        . 'Run php database/repair-batch-ledger.php');
} else {
    say('   Every batch-tracked position totals its batches.');
}

/* ================================================================== */
say(PHP_EOL . '4. Expiries');
/* ================================================================== */

// Vouchers. Done here rather than lazily on page load, so a voucher is expired
// whether or not anyone opens the voucher screen.
$expiredVouchers = db_tx(function (): int {
    $rows = qa("SELECT id, code, balance FROM vouchers
                 WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < CURDATE()");
    foreach ($rows as $v) {
        db_update('vouchers', ['status' => 'expired'], 'id = :id', ['id' => (int) $v['id']]);
    }
    if ($rows) {
        $value = array_sum(array_map(fn ($v) => (float) $v['balance'], $rows));
        audit_log('voucher.expire', 'voucher', null,
            sprintf('%d voucher(s) expired, releasing %s of outstanding liability',
                count($rows), money($value)),
            null, ['count' => count($rows), 'value' => $value], 'notice');
    }
    return count($rows);
});
say(sprintf('   %d voucher(s) expired', $expiredVouchers));

/**
 * Loyalty points.
 *
 * Points are a liability that otherwise only ever grows. Expiry is measured
 * from the customer's last activity rather than from when each point was
 * earned: a customer who shops every month should never lose points, and one
 * who has not been seen for two years should not still be owed.
 */
$pointsExpiryMonths = (int) setting_num('loyalty_expiry_months', 24.0);
$expiredPoints = 0;
if ($pointsExpiryMonths > 0) {
    $dormantCustomers = qa(
        "SELECT c.id, c.name, c.points_balance,
                (SELECT MAX(s.sold_at) FROM sales s WHERE s.customer_id = c.id) AS last_visit
           FROM customers c
          WHERE c.points_balance > 0
         HAVING last_visit IS NULL OR last_visit < (NOW() - INTERVAL ? MONTH)",
        [$pointsExpiryMonths]
    );
    foreach ($dormantCustomers as $c) {
        try {
            db_tx(function () use ($c, $pointsExpiryMonths): void {
                loyalty_move((int) $c['id'], -(float) $c['points_balance'], 'expire', null,
                    sprintf('Expired after %d months without a visit', $pointsExpiryMonths));
            });
            $expiredPoints++;
        } catch (Throwable $e) {
            problem('Could not expire points for ' . $c['name'] . ': ' . $e->getMessage());
        }
    }
    if ($expiredPoints > 0) {
        audit_log('loyalty.expire_run', 'customer', null,
            sprintf('%d dormant loyalty account(s) had their points expired after %d months',
                $expiredPoints, $pointsExpiryMonths),
            null, null, 'notice');
    }
}
say(sprintf('   %d loyalty account(s) had points expired', $expiredPoints));

// Expired stock still on the shelf is a safety matter, so it is named even
// though the scan rule has already raised it.
$expiredStock = qa(
    "SELECT st.name AS store_name, COUNT(*) AS batches,
            COALESCE(SUM(b.qty_on_hand * b.unit_cost), 0) AS value
       FROM stock_batches b JOIN stores st ON st.id = b.store_id
      WHERE b.qty_on_hand > 0 AND b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE()
      GROUP BY st.name"
);
foreach ($expiredStock as $e) {
    problem(sprintf('%s has %d expired batch(es) still on hand, %s at cost.',
        (string) $e['store_name'], (int) $e['batches'], money((float) $e['value'])));
}
if (!$expiredStock) {
    say('   No expired stock on hand.');
}

/* ================================================================== */
say(PHP_EOL . '5. Housekeeping');
/* ================================================================== */
$pruned = login_attempts_prune();
say(sprintf('   %d sign-in attempt record(s) pruned', $pruned));

// Unclosed trading days. Each one never had its figures frozen or its scan run,
// so a backlog here quietly means a backlog of unchecked trading.
$unclosed = qa(
    "SELECT s.store_id, st.name AS store_name, COUNT(DISTINCT s.business_date) AS days
       FROM sales s
       JOIN stores st ON st.id = s.store_id
       LEFT JOIN day_end_closures dc ON dc.store_id = s.store_id
              AND dc.business_date = s.business_date AND dc.status = 'closed'
      WHERE s.business_date < CURDATE()
        AND s.status IN ('completed','refunded','partly_refunded')
        AND dc.id IS NULL
      GROUP BY s.store_id, st.name"
);
foreach ($unclosed as $u) {
    problem(sprintf('%s has %d trading day(s) that were never closed.',
        (string) $u['store_name'], (int) $u['days']));
}
if (!$unclosed) {
    say('   Every past trading day has been closed.');
}

// Deliveries recorded but never posted: the shop is selling goods the system
// does not know it holds.
$draftGrns = (int) qv("SELECT COUNT(*) FROM grns WHERE status = 'draft'
                        AND received_date < (CURDATE() - INTERVAL 2 DAY)", [], 0);
if ($draftGrns > 0) {
    problem("$draftGrns delivery(ies) have been recorded but not posted to stock for more than "
        . 'two days.');
}

// Till variances nobody has explained.
$unreconciled = (int) qv("SELECT COUNT(*) FROM till_sessions WHERE status = 'closed'", [], 0);
if ($unreconciled > 0) {
    problem("$unreconciled till variance(s) are closed but not signed off.");
}

/* ================================================================== */
/* Result                                                             */
/* ================================================================== */
$elapsed = microtime(true) - $started;

say(PHP_EOL . str_repeat('=', 72));
if (!$problems) {
    say(sprintf('Nothing needs attention. Completed in %.1fs.', $elapsed));
    say(str_repeat('=', 72));
    exit(0);
}

// Printed even under --quiet: this is what the scheduler should surface.
echo PHP_EOL . str_repeat('=', 72) . PHP_EOL;
printf('%d thing(s) need attention:%s', count($problems), PHP_EOL);
foreach ($problems as $i => $p) {
    printf('  %d. %s%s', $i + 1, $p, PHP_EOL);
}
printf('Completed in %.1fs.%s', $elapsed, PHP_EOL);
echo str_repeat('=', 72) . PHP_EOL;

exit(1);
