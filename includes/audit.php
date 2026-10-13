<?php
/**
 * The audit trail: append-only and hash-chained.
 *
 * Each row's row_hash is SHA-256 over that row's own significant fields plus
 * the previous row's row_hash. The table is therefore a chain. Editing a
 * historic row, or deleting one, invalidates every hash from that point on, and
 * audit_verify_chain() re-walks the chain and reports exactly where it broke.
 *
 * That matters because the people with the most reason to alter a supermarket's
 * records are the ones with database access. A plain log tells you what someone
 * chose to leave in it; a chained log tells you whether anything was taken out.
 *
 * Rules this file exists to enforce, and which nothing else may break:
 *   - no UPDATE and no DELETE against audit_log anywhere in the codebase
 *   - the chain is extended under a row lock, so two concurrent writers cannot
 *     both chain off the same predecessor and leave a fork
 */
declare(strict_types=1);

/** Hash of the empty chain: the prev_hash of the very first row. */
const AUDIT_GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

/**
 * The hash input. Field order and formatting are fixed forever: change them and
 * every historic row stops verifying, so new fields must be appended, never
 * inserted, and never reformatted.
 */
function audit_row_hash(array $r): string
{
    $payload = implode('|', [
        (string) ($r['id'] ?? ''),
        (string) ($r['store_id'] ?? ''),
        (string) ($r['user_id'] ?? ''),
        (string) ($r['action'] ?? ''),
        (string) ($r['entity'] ?? ''),
        (string) ($r['entity_id'] ?? ''),
        (string) ($r['summary'] ?? ''),
        (string) ($r['before_json'] ?? ''),
        (string) ($r['after_json'] ?? ''),
        (string) ($r['severity'] ?? ''),
        (string) ($r['created_at'] ?? ''),
        (string) ($r['prev_hash'] ?? ''),
    ]);
    return hash('sha256', $payload);
}

/**
 * Append one entry to the trail.
 *
 * Never throws out to the caller. A business action that succeeded must not be
 * rolled back because its log entry failed - that would turn a logging fault
 * into lost stock. Instead the failure is recorded to the PHP error log, which
 * is itself outside the application's reach.
 */
