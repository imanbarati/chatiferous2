<?php
// POST id, pin=1|0 (admins); or topic, all=0 to unpin every message in a topic.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';

$user = api_post();
api_run(function () use ($user) {
    if (isset($_POST['all'])) {
        foreach (q('SELECT message_id FROM pins WHERE topic_id = ?', [(int)($_POST['topic'] ?? 0)])->fetchAll(PDO::FETCH_COLUMN) as $id) {
            set_pin($user, (int)$id, false);   // checks the admin rule each time
        }
        return ['ok' => true];
    }
    set_pin($user, (int)($_POST['id'] ?? 0), !empty($_POST['pin']));
    return ['ok' => true];
});
