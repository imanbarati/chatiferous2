<?php
// GET since=<change id>&topic=<open topic id or 0>: what changed since then.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/chat.php';

$user = api_user();
// Who's here and which topic they're reading: no notifications about what they're looking at.
q('UPDATE users SET last_active_at = UTC_TIMESTAMP(), active_topic_id = ? WHERE id = ?', [(int)($_GET['topic'] ?? 0) ?: null, $user['id']]);
json_out(sync_changes((int)$user['id'], (int)($_GET['topic'] ?? 0), (int)($_GET['since'] ?? 0)) + ['version' => app_version()]);
