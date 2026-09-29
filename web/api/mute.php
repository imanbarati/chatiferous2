<?php
// POST topic, muted=1|0: mute or unmute notifications (and the unread badge color) for one topic.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';

$user = api_post();
api_run(function () use ($user) {
    $topic = (int)($_POST['topic'] ?? 0);
    load_topic_for($user, $topic);
    q('INSERT INTO read_state (user_id, topic_id, muted) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE muted = VALUES(muted)',
        [$user['id'], $topic, empty($_POST['muted']) ? 0 : 1]);
    return ['muted' => !empty($_POST['muted'])];
});
