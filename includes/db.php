<?php
/**
 * PDO connection + thin query helpers used everywhere in TillPoint.
 * All statements are prepared; no value is ever concatenated into SQL.
 */
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
        if (APP_DEBUG) {
            die('Database connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES)
                . '<br>Run <a href="' . BASE_PATH . '/install.php">install.php</a> to create the database.');
        }
        die('Database unavailable. Please try again later.');
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Single row or null. */
function q1(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** All rows. */
function qa(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** First column of the first row, with a fallback. */
function qv(string $sql, array $params = [], $default = null)
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

/** Key => value pairs from a two-column select. */
function qpairs(string $sql, array $params = []): array
{
    $out = [];
    foreach (qa($sql, $params) as $r) {
        $vals = array_values($r);
        $out[$vals[0]] = $vals[1] ?? null;
    }
    return $out;
}

/** Single column as a flat list. */
function qcol(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql  = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(fn ($c) => "`$c`", $cols)),
        implode(', ', array_map(fn ($c) => ":$c", $cols))
    );
    q($sql, $data);
    return (int) db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    if (!$data) {
        return 0;
    }
    $sets   = implode(', ', array_map(fn ($c) => "`$c` = :s_$c", array_keys($data)));
    $params = [];
    foreach ($data as $k => $v) {
        $params["s_$k"] = $v;
    }
    foreach ($whereParams as $k => $v) {
        $params[$k] = $v;
    }
    return q("UPDATE `$table` SET $sets WHERE $where", $params)->rowCount();
}

/**
 * Run $fn inside a transaction, committing on return and rolling back on throw.
 * Nestable: an inner call joins the outer transaction instead of starting one,
 * so a posting routine can be called standalone or as part of a bigger unit of
 * work (a sale that also moves stock) without either committing half of it.
 */
function db_tx(callable $fn)
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $result = $fn($pdo);
        if ($own) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Lock a row for the rest of the transaction.
 *
 * Stock posting reads a balance, adds to it and writes it back. Two tills
 * selling the last unit of the same product at the same instant would both read
 * the same balance and both succeed, so every write path takes this lock on the
 * stock_levels row first. Pointless outside a transaction - MySQL releases the
 * lock at the end of the implicit one - so it refuses rather than pretending.
 */
function db_lock_row(string $table, string $where, array $params = []): ?array
{
    if (!db()->inTransaction()) {
        throw new RuntimeException("db_lock_row('$table') called outside a transaction; the lock would be released immediately.");
    }
    return q1("SELECT * FROM `$table` WHERE $where FOR UPDATE", $params);
}
