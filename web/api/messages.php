<?php
// GET topic=<id> and one of: mode=unread (default) | bottom | around&id= | before&id= | after&id=
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/chat.php';

$user = api_user();
$mode = in_array($_GET['mode'] ?? '', ['unread', 'bottom', 'around', 'before', 'after', 'all'], true) ? $_GET['mode'] : 'unread';
$out = topic_messages((int)$user['id'], (int)($_GET['topic'] ?? 0), $mode, (int)($_GET['id'] ?? 0));
$topic = q('SELECT kind, is_closed, created_by FROM topics WHERE id = ?', [$out['topic']['id']])->fetch();
$out['can_post'] = $topic['kind'] === 'dm' ? !$out['topic']['i_blocked'] && !$out['topic']['blocked_me']
    : !$topic['is_closed'] || is_admin($user) || (int)$topic['created_by'] === (int)$user['id'];
json_out($out);
