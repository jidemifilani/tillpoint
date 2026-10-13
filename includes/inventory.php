<?php
/**
 * The stock engine.
 *
 * Every change to stock in this application goes through stock_post(). Nothing
 * anywhere writes stock_levels.qty_on_hand directly. That is the invariant the
 * whole audit story rests on:
 *
 *      stock_levels.qty_on_hand  ==  SUM(stock_movements.qty_delta)
 *
 * for every (product, store) pair, always. If a page could nudge a quantity
 * without writing a ledger row, stock would differ from its own history and no
 * shrinkage figure would mean anything. tests/smoke.php asserts the equality
 * across the whole database.
 *
 * Costing: weighted average, moved only by receipts. Issues leave avg_cost
 * alone and carry the cost at the moment they happened, so a later price rise
 * cannot retroactively change the margin on sales already made.
 *
 * Batches: products with track_batches get a stock_batches row per receipt and
 * are consumed first-expiry-first-out, which is what a supermarket actually
 * does with stock and what makes the expiry report trustworthy.
 */
declare(strict_types=1);

class StockError extends RuntimeException
{
}

/* ------------------------------------------------------------------ */
/* Reading positions                                                  */
/* ------------------------------------------------------------------ */

function stock_level(int $productId, int $storeId): array
{
    $row = q1('SELECT * FROM stock_levels WHERE product_id = ? AND store_id = ?', [$productId, $storeId]);
    return $row ?? [
        'product_id'   => $productId,
        'store_id'     => $storeId,
        'qty_on_hand'  => 0.0,
        'qty_reserved' => 0.0,
        'avg_cost'     => 0.0,
    ];
}

function stock_on_hand(int $productId, int $storeId): float
{
    return (float) qv('SELECT qty_on_hand FROM stock_levels WHERE product_id = ? AND store_id = ?',
        [$productId, $storeId], 0.0);
}

/** On hand less what parked baskets and in-flight transfers are holding. */
function stock_available(int $productId, int $storeId): float
{
    $r = stock_level($productId, $storeId);
    return qty_round((float) $r['qty_on_hand'] - (float) $r['qty_reserved']);
}

function stock_avg_cost(int $productId, int $storeId): float
{
    $c = (float) qv('SELECT avg_cost FROM stock_levels WHERE product_id = ? AND store_id = ?',
        [$productId, $storeId], 0.0);
    // A product that has never been received has no average yet; its list cost
    // is the best estimate, and reporting zero cost would show a 100% margin.
    if ($c <= 0) {
        $c = (float) qv('SELECT cost_price FROM products WHERE id = ?', [$productId], 0.0);
    }
    return $c;
}

/**
 * Make sure a stock_levels row exists and return it locked for this transaction.
 * The lock is what stops two tills both selling the last unit.
 */
function stock_lock(int $productId, int $storeId): array
{
    // Create-if-absent and lock in one statement, so there is no gap between the
    // existence check and the lock for a concurrent writer to slip through.
    q('INSERT INTO stock_levels (product_id, store_id, qty_on_hand, avg_cost)
            VALUES (?, ?, 0, 0)
        ON DUPLICATE KEY UPDATE product_id = product_id',
      [$productId, $storeId]);

    $row = db_lock_row('stock_levels', 'product_id = :p AND store_id = :s',
        ['p' => $productId, 's' => $storeId]);
    if ($row === null) {
        throw new StockError('Could not lock the stock record for product #' . $productId . '.');
    }
    return $row;
}

/* ------------------------------------------------------------------ */
/* The single write path                                              */
/* ------------------------------------------------------------------ */

/**
 * Post one stock movement and bring the position with it.
 *
 * $args:
 *   product_id, store_id, movement_type, qty_delta (signed, required)
 *   unit_cost    - for receipts the purchase cost; omitted on issues, where the
 *                  current weighted average is used
 *   batch_id     - the batch this row belongs to, when batch-tracked
 *   ref_type, ref_id, ref_no, reason_code, note, user_id
 *   allow_negative - override the global "never sell what we do not have"
 *
 * Returns the movement id. Must be called inside a transaction; it refuses
 * otherwise, because the lock it takes would be released immediately.
 */
