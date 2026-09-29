<?php
// POST action=create|edit|close|pin|delete, with id, title, icon, color, closed, pin as needed.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';
require APP_DIR . '/lib/reading.php';   // setting()
require APP_DIR . '/lib/topics.php';

$user = api_post();
api_run(function () use ($user) {
    $id = (int)($_POST['id'] ?? 0);
    $title = (string)($_POST['title'] ?? '');
    $icon = isset($_POST['icon']) ? (string)$_POST['icon'] : null;
    $color = (int)($_POST['color'] ?? 0);
    switch ($_POST['action'] ?? '') {
        case 'create': return ['id' => create_topic($user, $title, $icon, $color)];
        case 'edit':   edit_topic($user, $id, $title, $icon, $color); return ['ok' => true];
        case 'close':  close_topic($user, $id, !empty($_POST['closed'])); return ['ok' => true];
        case 'pin':    pin_topic($user, $id, !empty($_POST['pin'])); return ['ok' => true];
        case 'delete': delete_topic($user, $id); return ['ok' => true];
    }
    fail('Unknown action.');
});
