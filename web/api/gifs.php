<?php
// GET q (empty = trending), page: GIF search results.
// POST topic, url, reply_to?: send that GIF as a message.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';
require APP_DIR . '/lib/gifs.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = api_user();
    api_run(function () use ($user) {
        [$items, $more] = gif_search((int)$user['id'], (string)($_GET['q'] ?? ''), (int)($_GET['page'] ?? 1));
        return ['items' => $items, 'more' => $more];
    });
}
$user = api_post();
api_run(function () use ($user) {
    $att = store_gif($user, (string)($_POST['url'] ?? ''));
    if (!empty($_POST['store_only'])) {   // for a DM: the app sends it inside a sealed message
        $a = q('SELECT id, mime, width, height FROM attachments WHERE id = ?', [$att])->fetch();
        return ['id' => (int)$a['id'], 'mime' => $a['mime'], 'w' => (int)$a['width'], 'h' => (int)$a['height']];
    }
    $id = send_message($user, (int)($_POST['topic'] ?? 0), '', [], (int)($_POST['reply_to'] ?? 0), [$att], null);
    return message_json($id, (int)$user['id']);
});
