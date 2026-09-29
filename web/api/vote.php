<?php
// POST id (poll message), options[] (none = retract), or close=1 to stop the poll.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';

$user = api_post();
api_run(function () use ($user) {
    $id = (int)($_POST['id'] ?? 0);
    if (!empty($_POST['close'])) {
        close_poll($user, $id);
    } else {
        vote($user, $id, (array)($_POST['options'] ?? []));
    }
    return message_json($id, (int)$user['id']);
});
