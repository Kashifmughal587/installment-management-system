<?php
/**
 * CSRF protection helpers.
 * A single per-session token is generated and verified on every
 * state-changing request.
 */

function csrf_ensure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
}

function csrf_token(): string
{
    csrf_ensure_session();
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token = null): bool
{
    csrf_ensure_session();
    $token = $token ?? ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($token) && $token !== '' && hash_equals($_SESSION['_csrf'], $token);
}

/**
 * Verify the CSRF token on a POST request or abort.
 */
function csrf_require(): void
{
    if (!csrf_verify()) {
        http_response_code(419);
        fatal_error('Your session expired or the request was invalid. Please go back and try again.');
    }
}