function stock_post(array $args): int
{
    $productId = (int) ($args['product_id'] ?? 0);
    $storeId   = (int) ($args['store_id'] ?? 0);
    $type      = (string) ($args['movement_type'] ?? '');
    $qtyDelta  = qty_round((float) ($args['qty_delta'] ?? 0));

    $types = ['receipt', 'sale', 'refund_in', 'transfer_out', 'transfer_in',
              'adjustment', 'wastage', 'count_variance', 'opening'];
    if ($productId === 0 || $storeId === 0) {
        throw new StockError('stock_post needs a product and a store.');
    }
    if (!in_array($type, $types, true)) {
        throw new StockError("Unknown movement type '$type'.");
    }
    if (abs($qtyDelta) < 0.0005) {
        throw new StockError('A stock movement of zero quantity is not a movement.');
    }
    if (!db()->inTransaction()) {
        throw new StockError('stock_post must run inside a transaction.');
    }

    $level   = stock_lock($productId, $storeId);
    $onHand  = (float) $level['qty_on_hand'];
    $avgCost = (float) $level['avg_cost'];
    $newQty  = qty_round($onHand + $qtyDelta);

    // Going short. Allowed only where the caller says so, or where the setting
    // permits it, because a negative shelf position is almost always an unposted
    // receipt rather than a real event - and selling into it hides the error.
    if ($newQty < 0) {
        $allow = (bool) ($args['allow_negative'] ?? setting_bool('negative_stock_allowed', NEGATIVE_STOCK_ALLOWED));
        if (!$allow) {
            $name = (string) qv('SELECT name FROM products WHERE id = ?', [$productId], "#$productId");
            throw new StockError(sprintf(
                'Not enough stock of %s: %s on hand, %s requested.',
                $name, qty_fmt($onHand), qty_fmt(abs($qtyDelta))
            ));
        }
    }

    // Costing.
    if ($type === 'receipt' || $type === 'opening') {
        $unitCost = (float) ($args['unit_cost'] ?? $avgCost);
        // Weighted average. Guarded for the two cases where the formula has no
        // meaning: nothing on hand to average against, or a position that is
        // already negative (the denominator could be zero or the result absurd).
        if ($onHand <= 0 || ($onHand + $qtyDelta) <= 0) {
            $newAvg = $unitCost;
        } else {
            $newAvg = round((($onHand * $avgCost) + ($qtyDelta * $unitCost)) / ($onHand + $qtyDelta), 4);
        }
    } else {
        // Issues and non-purchase increases carry the current average and leave
        // it where it is. A batch-tracked issue uses that batch's own cost.
        $unitCost = isset($args['unit_cost']) ? (float) $args['unit_cost'] : $avgCost;
        if ($unitCost <= 0) {
            $unitCost = $avgCost > 0 ? $avgCost : (float) qv('SELECT cost_price FROM products WHERE id = ?', [$productId], 0.0);
        }
        $newAvg = $avgCost;
    }

    // Keep the batch sub-ledger in step when the caller had no batch to name.
    // Counts and adjustments are the two movements with no delivery behind them,
    // so they arrive here without a batch_id even for a tracked product. Doing
    // this at the write path rather than in count_post() and adjustment_post()
    // means a future movement type gets it right without being told.
    $batchless = ($args['batch_id'] ?? null) === null;
    if ($batchless
        && in_array($type, ['adjustment', 'count_variance', 'refund_in', 'opening'], true)
        && (int) qv('SELECT track_batches FROM products WHERE id = ?', [$productId], 0) === 1) {
        stock_reconcile_batches($productId, $storeId, $qtyDelta, $unitCost,
            (string) ($args['ref_no'] ?? $type));
    }

    $movementId = db_insert('stock_movements', [
        'store_id'      => $storeId,
        'product_id'    => $productId,
        'batch_id'      => $args['batch_id'] ?? null,
        'movement_type' => $type,
        'qty_delta'     => $qtyDelta,
        'unit_cost'     => round($unitCost, 4),
        'value_delta'   => money_round($qtyDelta * $unitCost),
        'balance_after' => $newQty,
        'ref_type'      => $args['ref_type'] ?? null,
        'ref_id'        => $args['ref_id'] ?? null,
        'ref_no'        => $args['ref_no'] ?? null,
        'reason_code'   => $args['reason_code'] ?? null,
        'note'          => isset($args['note']) ? substr((string) $args['note'], 0, 255) : null,
        'user_id'       => $args['user_id'] ?? ((int) ($_SESSION['uid'] ?? 0) ?: null),
        'created_at'    => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.v'),
    ]);

    $update = ['qty_on_hand' => $newQty, 'avg_cost' => round($newAvg, 4)];
    if ($type === 'sale') {
        $update['last_sold_at'] = date('Y-m-d H:i:s');
    } elseif ($type === 'receipt' || $type === 'transfer_in') {
        $update['last_received_at'] = date('Y-m-d H:i:s');
    } elseif ($type === 'count_variance') {
        $update['last_counted_at'] = date('Y-m-d H:i:s');
    }
    db_update('stock_levels', $update, 'product_id = :p AND store_id = :s',
        ['p' => $productId, 's' => $storeId]);

    // For a batch-tracked product the batch sub-ledger must add up to the
    // position. Asserted here, at the single write path, rather than left to
    // each caller remembering: the drift this catches came from a restock path
    // that put units back into the position without putting them into a batch,
    // and a check in that one caller would not have stopped the next one.
    stock_assert_batch_balance($productId, $storeId);

    // Crossing below the reorder level is worth saying once, at the moment it
    // happens, rather than only in a report somebody has to remember to open.
    if ($qtyDelta < 0) {
        $p = q1('SELECT name, reorder_level FROM products WHERE id = ?', [$productId]);
        $reorder = (float) ($p['reorder_level'] ?? 0);
        if ($reorder > 0 && $newQty <= $reorder && $onHand > $reorder) {
            notify_role('stock', 'warning', 'Reorder level reached',
                sprintf('%s is down to %s (reorder at %s).',
                    $p['name'], qty_fmt($newQty), qty_fmt($reorder)),
                base_url('inventory/reorder'));
        }
    }

    return $movementId;
}

