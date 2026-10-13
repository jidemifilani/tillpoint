<?php
/**
 * CSRF tokens. One per session, checked on every state-changing POST.
 */
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = (string) ($_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return $sent !== '' && hash_equals(csrf_token(), $sent);
}

/**
 * Stop the request unless the token matches. Every POST handler calls this
 * first; there is no "skip for this one form" path, because the forms that
 * matter most here move money and stock.
 */
function csrf_require(): void
{
    if (!csrf_valid()) {
        http_response_code(419);
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Session expired. Reload the page and try again.']);
            exit;
        }
        flash_err('Your session expired before that form was submitted. Nothing was saved - please try again.');
        redirect($_SERVER['HTTP_REFERER'] ?? '/');
    }
}
