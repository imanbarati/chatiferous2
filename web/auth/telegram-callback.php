<?php
// Telegram sends people here after "Log in with Telegram". We check Telegram's
// signature, check they're still in the group, then sign them in: claiming
// their imported account, or creating one if they weren't in the export.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/avatars.php';

$data = telegram_verify_login($_GET);
if (!$data) {
    auth_event('denied', null, isset($_GET['id']) ? (int)$_GET['id'] : null, 'bad or stale signature');
    redirect('login.php?e=bad');
}

// Each signed link works once (so one left in browser history can't be reused).
if (q('INSERT IGNORE INTO telegram_logins_used (hash) VALUES (?)', [hash('sha256', (string)$_GET['hash'])])->rowCount() !== 1) {
    auth_event('denied', null, (int)$data['id'], 'sign-in link already used');
    redirect('login.php?e=bad');
}

$tg_id = (int)$data['id'];
$user = q('SELECT * FROM users WHERE telegram_id = ?', [$tg_id])->fetch();

if ($user && in_array($user['status'], ['deactivated', 'deleted'], true)) {
    auth_event('denied', (int)$user['id'], $tg_id, 'account ' . $user['status']);
    redirect('login.php?e=deactivated');
}

$member = telegram_is_group_member($tg_id);
if ($member === null) {
    redirect('login.php?e=unavailable');
}
if (!$member) {
    auth_event('denied', $user ? (int)$user['id'] : null, $tg_id,
        'not in the Telegram group: ' . trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')));
    redirect('login.php?e=notmember');
}

// Offered as a suggested username on the setup page.
$_SESSION['tg_username'] = $data['username'] ?? '';

if ($user) {
    if ($user['status'] === 'unclaimed') {
        q("UPDATE users SET status = 'active', claimed_at = UTC_TIMESTAMP() WHERE id = ?", [$user['id']]);
        auth_event('claim', (int)$user['id'], $tg_id);
        // Claimed with Telegram: any claim link made for them is no longer needed.
        q('UPDATE invite_codes SET revoked_at = UTC_TIMESTAMP() WHERE user_id = ? AND revoked_at IS NULL AND uses < max_uses', [$user['id']]);
    }
    log_in($user, 'login_telegram');
} else {
    // In the group but not in the export (e.g. never posted).
    $name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) ?: 'Member';
    q("INSERT INTO users (telegram_id, display_name, status, color_index, claimed_at)
       VALUES (?, ?, 'active', ?, UTC_TIMESTAMP())", [$tg_id, mb_substr($name, 0, 128), $tg_id % 7]);
    $user = q('SELECT * FROM users WHERE id = ?', [db()->lastInsertId()])->fetch();
    auth_event('join', (int)$user['id'], $tg_id);
    mark_all_read((int)$user['id']);
    log_in($user, 'login_telegram');
}

// Their Telegram profile photo, unless they already have one (or chose their own).
if (!empty($data['photo_url']) && !q('SELECT avatar_path FROM users WHERE id = ?', [$user['id']])->fetchColumn()) {
    avatar_from_login_url((int)$user['id'], $data['photo_url']);
}

// Came from "Forgot your password?": Telegram has just confirmed who they are, so My account lets
// them choose a new password without the old one, for the next 10 minutes.
if (time() - (int)($_SESSION['pw_reset_intent'] ?? 0) < 15 * 60) {
    unset($_SESSION['pw_reset_intent']);
    $_SESSION['pw_reset_ok'] = time();
    auth_event('password_reset_start', (int)$user['id'], $tg_id);
    redirect('account.php');
}

redirect($user['password_hash'] === null ? 'account.php' : '');
