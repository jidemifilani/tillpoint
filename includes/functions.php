<?php
/**
 * Shared helpers: URLs, escaping, formatting, flash messages, filters.
 */
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* URLs - every internal link goes through here so the .php extension */
/* is stripped in one place (see .htaccess for the matching rewrite). */
/* ------------------------------------------------------------------ */
function base_url(string $path = '', array $query = []): string
{
    $path = ltrim($path, '/');
    $path = preg_replace('/\.php$/i', '', $path);          // drop .php
    $path = preg_replace('#(^|/)index$#', '$1', $path);    // /foo/index -> /foo/
    $url  = rtrim(BASE_PATH . '/' . $path, '/');
    if ($url === '') {
        $url = BASE_PATH !== '' ? BASE_PATH : '/';
    }
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

function asset_url(string $path): string
{
    return BASE_PATH . '/assets/' . ltrim($path, '/');
}

function redirect(string $path, array $query = []): never
{
    $target = str_starts_with($path, 'http') ? $path : base_url($path, $query);
    header('Location: ' . $target);
    exit;
}

/** Current URL with some query parameters changed; null removes one. */
function url_with(array $changes): string
{
    $q = $_GET;
    foreach ($changes as $k => $v) {
        if ($v === null) {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
    return $q ? $path . '?' . http_build_query($q) : (string) $path;
}

/* ------------------------------------------------------------------ */
/* Escaping & formatting                                              */
/* ------------------------------------------------------------------ */
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($amount, bool $symbol = true): string
{
    $s = $symbol ? CURRENCY_SYMBOL : '';
    return $s . number_format((float) $amount, 2);
}

/** Compact money for dashboard tiles: N1.20m, N450.00k. */
function money_short($amount): string
{
    $a   = (float) $amount;
    $abs = abs($a);
    if ($abs >= 1_000_000_000) return CURRENCY_SYMBOL . number_format($a / 1_000_000_000, 2) . 'bn';
    if ($abs >= 1_000_000)     return CURRENCY_SYMBOL . number_format($a / 1_000_000, 2) . 'm';
    if ($abs >= 1_000)         return CURRENCY_SYMBOL . number_format($a / 1_000, 1) . 'k';
    return CURRENCY_SYMBOL . number_format($a, 2);
}

/**
 * Quantities print without trailing noise: 3 rather than 3.000, but 0.25 kg
 * stays 0.25. Supermarket stock is mostly whole units with a minority weighed,
 * and padding every shelf count to 3dp makes a stock sheet unreadable.
 */
function qty_fmt($qty): string
{
    $q = (float) $qty;
    if (abs($q - round($q)) < 0.0005) {
        return number_format($q, 0);
    }
    return rtrim(rtrim(number_format($q, 3), '0'), '.');
}

function pct_fmt($v, int $dp = 1): string
{
    return number_format((float) $v, $dp) . '%';
}

function date_fmt(?string $dt, bool $withTime = false): string
{
    if (!$dt || str_starts_with($dt, '0000')) {
        return '-';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return '-';
    }
    return date($withTime ? 'd M Y, H:i' : 'd M Y', $ts);
}

/** "3 days ago", for activity feeds. */
function ago(?string $dt): string
{
    if (!$dt) {
        return '-';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return '-';
    }
    $d = time() - $ts;
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return intdiv($d, 60) . 'm ago';
    if ($d < 86400)  return intdiv($d, 3600) . 'h ago';
    if ($d < 604800) return intdiv($d, 86400) . 'd ago';
    return date('d M Y', $ts);
}

/* ------------------------------------------------------------------ */
/* Request input                                                      */
/* ------------------------------------------------------------------ */
function is_post(): bool
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

function post(string $key, $default = ''): mixed
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function get(string $key, $default = ''): mixed
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function post_int(string $key, int $default = 0): int
{
    $v = $_POST[$key] ?? null;
    return is_numeric($v) ? (int) $v : $default;
}

function get_int(string $key, int $default = 0): int
{
    $v = $_GET[$key] ?? null;
    return is_numeric($v) ? (int) $v : $default;
}

/**
 * Money and quantity arrive as typed text: "1,250.50", " 3 ", "". Strip the
 * grouping commas and spaces before casting, because (float) "1,250.50" is 1.0
 * and a silent factor-of-1000 error in a stock figure is worse than a rejection.
 */
function post_num(string $key, float $default = 0.0): float
{
    $v = $_POST[$key] ?? null;
    if ($v === null || $v === '') {
        return $default;
    }
    $clean = str_replace([',', ' ', "\u{00A0}"], '', (string) $v);
    return is_numeric($clean) ? (float) $clean : $default;
}

function num(mixed $v, float $default = 0.0): float
{
    if ($v === null || $v === '') {
        return $default;
    }
    $clean = str_replace([',', ' ', "\u{00A0}"], '', (string) $v);
    return is_numeric($clean) ? (float) $clean : $default;
}

/** A value from a fixed list, or the first entry. Guards every ENUM write. */
function one_of(mixed $v, array $allowed, ?string $default = null): string
{
    $v = is_string($v) ? trim($v) : '';
    return in_array($v, $allowed, true) ? $v : (string) ($default ?? $allowed[0]);
}

/** YYYY-MM-DD or null. Rejects anything that is not a real calendar date. */
function date_or_null(mixed $v): ?string
{
    $v = is_string($v) ? trim($v) : '';
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

/** Money rounded to 2dp. All stored money passes through here. */
function money_round(float $v): float
{
    return round($v, 2);
}

/** Quantity rounded to the 3dp the schema stores. */
function qty_round(float $v): float
{
    return round($v, 3);
}

/* ------------------------------------------------------------------ */
/* Flash messages                                                     */
/* ------------------------------------------------------------------ */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_ok(string $m): void   { flash('success', $m); }
function flash_err(string $m): void  { flash('error', $m); }
function flash_warn(string $m): void { flash('warning', $m); }
function flash_info(string $m): void { flash('info', $m); }

function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------------------------------------------ */
/* Settings - DB-backed, with the config.php constant as the fallback */
/* ------------------------------------------------------------------ */
/**
 * A threshold a manager can tune (till tolerance, refund limit) lives in the
 * `settings` table; the constant in config.php is only the value it ships with.
 * Reading through here means a tuned value takes effect everywhere at once.
 */
function setting(string $key, mixed $default = null): mixed
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (qa('SELECT skey, svalue, value_type FROM settings') as $r) {
                $cache[$r['skey']] = setting_cast($r['svalue'], $r['value_type']);
            }
        } catch (Throwable) {
            // Before install.php has run there is no settings table. Falling back
            // to defaults keeps the installer itself usable.
            $cache = [];
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== null ? $cache[$key] : $default;
}

function setting_cast(?string $raw, string $type): mixed
{
    if ($raw === null) {
        return null;
    }
    return match ($type) {
        'int'     => (int) $raw,
        'decimal' => (float) $raw,
        'bool'    => in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true),
        'json'    => json_decode($raw, true),
        default   => $raw,
    };
}

function setting_num(string $key, float $default): float
{
    $v = setting($key, null);
    return is_numeric($v) ? (float) $v : $default;
}

function setting_bool(string $key, bool $default): bool
{
    $v = setting($key, null);
    return $v === null ? $default : (bool) $v;
}

/* ------------------------------------------------------------------ */
/* Small view helpers                                                 */
/* ------------------------------------------------------------------ */
function badge(string $text, string $tone = 'neutral'): string
{
    return '<span class="badge badge--' . e($tone) . '">' . e($text) . '</span>';
}

/** Maps a status string to a badge tone, so one status looks the same app-wide. */
function status_badge(?string $status): string
{
    $s = (string) $status;
    $tone = match ($s) {
        'posted', 'completed', 'approved', 'received', 'closed', 'reconciled',
        'active', 'cleared', 'ok'                          => 'ok',
        'draft', 'open', 'parked', 'pending', 'submitted',
        'reviewing', 'counted'                             => 'warn',
        'cancelled', 'voided', 'rejected', 'expired',
        'broken', 'confirmed'                              => 'bad',
        'partly_received', 'partly_refunded', 'dispatched',
        'refunded', 'escalated'                            => 'info',
        default                                            => 'neutral',
    };
    return badge(ucwords(str_replace('_', ' ', $s)), $tone);
}

function severity_badge(?string $sev): string
{
    $tone = match ((string) $sev) {
        'critical' => 'bad',
        'high'     => 'bad',
        'warning', 'medium' => 'warn',
        'notice', 'low'     => 'info',
        default    => 'neutral',
    };
    return badge(ucfirst((string) $sev), $tone);
}

/** Paging: returns [limit, offset, page]. */
function paging(int $perPage = 50): array
{
    $page = max(1, get_int('page', 1));
    return [$perPage, ($page - 1) * $perPage, $page];
}

function pager(int $total, int $perPage, int $page): string
{
    $pages = (int) ceil($total / max(1, $perPage));
    if ($pages <= 1) {
        return '';
    }
    $out = '<nav class="pager">';
    $out .= '<span class="pager__info">' . number_format($total) . ' records, page ' . $page . ' of ' . $pages . '</span>';
    if ($page > 1) {
        $out .= '<a class="pager__link" href="' . e(url_with(['page' => $page - 1])) . '">&larr; Prev</a>';
    }
    if ($page < $pages) {
        $out .= '<a class="pager__link" href="' . e(url_with(['page' => $page + 1])) . '">Next &rarr;</a>';
    }
    return $out . '</nav>';
}

/** CSV download from rows of scalars. */
function send_csv(string $filename, array $header, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8
    fputcsv($out, $header);
    foreach ($rows as $r) {
        fputcsv($out, array_values($r));
    }
    fclose($out);
    exit;
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

function client_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/**
 * The trading day. Sales after midnight belong to the shift that is still open,
 * so a 01:30 sale on a 22:00-02:00 shift reports under the previous date. Falls
 * back to the calendar date when no session is open.
 */
function business_date(?array $session = null): string
{
    if ($session && !empty($session['opened_at'])) {
        return date('Y-m-d', strtotime((string) $session['opened_at']));
    }
    return date('Y-m-d');
}

/** EAN-13 check digit, for barcodes the app generates for loose/weighed goods. */
function ean13_check_digit(string $twelve): string
{
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int) $twelve[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return (string) ((10 - ($sum % 10)) % 10);
}

function ean13_valid(string $code): bool
{
    if (!preg_match('/^\d{13}$/', $code)) {
        return false;
    }
    return ean13_check_digit(substr($code, 0, 12)) === $code[12];
}