/* ------------------------------------------------------------------ */
/* Batches - first expiry, first out                                  */
/* ------------------------------------------------------------------ */

/** Open batches in the order they should be consumed: soonest expiry first. */
function stock_batches_fefo(int $productId, int $storeId): array
{
    return qa(
        'SELECT * FROM stock_batches
          WHERE product_id = ? AND store_id = ? AND qty_on_hand > 0
          ORDER BY (expiry_date IS NULL), expiry_date ASC, received_at ASC, id ASC',
        [$productId, $storeId]
    );
}

/**
 * Decide which batches cover a quantity, FEFO, and draw them down.
 *
 * Returns a list of ['batch_id', 'qty', 'unit_cost'] so the caller can post one
 * movement per batch and keep batch-level traceability - which is the point of
 * tracking batches at all: a recall has to name the affected receipts.
 *
 * Short cover is not padded silently. If the batches hold less than the figure
 * asked for, the remainder comes back with a null batch_id, and the caller
 * posts it against the product without a batch; stock_post still enforces the
 * negative-stock rule, so nothing slips through unnoticed.
 */
function stock_draw_batches(int $productId, int $storeId, float $qty): array
{
    if (!db()->inTransaction()) {
        throw new StockError('stock_draw_batches must run inside a transaction.');
    }
    $need  = qty_round($qty);
    $taken = [];

    foreach (stock_batches_fefo($productId, $storeId) as $b) {
        if ($need <= 0.0005) {
            break;
        }
        // Lock each batch before drawing it down, same reason as stock_levels.
        $locked = db_lock_row('stock_batches', 'id = :id', ['id' => (int) $b['id']]);
        if ($locked === null || (float) $locked['qty_on_hand'] <= 0) {
            continue;
        }
        $take = min($need, (float) $locked['qty_on_hand']);
        $take = qty_round($take);
        if ($take <= 0.0005) {
            continue;
        }
        q('UPDATE stock_batches SET qty_on_hand = qty_on_hand - ? WHERE id = ?',
          [$take, (int) $locked['id']]);

        $taken[] = [
            'batch_id'  => (int) $locked['id'],
            'batch_no'  => (string) $locked['batch_no'],
            'expiry'    => $locked['expiry_date'],
            'qty'       => $take,
            'unit_cost' => (float) $locked['unit_cost'],
        ];
        $need = qty_round($need - $take);
    }

    if ($need > 0.0005) {
        $taken[] = [
            'batch_id'  => null,
            'batch_no'  => null,
            'expiry'    => null,
            'qty'       => $need,
            'unit_cost' => stock_avg_cost($productId, $storeId),
        ];
    }
    return $taken;
}