function audit_log(
    string $action,
    string $entity,
    ?int $entityId = null,
    string $summary = '',
    ?array $before = null,
    ?array $after = null,
    string $severity = 'info',
    ?int $storeId = null
): void {
    try {
        $uid   = (int) ($_SESSION['uid'] ?? 0) ?: null;
        $label = $_SESSION['user_name'] ?? ($uid ? null : 'system');
        $store = $storeId ?? current_store_id();

        // created_at is generated here rather than by the database, because the
        // hash covers it and must be over the exact value stored.
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.v');

        $row = [
            'store_id'    => $store,
            'user_id'     => $uid,
            'user_label'  => $label !== null ? substr((string) $label, 0, 120) : null,
            'action'      => substr($action, 0, 60),
            'entity'      => substr($entity, 0, 40),
            'entity_id'   => $entityId,
            'summary'     => substr($summary !== '' ? $summary : $action, 0, 255),
            'before_json' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            'after_json'  => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            'severity'    => one_of($severity, ['info', 'notice', 'warning', 'critical'], 'info'),
            'ip'          => client_ip(),
            'user_agent'  => client_agent(),
            'created_at'  => $now,
        ];

        // The chain must be extended serially. Reading the tail under FOR UPDATE
        // inside the caller's transaction serialises concurrent writers: the
        // second waits for the first to commit and chains off its real hash.
        db_tx(function () use ($row): void {
            $tail = q1('SELECT id, row_hash FROM audit_log ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $row['prev_hash'] = $tail['row_hash'] ?? AUDIT_GENESIS_HASH;

            // id is part of the hash but is only known after the insert, so the
            // row goes in with a placeholder hash and is immediately sealed.
            $row['row_hash'] = '';
            $id = db_insert('audit_log', $row);

            $row['id']       = $id;
            $row['row_hash'] = audit_row_hash($row);
            q('UPDATE audit_log SET row_hash = ? WHERE id = ? AND row_hash = \'\'',
              [$row['row_hash'], $id]);
        });
    } catch (Throwable $e) {
        error_log('[TillPoint audit] failed to log ' . $action . ': ' . $e->getMessage());
    }
}

/**
 * Re-walk the chain and report the first row that does not verify.
 *
 * Returns ['result' => 'ok'|'broken'|'empty', 'rows_checked', 'broken_at_id',
 * 'first_id', 'last_id', 'detail']. Streams in blocks so a long trail does not
 * have to fit in memory.
 */
function audit_verify_chain(?int $fromId = null, ?int $limit = null): array
{
    $prevHash = AUDIT_GENESIS_HASH;
    $checked  = 0;
    $firstId  = null;
    $lastId   = null;
    $cursor   = 0;
    $block    = 500;

    // Starting partway along, the expected prev_hash is the hash already stored
    // on the preceding row. That trusts the earlier section, so a partial check
    // proves only that this section is internally consistent.
    if ($fromId !== null && $fromId > 1) {
        $prev = q1('SELECT row_hash FROM audit_log WHERE id < ? ORDER BY id DESC LIMIT 1', [$fromId]);
        $prevHash = $prev['row_hash'] ?? AUDIT_GENESIS_HASH;
        $cursor   = $fromId - 1;
    }

    while (true) {
        $rows = qa(
            'SELECT id, store_id, user_id, action, entity, entity_id, summary,
                    before_json, after_json, severity, created_at, prev_hash, row_hash
               FROM audit_log
              WHERE id > ?
              ORDER BY id ASC
              LIMIT ' . (int) $block,
            [$cursor]
        );
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $cursor  = (int) $r['id'];
            $firstId ??= $cursor;
            $lastId  = $cursor;
            $checked++;

            if ($r['prev_hash'] !== $prevHash) {
                return [
                    'result'       => 'broken',
                    'rows_checked' => $checked,
                    'first_id'     => $firstId,
                    'last_id'      => $lastId,
                    'broken_at_id' => $cursor,
                    'detail'       => "Row #$cursor claims to follow " . substr((string) $r['prev_hash'], 0, 12)
                                      . '... but the preceding row hashes to ' . substr($prevHash, 0, 12)
                                      . '... - a row was inserted, removed or reordered here.',
                ];
            }
            $expected = audit_row_hash($r);
            if (!hash_equals((string) $r['row_hash'], $expected)) {
                return [
                    'result'       => 'broken',
                    'rows_checked' => $checked,
                    'first_id'     => $firstId,
                    'last_id'      => $lastId,
                    'broken_at_id' => $cursor,
                    'detail'       => "Row #$cursor does not match its own hash - its contents were altered after it was written.",
                ];
            }
            $prevHash = (string) $r['row_hash'];

            if ($limit !== null && $checked >= $limit) {
                break 2;
            }
        }
    }

    return [
        'result'       => $checked === 0 ? 'empty' : 'ok',
        'rows_checked' => $checked,
        'first_id'     => $firstId,
        'last_id'      => $lastId,
        'broken_at_id' => null,
        'detail'       => $checked === 0
            ? 'The audit trail is empty.'
            : "Verified $checked entries; every hash matches and the chain is unbroken.",
    ];
}

/** Run a verification and keep the result, so checks themselves are evidenced. */
function audit_record_integrity_check(?int $runBy = null, string $note = ''): array
{
    $res = audit_verify_chain();
    db_insert('audit_integrity_checks', [
        'run_by'       => $runBy,
        'rows_checked' => $res['rows_checked'],
        'first_id'     => $res['first_id'],
        'last_id'      => $res['last_id'],
        'result'       => $res['result'],
        'broken_at_id' => $res['broken_at_id'],
        'note'         => substr($note !== '' ? $note : $res['detail'], 0, 255),
    ]);
    if ($res['result'] === 'broken') {
        notify_role('auditor', 'critical', 'Audit trail integrity check FAILED',
            $res['detail'], base_url('audit/integrity'));
        notify_role('admin', 'critical', 'Audit trail integrity check FAILED',
            $res['detail'], base_url('audit/integrity'));
    }
    return $res;
}

/* ------------------------------------------------------------------ */
/* Exceptions raised inline by posting routines                       */
/* ------------------------------------------------------------------ */

/**
 * Raise (or refresh) an audit exception.
 *
 * Deduplicated on the unique key rule+date+store+subject+entity, so the nightly
 * scan can run repeatedly without flooding the queue, and a re-detected
 * exception keeps its id and therefore its review history. A re-detection does
 * NOT reopen an exception a reviewer has already disposed of - that would let a
 * cron job overrule a human decision - except to bump the metric.
 */
