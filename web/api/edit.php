<?php
// POST id, text, mentions? (JSON)
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';

$user = api_post();
api_run(function () use ($user) {
    $id = (int)($_POST['id'] ?? 0);
    edit_message($user, $id, (string)($_POST['text'] ?? ''), post_mentions());
    return message_json($id, (int)$user['id']);
});