/** Put units back into a named batch (a restocked refund, or a cancelled issue). */
function stock_return_to_batch(int $batchId, float $qty): void
{
    q('UPDATE stock_batches SET qty_on_hand = qty_on_hand + ? WHERE id = ?', [qty_round($qty), $batchId]);
}

/**
 * The batch sub-ledger must equal the position it belongs to.
 *
 * Checked only when the position is zero or positive. A negative position means
 * the product was oversold, in which case the batches are legitimately empty and
 * stock_draw_batches() has recorded the uncovered remainder against no batch -
 * see the null-batch fallback there. That state is already reported by the
 * NEGATIVE_STOCK rule, so asserting on it here would turn one exception into a
 * hard failure that stops the till.
 */
function stock_assert_batch_balance(int $productId, int $storeId): void
{
    $tracked = (int) qv('SELECT track_batches FROM products WHERE id = ?', [$productId], 0) === 1;
    if (!$tracked) {
        return;
    }
    $position = (float) qv('SELECT qty_on_hand FROM stock_levels
                             WHERE product_id = ? AND store_id = ?',
                           [$productId, $storeId], 0.0);
    if ($position < 0) {
        return;
    }
    $batched = (float) qv('SELECT COALESCE(SUM(qty_on_hand), 0) FROM stock_batches
                            WHERE product_id = ? AND store_id = ?',
                          [$productId, $storeId], 0.0);

    if (abs($position - $batched) > 0.0005) {
        $name = (string) qv('SELECT name FROM products WHERE id = ?', [$productId], "#$productId");
        throw new StockError(sprintf(
            'Batch sub-ledger out of step for %s: the position says %s but the batches hold %s. '
            . 'Every movement of a batch-tracked product must name the batch it came from or went into.',
            $name, qty_fmt($position), qty_fmt($batched)
        ));
    }
}

/**
 * Apply a position change to the batch sub-ledger when the caller has no batch
 * to name.
 *
 * A stock count and an adjustment both change what the system believes is on the
 * shelf without reference to any delivery - that is their nature. For an
 * untracked product that is the whole story. For a tracked one it leaves the
 * batches disagreeing with the position, which is what drifted six produce lines
 * here by a net 13 units.
 *
 * Down: consume FEFO, so the shortfall is taken off the stock nearest its date,
 * which is where a genuine shelf shortage almost always is.
 * Up: into a dated catch-up batch, so found stock cannot acquire an open-ended
 * shelf life just because nobody knows where it came from.
 */
function stock_reconcile_batches(int $productId, int $storeId, float $delta,
                                 float $unitCost, string $refNo): void
{
    if (!db()->inTransaction()) {
        throw new StockError('stock_reconcile_batches must run inside a transaction.');
    }
    $delta = qty_round($delta);
    if (abs($delta) < 0.0005) {
        return;
    }

    if ($delta < 0) {
        // Draw what the batches can cover. A shortfall beyond that means the
        // position is going negative, which the assert deliberately tolerates
        // and the NEGATIVE_STOCK rule reports.
        stock_draw_batches($productId, $storeId, -$delta);
        return;
    }

    $batchId = stock_returns_batch($productId, $storeId, $unitCost, $refNo);
    q('UPDATE stock_batches
          SET qty_on_hand = qty_on_hand + ?, qty_received = qty_received + ?
        WHERE id = ?',
      [$delta, $delta, $batchId]);
}

