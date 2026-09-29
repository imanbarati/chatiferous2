<?php
// GET id: who reacted to a message, with each emoji (newest first). Reactions brought over
// from Telegram whose senders weren't recorded come back as a count ("extra").
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';

$user = api_user();
api_run(function () use ($user) {
    $id = (int)($_GET['id'] ?? 0);
    load_message_for($user, $id);
    $m = q('SELECT id, extra_reactions FROM messages WHERE id = ? AND deleted_at IS NULL', [$id])->fetch() ?: fail('That message was deleted.');
    $by = [];
    $users = [];
    foreach (q('SELECT emoji, user_id FROM reactions WHERE message_id = ? ORDER BY created_at DESC', [$id])->fetchAll() as $r) {
        $by[$r['emoji']][] = (int)$r['user_id'];
        $users[(int)$r['user_id']] = true;
    }
    $extra = json_decode((string)$m['extra_reactions'], true) ?: [];
    $out = [];
    foreach (array_unique(array_merge(array_keys($by), array_keys($extra))) as $e) {
        $out[] = ['emoji' => (string)$e, 'users' => $by[$e] ?? [], 'extra' => (int)($extra[$e] ?? 0)];
    }
    usort($out, fn($a, $b) => (count($b['users']) + $b['extra']) <=> (count($a['users']) + $a['extra']));
    return ['reactions' => $out, 'users' => user_map($users)];
});
