<?php
/**
 * Document numbers: RC-0001-202610-000123 style, allocated without gaps or
 * collisions.
 *
 * Receipt numbers are the spine of the audit trail, so the allocation has to be
 * right under concurrency. Two tills ringing up at the same moment must not both
 * get 000123, and MAX(receipt_no)+1 would do exactly that. Instead a counter row
 * per (doc_type, store, period) is locked FOR UPDATE and incremented, so the
 * second till waits microseconds and gets 000124.
 *
 * A gap in a receipt sequence is itself an audit signal - it means a sale was
 * started and discarded - so numbers are allocated at the point the document is
 * created and never reused.
 */
declare(strict_types=1);

/** Document type => [prefix, store-scoped, period-scoped, pad width]. */
function doc_type_config(): array
{
    return [
        'receipt'  => ['RC',  true,  true,  6],
        'refund'   => ['RF',  true,  true,  5],
        'session'  => ['TS',  true,  true,  5],
        'po'       => ['PO',  true,  true,  5],
        'grn'      => ['GRN', true,  true,  5],
        'transfer' => ['TRF', true,  true,  5],
        'count'    => ['SC',  true,  true,  4],
        'wastage'  => ['WST', true,  true,  5],
        'adjust'   => ['ADJ', true,  true,  4],
        'customer'         => ['CUS', false, false, 6],
        'voucher'          => ['VCH', false, false, 6],
        'supplier_invoice' => ['SI',  true,  true,  5],
    ];
}

function next_number(string $docType, ?int $storeId = null): string
{
    $cfg = doc_type_config()[$docType] ?? null;
    if ($cfg === null) {
        throw new InvalidArgumentException("Unknown document type '$docType'.");
    }
    [$prefix, $storeScoped, $periodScoped, $pad] = $cfg;

    $store  = $storeScoped ? (int) ($storeId ?? current_store_id() ?? 0) : 0;
    $period = $periodScoped ? date('Ym') : '';

    return db_tx(function () use ($docType, $store, $period, $prefix, $pad, $storeScoped, $periodScoped): string {
        // INSERT .. ON DUPLICATE KEY UPDATE both creates the counter on first use
        // and takes the row lock, in one statement, so there is no window between
        // "does the counter exist" and "lock it".
        q('INSERT INTO number_sequences (doc_type, store_id, period, last_no)
                VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE last_no = last_no + 1',
          [$docType, $store, $period]);

        $n = (int) qv('SELECT last_no FROM number_sequences
                        WHERE doc_type = ? AND store_id = ? AND period = ?',
                      [$docType, $store, $period], 1);

        $parts = [$prefix];
        if ($storeScoped) {
            $code = qv('SELECT code FROM stores WHERE id = ?', [$store]);
            $parts[] = $code !== null ? (string) $code : str_pad((string) $store, 3, '0', STR_PAD_LEFT);
        }
        if ($periodScoped) {
            $parts[] = $period;
        }
        $parts[] = str_pad((string) $n, $pad, '0', STR_PAD_LEFT);

        return implode('-', $parts);
    });
}

/**
 * Gaps in a store's receipt sequence for a period. A started-then-abandoned
 * sale leaves a hole; a lot of holes on one till is worth asking about, so the
 * audit scan reads this.
 */
function receipt_sequence_gaps(int $storeId, string $period): array
{
    $issued = (int) qv('SELECT last_no FROM number_sequences
                         WHERE doc_type = \'receipt\' AND store_id = ? AND period = ?',
                       [$storeId, $period], 0);
    if ($issued === 0) {
        return ['issued' => 0, 'present' => 0, 'missing' => 0, 'gap_numbers' => []];
    }
    $prefix = 'RC-' . (string) qv('SELECT code FROM stores WHERE id = ?', [$storeId], '') . '-' . $period . '-';

    $present = qcol(
        'SELECT CAST(SUBSTRING(receipt_no, ?) AS UNSIGNED) AS n
           FROM sales WHERE store_id = ? AND receipt_no LIKE ?',
        [strlen($prefix) + 1, $storeId, $prefix . '%']
    );
    $have    = array_fill_keys(array_map('intval', $present), true);
    $missing = [];
    for ($i = 1; $i <= $issued; $i++) {
        if (!isset($have[$i])) {
            $missing[] = $i;
        }
    }
    return [
        'issued'      => $issued,
        'present'     => count($have),
        'missing'     => count($missing),
        'gap_numbers' => array_slice($missing, 0, 50),
    ];
}