/**
 * Find or create the batch that a return of unknown provenance goes back into.
 *
 * A customer bringing something back rarely brings the delivery it came from, so
 * a restocked return on a batch-tracked product has no batch to re-enter. It
 * cannot go into no batch at all - that is what broke the sub-ledger - and it
 * cannot be invented with a generous expiry date, because that would quietly
 * extend the shelf life of goods nobody can date.
 *
 * So it goes into a returns batch dated as conservatively as the evidence
 * allows: the soonest expiry currently open for that product, which guarantees
 * FEFO sells it no later than stock that is known to be at least as fresh.
 */
function stock_returns_batch(int $productId, int $storeId, float $unitCost, string $refNo): int
{
    $soonest = qv('SELECT MIN(expiry_date) FROM stock_batches
                    WHERE product_id = ? AND store_id = ? AND qty_on_hand > 0
                      AND expiry_date IS NOT NULL',
                  [$productId, $storeId]);

    if ($soonest === null) {
        // Nothing open to compare against. Fall back to the shelf life from
        // today, which is the only defensible estimate left, and the product's
        // own definition of how long it keeps.
        $shelfLife = qv('SELECT shelf_life_days FROM products WHERE id = ?', [$productId]);
        $soonest   = $shelfLife !== null
            ? date('Y-m-d', strtotime('+' . (int) $shelfLife . ' days'))
            : null;
    }

    $batchNo  = substr('RET-' . $refNo, 0, 40);
    $existing = q1('SELECT id FROM stock_batches
                     WHERE product_id = ? AND store_id = ? AND batch_no = ?',
                   [$productId, $storeId, $batchNo]);
    if ($existing) {
        return (int) $existing['id'];
    }

    return db_insert('stock_batches', [
        'product_id'   => $productId,
        'store_id'     => $storeId,
        'batch_no'     => $batchNo,
        'expiry_date'  => $soonest,
        'qty_received' => 0,
        'qty_on_hand'  => 0,
        'unit_cost'    => round($unitCost, 4),
    ]);
}

/* ------------------------------------------------------------------ */
/* Receiving                                                          */
/* ------------------------------------------------------------------ */

/**
 * Receive stock: create the batch where the product is tracked, then post.
 * Used by GRN posting, opening balances and inbound transfers.
 */
function stock_receive(array $args): array
{
    $productId = (int) $args['product_id'];
    $storeId   = (int) $args['store_id'];
    $qty       = qty_round((float) $args['qty']);
    $unitCost  = (float) ($args['unit_cost'] ?? 0);

    if ($qty <= 0) {
        throw new StockError('A receipt must be for a positive quantity.');
    }

    $product = q1('SELECT * FROM products WHERE id = ?', [$productId]);
    if (!$product) {
        throw new StockError("Product #$productId does not exist.");
    }

    $batchId = null;
    if ((int) $product['track_batches'] === 1) {
        $batchNo = trim((string) ($args['batch_no'] ?? ''));
        if ($batchNo === '') {
            // Batch tracking with no batch number from the supplier still needs a
            // handle for traceability, so one is derived from the receiving
            // document and date rather than refusing the delivery at the door.
            $batchNo = 'AUTO-' . ($args['ref_no'] ?? date('Ymd')) . '-' . $productId;
        }
        $expiry = $args['expiry_date'] ?? null;
        if ($expiry === null && $product['shelf_life_days'] !== null) {
            $expiry = date('Y-m-d', strtotime('+' . (int) $product['shelf_life_days'] . ' days'));
        }
        $batchId = db_insert('stock_batches', [
            'product_id'   => $productId,
            'store_id'     => $storeId,
            'batch_no'     => substr($batchNo, 0, 40),
            'expiry_date'  => $expiry,
            'qty_received' => $qty,
            'qty_on_hand'  => $qty,
            'unit_cost'    => round($unitCost, 4),
            'grn_id'       => $args['grn_id'] ?? null,
        ]);
    }

    $movementId = stock_post([
        'product_id'    => $productId,
        'store_id'      => $storeId,
        'movement_type' => $args['movement_type'] ?? 'receipt',
        'qty_delta'     => $qty,
        'unit_cost'     => $unitCost,
        'batch_id'      => $batchId,
        'ref_type'      => $args['ref_type'] ?? null,
        'ref_id'        => $args['ref_id'] ?? null,
        'ref_no'        => $args['ref_no'] ?? null,
        'note'          => $args['note'] ?? null,
    ]);

    return ['movement_id' => $movementId, 'batch_id' => $batchId];
}

