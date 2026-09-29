<?php
// GET: members for the @ list.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/chat.php';

api_user();
$rows = q("SELECT id, display_name, username, color_index, avatar_path, avatar_version FROM users
           WHERE role <> 'system' AND status IN ('active', 'unclaimed') ORDER BY display_name")->fetchAll();
json_out(['members' => array_map(fn($u) => [
    'id' => (int)$u['id'], 'name' => $u['display_name'], 'username' => $u['username'], 'color' => (int)$u['color_index'],
    'photo' => $u['avatar_path'] ? url('avatar.php?u=' . (int)$u['id'] . '&v=' . (int)$u['avatar_version']) : null,
], $rows)]);
