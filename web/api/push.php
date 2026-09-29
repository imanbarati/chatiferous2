<?php
// POST action=subscribe (subscription JSON, device) | unsubscribe (endpoint) | test | level (all|mentions|off)
// GET: this member's notification settings and the server's public key.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';
require APP_DIR . '/lib/push.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = api_user();
    json_out([
        'public_key' => config('vapid_public'),
        'level'      => $user['notify_level'],
        'devices'    => (int)q('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$user['id']])->fetchColumn(),
    ]);
}
$user = api_post();
api_run(function () use ($user) {
    $me = (int)$user['id'];
    switch ($_POST['action'] ?? '') {
        case 'subscribe':
            save_subscription($me, json_decode((string)($_POST['subscription'] ?? ''), true) ?: [], (string)($_POST['device'] ?? ''));
            return ['ok' => true];
        case 'unsubscribe':
            remove_subscription($me, (string)($_POST['endpoint'] ?? ''));
            return ['ok' => true];
        case 'test':
            [$sent, $failed] = push_test($me);
            return ['sent' => $sent, 'failed' => $failed];
        case 'level':
            $level = in_array($_POST['level'] ?? '', ['all', 'mentions', 'off'], true) ? $_POST['level'] : 'all';
            q('UPDATE users SET notify_level = ? WHERE id = ?', [$level, $me]);
            return ['level' => $level];
    }
    fail('Unknown action.');
});