/**
 * Issue stock, honouring FEFO where batches are tracked. One ledger row per
 * batch touched. Returns the drawn batches with the weighted cost of the issue,
 * which the caller writes onto the sale line for margin reporting.
 */
function stock_issue(array $args): array
{
    $productId = (int) $args['product_id'];
    $storeId   = (int) $args['store_id'];
    $qty       = qty_round((float) $args['qty']);
    $type      = (string) ($args['movement_type'] ?? 'sale');

    if ($qty <= 0) {
        throw new StockError('An issue must be for a positive quantity.');
    }

    $tracked = (int) qv('SELECT track_batches FROM products WHERE id = ?', [$productId], 0) === 1;

    if (!$tracked) {
        $cost = stock_avg_cost($productId, $storeId);
        stock_post([
            'product_id'     => $productId,
            'store_id'       => $storeId,
            'movement_type'  => $type,
            'qty_delta'      => -$qty,
            'unit_cost'      => $cost,
            'ref_type'       => $args['ref_type'] ?? null,
            'ref_id'         => $args['ref_id'] ?? null,
            'ref_no'         => $args['ref_no'] ?? null,
            'reason_code'    => $args['reason_code'] ?? null,
            'note'           => $args['note'] ?? null,
            'allow_negative' => $args['allow_negative'] ?? null,
        ]);
        return ['batches' => [], 'unit_cost' => $cost, 'batch_id' => null];
    }

    // Batch-tracked. Check cover before drawing anything down, so a refusal
    // leaves no half-consumed batches behind.
    $available = stock_on_hand($productId, $storeId);
    $allowNeg  = (bool) ($args['allow_negative'] ?? setting_bool('negative_stock_allowed', NEGATIVE_STOCK_ALLOWED));
    if (!$allowNeg && $available + 0.0005 < $qty) {
        $name = (string) qv('SELECT name FROM products WHERE id = ?', [$productId], "#$productId");
        throw new StockError(sprintf('Not enough stock of %s: %s on hand, %s requested.',
            $name, qty_fmt($available), qty_fmt($qty)));
    }

    $drawn     = stock_draw_batches($productId, $storeId, $qty);
    $totalCost = 0.0;
    foreach ($drawn as $d) {
        stock_post([
            'product_id'     => $productId,
            'store_id'       => $storeId,
            'movement_type'  => $type,
            'qty_delta'      => -$d['qty'],
            'unit_cost'      => $d['unit_cost'],
            'batch_id'       => $d['batch_id'],
            'ref_type'       => $args['ref_type'] ?? null,
            'ref_id'         => $args['ref_id'] ?? null,
            'ref_no'         => $args['ref_no'] ?? null,
            'reason_code'    => $args['reason_code'] ?? null,
            'note'           => $args['note'] ?? null,
            'allow_negative' => $args['allow_negative'] ?? null,
        ]);
        $totalCost += $d['qty'] * $d['unit_cost'];
    }

    return [
        'batches'   => $drawn,
        'unit_cost' => $qty > 0 ? round($totalCost / $qty, 4) : 0.0,
        'batch_id'  => $drawn[0]['batch_id'] ?? null,
    ];
}

/* ------------------------------------------------------------------ */
/* Reservations - parked baskets hold stock without consuming it      */
/* ------------------------------------------------------------------ */
function stock_reserve(int $productId, int $storeId, float $qty): void
{
    stock_lock($productId, $storeId);
    q('UPDATE stock_levels SET qty_reserved = qty_reserved + ? WHERE product_id = ? AND store_id = ?',
      [qty_round($qty), $productId, $storeId]);
}

