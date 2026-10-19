<?php
/**
 * Authentication, session hardening, rate limiting and authorization.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

/* ------------------------------------------------------------------ */
/*  Session bootstrap (hardened)                                      */
/* ------------------------------------------------------------------ */
function boot_session(): void
{
    static $booted = false;
    if ($booted || session_status() === PHP_SESSION_ACTIVE) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $booted = true;
        }
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    session_name(defined('SESSION_NAME') ? SESSION_NAME : 'IMSSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
    $booted = true;

    // Bind the session to a fingerprint to limit hijacking.
    $fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (!isset($_SESSION['_fp'])) {
        $_SESSION['_fp'] = $fingerprint;
    } elseif (!hash_equals($_SESSION['_fp'], $fingerprint)) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['_fp'] = $fingerprint;
    }
}
boot_session();

/* ------------------------------------------------------------------ */
/*  Rate limiting                                                     */
/* ------------------------------------------------------------------ */
function login_is_rate_limited(string $username): bool
{
    $db = getDB();
    $cutoff = date('Y-m-d H:i:s', time() - (LOGIN_WINDOW_MINUTES * 60));
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE success = 0 AND attempted_at > ? AND (username = ? OR ip_address = ?)'
    );
    $stmt->execute([$cutoff, mb_strtolower($username), client_ip()]);
    return (int)$stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

function login_record_attempt(string $username, bool $success): void
{
    try {
        $db = getDB();
        $db->prepare('INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, ?)')
           ->execute([mb_strtolower($username), client_ip(), $success ? 1 : 0]);
        // Opportunistic cleanup of old rows.
        if (random_int(1, 20) === 1) {
            $db->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
               ->execute([date('Y-m-d H:i:s', time() - 86400)]);
        }
    } catch (Throwable $e) {
        app_log('login_record_attempt failed: ' . $e->getMessage());
    }
}

function login_clear_attempts(string $username): void
{
    try {
        getDB()->prepare('DELETE FROM login_attempts WHERE username = ? OR ip_address = ?')
              ->execute([mb_strtolower($username), client_ip()]);
    } catch (Throwable $e) {
        // ignore
    }
}

/* ------------------------------------------------------------------ */
/*  Authentication                                                    */
/* ------------------------------------------------------------------ */
/**
 * @return array{ok:bool, error?:string}
 */
function attempt_login(string $identity, string $password): array
{
    $identity = trim($identity);
    if ($identity === '' || $password === '') {
        return ['ok' => false, 'error' => 'Please enter your username/email and password.'];
    }
    if (login_is_rate_limited($identity)) {
        return ['ok' => false, 'error' => 'Too many failed attempts. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes and try again.'];
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->execute([$identity, $identity]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        login_record_attempt($identity, false);
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }
    if ($user['status'] !== 'active') {
        login_record_attempt($identity, false);
        return ['ok' => false, 'error' => 'This account is inactive. Please contact the owner.'];
    }

    // Rehash if the algorithm/cost changed.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
           ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    session_regenerate_id(true);
    unset($_SESSION['_csrf']);
    $_SESSION['user_id']  = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];
    $_SESSION['_fp']      = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    login_record_attempt($identity, true);
    login_clear_attempts($identity);

    audit_log((int)$user['id'], 'login', 'user', (int)$user['id'], ['username' => $user['username']]);
    return ['ok' => true];
}

function logout_user(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function current_user(): ?array
{
    static $user = null;
    static $loaded = false;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    try {
        $stmt = getDB()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
        $user = ($row && $row['status'] === 'active') ? $row : null;
    } catch (Throwable $e) {
        $user = null;
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function current_user_id(): ?int
{
    $u = current_user();
    return $u ? (int)$u['id'] : null;
}

function current_role(): string
{
    $u = current_user();
    return $u ? $u['role'] : 'guest';
}

/* ------------------------------------------------------------------ */
/*  Authorization                                                     */
/* ------------------------------------------------------------------ */
function role_permissions(): array
{
    $staff = [
        'view_customers', 'manage_customers',
        'view_agreements', 'manage_agreements',
        'view_payments', 'record_payments',
        'view_reports',
    ];
    $owner = array_merge($staff, [
        'void_payment',
        'cancel_agreement',
        'amend_agreement',
        'manage_settings',
        'manage_users',
        'delete_customer',
    ]);
    return [
        'owner' => $owner,
        'staff' => $staff,
    ];
}

function can(string $capability): bool
{
    $role = current_role();
    $map = role_permissions();
    return in_array($capability, $map[$role] ?? [], true);
}

function require_login(): void
{
    if (!is_logged_in()) {
        $_SESSION['_intended'] = current_url_path();
        flash_set('warning', 'Please log in to continue.');
        redirect('login.php');
    }
}

function require_permission(string $capability): void
{
    require_login();
    if (!can($capability)) {
        http_response_code(403);
        audit_log(current_user_id(), 'access_denied', null, null, ['capability' => $capability, 'url' => current_url_path()]);
        fatal_error('You do not have permission to perform this action.');
    }
}

function require_role(string ...$roles): void
{
    require_login();
    if (!in_array(current_role(), $roles, true)) {
        http_response_code(403);
        fatal_error('You do not have permission to access this page.');
    }
}
