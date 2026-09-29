<?php
// POST topic, message: marks the topic read up to that message; reactions?: ids of your messages now seen.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/chat.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only.'], 405);
}
check_csrf();
$user = api_user();
$t = q('SELECT * FROM topics WHERE id = ?', [(int)($_POST['topic'] ?? 0)])->fetch();
if (!$t || !topic_visible((int)$user['id'], $t)) {
    json_out(['error' => 'That chat isn’t available.'], 404);
}
mark_read((int)$user['id'], (int)$t['id'], (int)($_POST['message'] ?? 0));
if ($t['kind'] === 'dm') {   // lets the other person's ✓ turn into ✓✓ without waiting
    q("INSERT INTO changes (topic_id, message_id, kind) VALUES (?, NULL, 'read')", [$t['id']]);
}
if (!empty($_POST['reactions'])) {   // your messages you've now seen: their reactions aren't new any more
    mark_reactions_seen((int)$user['id'], explode(',', (string)$_POST['reactions']));
}
json_out(['ok' => true]);