function stock_release(int $productId, int $storeId, float $qty): void
{
    stock_lock($productId, $storeId);
    // GREATEST(0, ...) so a double release cannot drive the reservation negative
    // and make available stock read higher than on hand.
    q('UPDATE stock_levels SET qty_reserved = GREATEST(0, qty_reserved - ?)
        WHERE product_id = ? AND store_id = ?',
      [qty_round($qty), $productId, $storeId]);
}

/* ------------------------------------------------------------------ */
/* Valuation & replenishment                                          */
/* ------------------------------------------------------------------ */

/** Stock value at weighted average cost, and at retail, for one store. */
function stock_valuation(?int $storeId = null): array
{
    $where  = $storeId ? 'AND sl.store_id = ?' : '';
    $params = $storeId ? [$storeId] : [];
    $row = q1(
        "SELECT COUNT(DISTINCT sl.product_id) AS line_rows,
                COALESCE(SUM(sl.qty_on_hand), 0) AS units,
                COALESCE(SUM(sl.qty_on_hand * sl.avg_cost), 0) AS cost_value,
                COALESCE(SUM(sl.qty_on_hand * p.sell_price), 0) AS retail_value
           FROM stock_levels sl
           JOIN products p ON p.id = sl.product_id
          WHERE sl.qty_on_hand <> 0 $where",
        $params
    );
    $cost   = (float) ($row['cost_value'] ?? 0);
    $retail = (float) ($row['retail_value'] ?? 0);
    return [
        'lines'        => (int) ($row['line_rows'] ?? 0),
        'units'        => (float) ($row['units'] ?? 0),
        'cost_value'   => $cost,
        'retail_value' => $retail,
        'margin_value' => $retail - $cost,
        'margin_pct'   => $retail > 0 ? (($retail - $cost) / $retail) * 100 : 0.0,
    ];
}

/**
 * What to reorder, and how much.
 *
 * Suggested quantity covers the gap to the reorder level plus the demand
 * expected over the supplier's lead time, measured from the last 30 days of
 * actual sales rather than a static figure - a static reorder quantity is how a
 * supermarket ends up with a pallet of something that stopped selling.
 */
