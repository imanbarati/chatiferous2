<?php

const SESSION_LIFETIME = 60 * 60 * 24 * 90; // 90 days

function start_session(): void
{
    // Shared hosting's default session cleanup runs after ~24 minutes; a private
    // folder with our own lifetime keeps people logged in for 90 days.
    $dir = config('session_dir');
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    ini_set('session.use_strict_mode', '1');
    session_name((string)config('session_name'));
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => config('base_url'),
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $user = q("SELECT * FROM users WHERE id = ? AND status = 'active'", [$_SESSION['user_id']])->fetch() ?: null;
            // Deactivated since logging in, or the password changed since (other sessions end).
            if (!$user || (int)($_SESSION['epoch'] ?? 0) !== (int)$user['session_epoch']) {
                $user = null;
                $_SESSION = [];
            }
        }
    }
    return $user;
}

function is_admin(?array $user): bool
{
    return $user && in_array($user['role'], ['owner', 'admin'], true);
}

// Pages call this first. New members must finish setting up (username and
// password) before they can see anything else.
function require_login(bool $allow_unfinished = false): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    if (!$allow_unfinished && $user['password_hash'] === null) {
        redirect('account.php');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if (!is_admin($user)) {
        http_response_code(403);
        exit('Admins only.');
    }
    return $user;
}

function log_in(array $user, string $kind): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['epoch'] = (int)q('SELECT session_epoch FROM users WHERE id = ?', [$user['id']])->fetchColumn();
    q('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$user['id']]);
    auth_event($kind, (int)$user['id'], $user['telegram_id'] ? (int)$user['telegram_id'] : null);
}

// Signs the account out everywhere else (after a password change); this session carries on.
function end_other_sessions(int $user_id): void
{
    q('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?', [$user_id]);
    if ((int)($_SESSION['user_id'] ?? 0) === $user_id) {
        $_SESSION['epoch'] = (int)q('SELECT session_epoch FROM users WHERE id = ?', [$user_id])->fetchColumn();
    }
}

function log_out(): void
{
    $_SESSION = [];
    session_destroy();
}

function auth_event(string $kind, ?int $user_id, ?int $telegram_id = null, string $detail = ''): void
{
    q('INSERT INTO auth_events (user_id, kind, telegram_id, ip, detail) VALUES (?, ?, ?, ?, ?)',
        [$user_id, $kind, $telegram_id, client_ip(), mb_substr($detail, 0, 255)]);
}

// Password logins: a short delay on every failure, and a pause after 10
// failures from one address in 15 minutes.
function too_many_failures(?int $user_id = null): bool
{
    // 10 failures from one address, or 20 against one account from anywhere, in 15 minutes.
    if ((int)q("SELECT COUNT(*) FROM auth_events WHERE kind = 'login_failed' AND ip = ?
                AND created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE", [client_ip()])->fetchColumn() >= 10) {
        return true;
    }
    return $user_id !== null && (int)q("SELECT COUNT(*) FROM auth_events WHERE kind = 'login_failed' AND user_id = ?
        AND created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE", [$user_id])->fetchColumn() >= 20;
}

// New members start with existing history marked as read.
function mark_all_read(int $user_id): void
{
    q('INSERT INTO read_state (user_id, topic_id, last_read_id)
       SELECT ?, id, COALESCE(last_message_id, 0) FROM topics
       ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))', [$user_id]);
}

function csrf_token(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

// Every POST handler calls this before changing anything.
function check_csrf(): void
{
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('This form expired. Go back, reload the page and try again.');
    }
}

function valid_username(string $u): bool
{
    return (bool)preg_match('/^[A-Za-z][A-Za-z0-9_]{2,31}$/', $u);
}
