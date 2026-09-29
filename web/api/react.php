<?php
// POST id, emoji ('' removes my reaction)
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';

$user = api_post();
api_run(function () use ($user) {
    $id = (int)($_POST['id'] ?? 0);
    react($user, $id, (string)($_POST['emoji'] ?? ''));
    return message_json($id, (int)$user['id']);
});