function reorder_suggestions(int $storeId, int $lookbackDays = 30): array
{
    $lead = (int) setting_num('default_reorder_lead_days', (float) DEFAULT_REORDER_LEAD_DAYS);

    return qa(
        'SELECT p.id, p.sku, p.name, p.unit_code, p.reorder_level, p.reorder_qty,
                p.cost_price, p.pack_size,
                s.id AS supplier_id, s.name AS supplier_name,
                COALESCE(s.lead_time_days, ?) AS lead_time_days,
                COALESCE(sl.qty_on_hand, 0) AS qty_on_hand,
                COALESCE(sl.qty_reserved, 0) AS qty_reserved,
                COALESCE(sold.units, 0) AS units_sold,
                COALESCE(sold.units, 0) / ? AS daily_rate,
                COALESCE(onorder.qty, 0) AS qty_on_order
           FROM products p
           LEFT JOIN suppliers s ON s.id = p.supplier_id
           LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.store_id = ?
           LEFT JOIN (
                SELECT sline.product_id, SUM(sline.qty) AS units
                  FROM sale_lines sline
                  JOIN sales sa ON sa.id = sline.sale_id
                 WHERE sa.store_id = ? AND sa.status IN (\'completed\',\'partly_refunded\')
                   AND sa.business_date >= (CURDATE() - INTERVAL ? DAY)
                 GROUP BY sline.product_id
           ) sold ON sold.product_id = p.id
           LEFT JOIN (
                SELECT pol.product_id, SUM(pol.qty_ordered - pol.qty_received) AS qty
                  FROM purchase_order_lines pol
                  JOIN purchase_orders po ON po.id = pol.po_id
                 WHERE po.store_id = ? AND po.status IN (\'approved\',\'partly_received\',\'submitted\')
                 GROUP BY pol.product_id
           ) onorder ON onorder.product_id = p.id
          WHERE p.is_active = 1
            AND p.reorder_level > 0
            AND (COALESCE(sl.qty_on_hand, 0) - COALESCE(sl.qty_reserved, 0)
                 + COALESCE(onorder.qty, 0)) <= p.reorder_level
          ORDER BY (COALESCE(sl.qty_on_hand,0) / NULLIF(p.reorder_level,0)) ASC, p.name ASC',
        [$lead, max(1, $lookbackDays), $storeId, $storeId, $lookbackDays, $storeId]
    );
}

/** Suggested order quantity for one reorder row, rounded up to a supplier case. */
function reorder_qty_for(array $row): float
{
    $onHand  = (float) $row['qty_on_hand'] - (float) $row['qty_reserved'] + (float) $row['qty_on_order'];
    $level   = (float) $row['reorder_level'];
    $daily   = (float) $row['daily_rate'];
    $lead    = (int) $row['lead_time_days'];
    $fixed   = (float) $row['reorder_qty'];

    $need = max($level - $onHand, 0) + ($daily * $lead);
    if ($fixed > 0) {
        $need = max($need, $fixed);
    }
    $pack = (float) ($row['pack_size'] ?? 1);
    if ($pack > 1) {
        $need = ceil($need / $pack) * $pack;   // suppliers sell cases, not units
    }
    return qty_round(max($need, 0));
}

/** Batches at or near expiry. The main perishables control. */
function expiring_batches(?int $storeId, int $withinDays): array
{
    $where  = $storeId ? 'AND b.store_id = ?' : '';
    $params = [$withinDays];
    if ($storeId) {
        $params[] = $storeId;
    }
    return qa(
        "SELECT b.*, p.sku, p.name, p.unit_code, p.sell_price, st.name AS store_name,
                DATEDIFF(b.expiry_date, CURDATE()) AS days_left,
                b.qty_on_hand * b.unit_cost AS value_at_risk
           FROM stock_batches b
           JOIN products p ON p.id = b.product_id
           JOIN stores st ON st.id = b.store_id
          WHERE b.qty_on_hand > 0
            AND b.expiry_date IS NOT NULL
            AND b.expiry_date <= (CURDATE() + INTERVAL ? DAY)
            $where
          ORDER BY b.expiry_date ASC, value_at_risk DESC",
        $params
    );
}

/** Products with stock but no sale in N days: markdown or delist candidates. */
function slow_movers(int $storeId, int $days): array
{
    return qa(
        'SELECT p.id, p.sku, p.name, p.unit_code, p.sell_price,
                sl.qty_on_hand, sl.avg_cost, sl.last_sold_at,
                sl.qty_on_hand * sl.avg_cost AS tied_up,
                DATEDIFF(CURDATE(), DATE(sl.last_sold_at)) AS days_since_sale
           FROM stock_levels sl
           JOIN products p ON p.id = sl.product_id
          WHERE sl.store_id = ?
            AND sl.qty_on_hand > 0
            AND p.is_active = 1
            AND (sl.last_sold_at IS NULL OR sl.last_sold_at < (NOW() - INTERVAL ? DAY))
          ORDER BY tied_up DESC',
        [$storeId, $days]
    );
}

/**
 * Reconcile the position cache against its own ledger.
 *
 * The invariant this file is built around, checked rather than assumed. Any row
 * returned is a bug or a tampered record, and both are worth knowing about;
 * audit/shrinkage surfaces it and the smoke test fails on it.
 */
function stock_ledger_discrepancies(?int $storeId = null): array
{
    $where  = $storeId ? 'WHERE sl.store_id = ?' : '';
    $params = $storeId ? [$storeId] : [];
    return qa(
        "SELECT sl.product_id, sl.store_id, p.sku, p.name, st.name AS store_name,
                sl.qty_on_hand,
                COALESCE(led.total, 0) AS ledger_qty,
                sl.qty_on_hand - COALESCE(led.total, 0) AS difference
           FROM stock_levels sl
           JOIN products p ON p.id = sl.product_id
           JOIN stores st ON st.id = sl.store_id
           LEFT JOIN (
                SELECT product_id, store_id, SUM(qty_delta) AS total
                  FROM stock_movements GROUP BY product_id, store_id
           ) led ON led.product_id = sl.product_id AND led.store_id = sl.store_id
           $where
          HAVING ABS(difference) > 0.0005
          ORDER BY ABS(difference) DESC",
        $params
    );
}
