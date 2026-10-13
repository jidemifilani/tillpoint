<?php
/**
 * One-off repair: bring the batch sub-ledger back into step with the position.
 *
 *     php database/repair-batch-ledger.php            # report only
 *     php database/repair-batch-ledger.php --apply    # write the correction
 *
 * Why this exists
 * ---------------
 * Before the fix in stock_post(), a stock count or an adjustment on a
 * batch-tracked product changed the position without touching the batches, so
 * the sub-ledger drifted by whatever those documents moved. The positions were
 * never wrong - the ledger of movements has always reconciled - so the
 * correction is to the batch balances only.
 *
 * What it does NOT do
 * -------------------
 * It writes no stock_movements rows. The movements are already right, and
 * inventing more would make the ledger disagree with itself to fix a different
 * table. It only draws down or tops up batch balances so they total the position
 * the ledger already supports, and it says plainly which batches it touched.
 *
 * Safe to re-run: once the balances agree there is nothing left to do.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This repair runs from the command line only.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
$_SESSION = [];
foreach (['db', 'functions', 'csrf', 'rbac', 'rate_limit', 'auth', 'audit',
          'numbering', 'inventory'] as $inc) {
    require_once $root . "/includes/$inc.php";
}

$apply = in_array('--apply', $argv, true);

echo str_repeat('=', 72) . PHP_EOL;
echo 'Batch sub-ledger repair - ' . ($apply ? 'APPLYING' : 'dry run, nothing will be written') . PHP_EOL;
echo str_repeat('=', 72) . PHP_EOL;

// Every tracked product whose batches do not total its position. Negative
// positions are excluded for the same reason stock_assert_batch_balance()
// excludes them: there the batches are legitimately empty and the uncovered
// remainder is an oversell, which is a different problem with its own rule.
$drifted = qa(
    "SELECT sl.product_id, sl.store_id, p.sku, p.name, st.name AS store_name,
            sl.qty_on_hand AS position,
            COALESCE(b.total, 0) AS batched,
            sl.qty_on_hand - COALESCE(b.total, 0) AS difference,
            COALESCE(sl.avg_cost, p.cost_price) AS unit_cost
       FROM stock_levels sl
       JOIN products p ON p.id = sl.product_id
       JOIN stores st ON st.id = sl.store_id
       LEFT JOIN (SELECT product_id, store_id, SUM(qty_on_hand) AS total
                    FROM stock_batches GROUP BY product_id, store_id) b
              ON b.product_id = sl.product_id AND b.store_id = sl.store_id
      WHERE p.track_batches = 1 AND sl.qty_on_hand >= 0
        AND ABS(sl.qty_on_hand - COALESCE(b.total, 0)) > 0.0005
      ORDER BY ABS(sl.qty_on_hand - COALESCE(b.total, 0)) DESC"
);

if (!$drifted) {
    echo 'Nothing to repair: every batch-tracked position already totals its batches.' . PHP_EOL;
    exit(0);
}

printf('%d line(s) out of step.%s%s', count($drifted), PHP_EOL, PHP_EOL);

$netUnits = 0.0;
$netValue = 0.0;
$repaired = 0;

foreach ($drifted as $row) {
    $productId = (int) $row['product_id'];
    $storeId   = (int) $row['store_id'];
    $diff      = (float) $row['difference'];   // position minus batches
    $cost      = (float) $row['unit_cost'];

    printf("%-34s %-14s  position %9s   batches %9s   out by %s%s",
        substr((string) $row['name'], 0, 33),
        substr((string) $row['store_name'], 0, 13),
        qty_fmt((float) $row['position']),
        qty_fmt((float) $row['batched']),
        ($diff > 0 ? '+' : '') . qty_fmt($diff),
        PHP_EOL);

    $netUnits += $diff;
    $netValue += $diff * $cost;

    if (!$apply) {
        continue;
    }

    try {
        db_tx(function () use ($productId, $storeId, $diff, $cost): void {
            if ($diff < 0) {
                // Batches hold more than the position: draw the surplus down,
                // soonest expiry first, which is where a counted shortage sits.
                $drawn = stock_draw_batches($productId, $storeId, -$diff);
                foreach ($drawn as $d) {
                    if ($d['batch_id'] === null) {
                        continue;   // nothing left to draw; the assert tolerates it
                    }
                    printf("    drew %s from batch %s%s",
                        qty_fmt((float) $d['qty']), (string) $d['batch_no'], PHP_EOL);
                }
            } else {
                // Position holds more than the batches: put the difference into
                // a dated catch-up batch rather than leaving it unattributed.
                $batchId = stock_returns_batch($productId, $storeId, $cost, 'LEDGER-REPAIR');
                q('UPDATE stock_batches
                      SET qty_on_hand = qty_on_hand + ?, qty_received = qty_received + ?
                    WHERE id = ?', [$diff, $diff, $batchId]);
                printf("    added %s to catch-up batch #%d%s", qty_fmt($diff), $batchId, PHP_EOL);
            }

            // Prove the line is now in step before committing it.
            stock_assert_batch_balance($productId, $storeId);
        });
        $repaired++;
    } catch (Throwable $e) {
        printf("    FAILED and rolled back: %s%s", $e->getMessage(), PHP_EOL);
    }
}

echo PHP_EOL . str_repeat('-', 72) . PHP_EOL;
printf('Net drift: %s units, %s at cost%s',
    qty_fmt($netUnits), money($netValue), PHP_EOL);

if (!$apply) {
    echo PHP_EOL . 'Dry run. Re-run with --apply to write the correction.' . PHP_EOL;
    exit(0);
}

printf('%d line(s) repaired.%s', $repaired, PHP_EOL);

// The repair itself is a change to stock records, so it belongs in the trail.
audit_log('stock.batch_repair', 'stock', null,
    sprintf('Batch sub-ledger repair: %d line(s) brought into step, net drift %s units (%s at cost). '
        . 'Caused by count and adjustment movements posted before stock_post() reconciled batches.',
        $repaired, qty_fmt($netUnits), money($netValue)),
    null, ['lines' => $repaired, 'net_units' => $netUnits, 'net_value' => $netValue],
    'critical');

$remaining = (int) qv(
    "SELECT COUNT(*) FROM stock_levels sl
       JOIN products p ON p.id = sl.product_id
       LEFT JOIN (SELECT product_id, store_id, SUM(qty_on_hand) AS total
                    FROM stock_batches GROUP BY product_id, store_id) b
              ON b.product_id = sl.product_id AND b.store_id = sl.store_id
      WHERE p.track_batches = 1 AND sl.qty_on_hand >= 0
        AND ABS(sl.qty_on_hand - COALESCE(b.total, 0)) > 0.0005", [], 0
);
echo $remaining === 0
    ? 'Every batch-tracked position now totals its batches.' . PHP_EOL
    : "WARNING: $remaining line(s) still out of step." . PHP_EOL;

exit($remaining === 0 ? 0 : 1);
