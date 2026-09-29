<?php
// Direct messages.
//   GET                   your conversations (newest first)
//   POST user=<id>        the conversation with that person (made if new): {topic}
//   POST block=<id>, on=1|0   block or unblock someone (they can't send you direct messages)
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = api_user();
    $list = dm_list((int)$user['id']);
    json_out(['dms' => $list, 'users' => user_map(array_fill_keys(array_column($list, 'partner'), true))]);
}
$user = api_post();
api_run(function () use ($user) {
    if (isset($_POST['block'])) {
        set_block($user, (int)$_POST['block'], !empty($_POST['on']));
        return ['ok' => true];
    }
    return ['topic' => dm_with($user, (int)($_POST['user'] ?? 0))];
});
