<?php
/**
 * The promotion engine.
 *
 * Given a product, a quantity and the moment of sale, work out what the line
 * should cost. Called by the till for every line, so it is written to be cheap:
 * the candidate promotions for the current moment are loaded once per request
 * and matched in PHP rather than queried per line.
 *
 * One promotion applies per line, chosen by priority and then by whichever saves
 * the customer most. Stacking discounts is how a shop accidentally sells below
 * cost, and the till operator cannot be the one to notice.
 */
declare(strict_types=1);

/** Promotions live right now, cached per request. */
function promotions_live(?DateTimeImmutable $at = null): array
{
    static $cache = [];
    $at  = $at ?? new DateTimeImmutable('now');
    $key = $at->format('Y-m-d H');
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $rows = qa(
        'SELECT * FROM promotions
          WHERE is_active = 1 AND starts_at <= ? AND ends_at >= ?
          ORDER BY priority ASC, id ASC',
        [$at->format('Y-m-d H:i:s'), $at->format('Y-m-d H:i:s')]
    );

    // Day-of-week and hour windows are checked here rather than in SQL: the
    // bitmask and the wrap-around hour range are both awkward to express in SQL
    // and easy to get subtly wrong there.
    $dow  = (int) $at->format('N') - 1;      // 0 = Monday
    $hour = (int) $at->format('G');

    $live = [];
    foreach ($rows as $p) {
        if ((((int) $p['days_mask']) & (1 << $dow)) === 0) {
            continue;
        }
        if ($p['hour_from'] !== null && $p['hour_to'] !== null) {
            $from = (int) $p['hour_from'];
            $to   = (int) $p['hour_to'];
            // A window like 22-02 crosses midnight, so it is not a simple range.
            $in = $from <= $to ? ($hour >= $from && $hour < $to) : ($hour >= $from || $hour < $to);
            if (!$in) {
                continue;
            }
        }
        $live[] = $p;
    }
    return $cache[$key] = $live;
}

/** Promotions that could apply to one product. */
function promotions_for_product(array $product, ?DateTimeImmutable $at = null): array
{
    $out = [];
    foreach (promotions_live($at) as $p) {
        $matches = match ($p['scope']) {
            'product'  => (int) $p['product_id'] === (int) $product['id'],
            'category' => (int) $p['category_id'] === (int) $product['category_id'],
            'all'      => true,
            default    => false,
        };
        if ($matches) {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * Price one line.
 *
 * Returns ['unit_price', 'line_total', 'discount', 'promotion_id', 'promo_label'].
 * unit_price is the effective VAT-inclusive price per unit after the promotion,
 * which is what the receipt shows; line_total is authoritative, because a BOGO
 * cannot be expressed exactly as a per-unit figure (3 for the price of 2 gives
 * a recurring decimal) and rounding the unit price would make the lines not add
 * up to the total.
 */
function promo_price_line(array $product, float $qty, ?DateTimeImmutable $at = null): array
{
    $list  = (float) $product['sell_price'];
    $gross = money_round($list * $qty);

    $best = [
        'unit_price'   => $list,
        'line_total'   => $gross,
        'discount'     => 0.0,
        'promotion_id' => null,
        'promo_label'  => null,
    ];

    foreach (promotions_for_product($product, $at) as $p) {
        $minQty = (float) $p['min_qty'];
        if ($qty + 0.0005 < $minQty) {
            continue;
        }
        $value = (float) $p['value'];

        $total = match ($p['promo_type']) {
            'pct_off'     => $gross * (1 - min(max($value, 0), 100) / 100),
            'bundle_pct'  => $gross * (1 - min(max($value, 0), 100) / 100),
            'fixed_price' => $value * $qty,
            'bogo'        => promo_bogo_total($qty, $list, $minQty, (float) $p['free_qty']),
            default       => $gross,
        };
        $total = money_round(max(0.0, $total));

        if ($total < $best['line_total'] - 0.005) {
            $best = [
                // Reported for the receipt only; line_total is what is charged.
                'unit_price'   => $qty > 0 ? money_round($total / $qty) : $list,
                'line_total'   => $total,
                'discount'     => money_round($gross - $total),
                'promotion_id' => (int) $p['id'],
                'promo_label'  => (string) $p['name'],
            ];
        }
    }
    return $best;
}

/**
 * Buy min_qty, get free_qty free. Only whole groups qualify; the remainder is
 * charged at the list price, which is how a shelf-edge offer actually behaves -
 * "3 for 2" does not give a third off two items.
 */
function promo_bogo_total(float $qty, float $unitPrice, float $minQty, float $freeQty): float
{
    if ($minQty <= 0 || $freeQty <= 0) {
        return $qty * $unitPrice;
    }
    $groupSize = $minQty + $freeQty;
    $groups    = floor(($qty + 0.0005) / $groupSize);
    $remainder = $qty - ($groups * $groupSize);
    // Within a partial group, up to min_qty is still charged.
    $chargeable = ($groups * $minQty) + min($remainder, $minQty);
    return $chargeable * $unitPrice;
}

/** Human description for a promotions list. */
function promo_describe(array $p): string
{
    $v = (float) $p['value'];
    return match ($p['promo_type']) {
        'pct_off'     => pct_fmt($v, 0) . ' off',
        'bundle_pct'  => pct_fmt($v, 0) . ' off when you buy ' . qty_fmt((float) $p['min_qty']) . '+',
        'fixed_price' => money($v) . ' each',
        'bogo'        => 'Buy ' . qty_fmt((float) $p['min_qty']) . ', get ' . qty_fmt((float) $p['free_qty']) . ' free',
        default       => '-',
    };
}

function promo_scope_label(array $p): string
{
    return match ($p['scope']) {
        'product'  => (string) qv('SELECT name FROM products WHERE id = ?', [(int) $p['product_id']], 'Product'),
        'category' => 'Category: ' . (string) qv('SELECT name FROM categories WHERE id = ?', [(int) $p['category_id']], ''),
        'all'      => 'Everything',
        default    => '-',
    };
}

/** Bit names for the days_mask editor. */
function promo_day_names(): array
{
    return ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
}

function promo_days_label(int $mask): string
{
    if (($mask & 127) === 127) {
        return 'Every day';
    }
    $out = [];
    foreach (promo_day_names() as $i => $d) {
        if ($mask & (1 << $i)) {
            $out[] = $d;
        }
    }
    return $out ? implode(', ', $out) : 'Never';
}