function audit_raise_exception(string $ruleCode, array $args): ?int
{
    try {
        $rule = q1('SELECT * FROM audit_rules WHERE code = ? AND is_active = 1', [$ruleCode]);
        if (!$rule) {
            return null;   // unknown or switched-off rule: nothing to raise
        }
        $row = [
            'rule_code'       => $ruleCode,
            'store_id'        => $args['store_id'] ?? current_store_id(),
            'subject_user_id' => $args['subject_user'] ?? null,
            'entity'          => isset($args['entity']) ? substr((string) $args['entity'], 0, 40) : null,
            'entity_id'       => $args['entity_id'] ?? null,
            'period_date'     => $args['period_date'] ?? date('Y-m-d'),
            'severity'        => $args['severity'] ?? $rule['severity'],
            'metric_value'    => isset($args['metric']) ? (float) $args['metric'] : null,
            'threshold'       => $args['threshold'] ?? $rule['threshold'],
            'summary'         => substr((string) ($args['summary'] ?? $rule['name']), 0, 255),
            'detail_json'     => isset($args['detail']) ? json_encode($args['detail'], JSON_UNESCAPED_UNICODE) : null,
        ];

        // The dedupe key spans nullable columns, and MySQL treats NULLs as
        // distinct in a unique index - so an INSERT .. ON DUPLICATE KEY would
        // silently insert a duplicate whenever subject or entity is NULL. Look
        // the row up with NULL-safe comparisons instead.
        $existing = q1(
            'SELECT id, status FROM audit_exceptions
              WHERE rule_code = ? AND period_date <=> ? AND store_id <=> ?
                AND subject_user_id <=> ? AND entity_id <=> ?
              LIMIT 1',
            [$ruleCode, $row['period_date'], $row['store_id'], $row['subject_user_id'], $row['entity_id']]
        );

        if ($existing) {
            q('UPDATE audit_exceptions
                  SET metric_value = ?, summary = ?, detail_json = ?, severity = ?, detected_at = NOW()
                WHERE id = ?',
              [$row['metric_value'], $row['summary'], $row['detail_json'], $row['severity'], $existing['id']]);
            return (int) $existing['id'];
        }

        $id = db_insert('audit_exceptions', $row);

        if (in_array($row['severity'], ['high', 'critical'], true)) {
            notify_role('auditor', 'critical', 'Audit exception: ' . $rule['name'],
                $row['summary'], base_url('audit/exception', ['id' => $id]));
            notify_role('manager', 'warning', 'Audit exception: ' . $rule['name'],
                $row['summary'], base_url('audit/exception', ['id' => $id]));
        }
        return $id;
    } catch (Throwable $e) {
        error_log('[TillPoint audit] could not raise exception ' . $ruleCode . ': ' . $e->getMessage());
        return null;
    }
}

/* ------------------------------------------------------------------ */
/* Notifications                                                      */
/* ------------------------------------------------------------------ */
function notify_user(int $userId, string $severity, string $title, string $body = '', string $link = ''): void
{
    try {
        db_insert('notifications', [
            'user_id'  => $userId,
            'severity' => one_of($severity, ['info', 'warning', 'critical'], 'info'),
            'title'    => substr($title, 0, 160),
            'body'     => substr($body, 0, 500),
            'link'     => substr($link, 0, 255) ?: null,
            'store_id' => current_store_id(),
        ]);
    } catch (Throwable $e) {
        error_log('[TillPoint notify] ' . $e->getMessage());
    }
}

function notify_role(string $roleCode, string $severity, string $title, string $body = '', string $link = ''): void
{
    try {
        db_insert('notifications', [
            'role_code' => $roleCode,
            'severity'  => one_of($severity, ['info', 'warning', 'critical'], 'info'),
            'title'     => substr($title, 0, 160),
            'body'      => substr($body, 0, 500),
            'link'      => substr($link, 0, 255) ?: null,
            'store_id'  => current_store_id(),
        ]);
    } catch (Throwable $e) {
        error_log('[TillPoint notify] ' . $e->getMessage());
    }
}

/** Unread notifications for the signed-in user, by user id or by their role. */
function my_notifications(int $limit = 12): array
{
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if ($uid === 0) {
        return [];
    }
    return qa(
        'SELECT n.* FROM notifications n
          WHERE n.read_at IS NULL
            AND (n.user_id = ? OR n.role_code = ?)
          ORDER BY FIELD(n.severity, \'critical\',\'warning\',\'info\'), n.created_at DESC
          LIMIT ' . (int) $limit,
        [$uid, (string) ($_SESSION['role_code'] ?? '')]
    );
}
