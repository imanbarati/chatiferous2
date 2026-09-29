<?php
// Loads history.json (from import/prepare_history.py) into the database.
// Run after import_members.php:  php cli/import_history.php data/history.json
// Safe to re-run: topics and messages already imported (by telegram_id) are skipped,
// so a later export can top up the last few days before switching over.
require __DIR__ . '/../lib/bootstrap.php';

$file = $argv[1] ?? '';
if (!is_file($file)) {
    exit("Usage: php cli/import_history.php history.json\n");
}
$h = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

$users = q('SELECT telegram_id, id FROM users WHERE telegram_id IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
$uid = function (?int $tg) use ($users): ?int {
    return $tg !== null && isset($users[$tg]) ? (int)$users[$tg] : null;
};
$system = (int)q("SELECT id FROM users WHERE role = 'system' ORDER BY id LIMIT 1")->fetchColumn();

db()->beginTransaction();

// ---- Topics ----
$topic_ids = q('SELECT telegram_id, id FROM topics WHERE telegram_id IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($h['topics'] as $t) {
    if (isset($topic_ids[$t['tg_id']])) {
        continue;
    }
    q('INSERT INTO topics (title, icon_color, is_general, created_by, telegram_id, created_at) VALUES (?, ?, ?, ?, ?, ?)', [
        $t['title'], $t['is_general'] ? 0 : $t['tg_id'] % 6, $t['is_general'] ? 1 : 0,
        $uid($t['creator']), $t['tg_id'], $t['created_at'],
    ]);
    $topic_ids[$t['tg_id']] = (int)db()->lastInsertId();
}

// ---- Messages (first pass) ----
$msg_ids = q('SELECT telegram_id, id FROM messages WHERE telegram_id IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
$new = [];
$ins_msg = db()->prepare('INSERT INTO messages (topic_id, user_id, kind, text, entities, forwarded_from, extra_reactions,
    created_at, edited_at, telegram_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($h['messages'] as $m) {
    if (isset($msg_ids[$m['tg_id']])) {
        continue;
    }
    $author = $uid($m['from'] ?? null) ?? $system;
    $ents = [];
    foreach ($m['entities'] as $e) {
        if (isset($e['tg_user_id'])) {
            $e['user_id'] = $uid((int)$e['tg_user_id']);
            unset($e['tg_user_id']);
            if ($e['user_id'] === null) {
                $e = ['type' => 'bold', 'offset' => $e['offset'], 'length' => $e['length']];
            }
        }
        $ents[] = $e;
    }
    // One reaction per person, as in Telegram. Reactors the export doesn't name
    // (or names twice) are kept as anonymous counts.
    $extra = [];
    $reactors = [];
    foreach ($m['reactions'] ?? [] as $r) {
        $extra[$r['emoji']] = ($extra[$r['emoji']] ?? 0) + $r['extra'];
        foreach ($r['users'] as $i => $tg) {
            $u = $uid($tg);
            if ($u === null || isset($reactors[$u])) {
                $extra[$r['emoji']]++;
            } else {
                $reactors[$u] = [$r['emoji'], $r['dates'][$i] ?? $m['created_at']];
            }
        }
    }
    $extra = array_filter($extra);
    $ins_msg->execute([
        $topic_ids[$m['topic']], $author, $m['kind'], $m['text'], $ents ? json_encode($ents, JSON_UNESCAPED_UNICODE) : null,
        $m['forwarded_from'] ?? null, $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
        $m['created_at'], $m['edited_at'] ?? null, $m['tg_id'],
    ]);
    $msg_ids[$m['tg_id']] = (int)db()->lastInsertId();
    foreach ($reactors as $u => [$emoji, $at]) {
        q('INSERT INTO reactions (message_id, user_id, emoji, created_at) VALUES (?, ?, ?, ?)',
            [$msg_ids[$m['tg_id']], $u, $emoji, $at]);
    }
    $new[] = $m;
}

// ---- Second pass: things that point at other messages ----
foreach ($new as $m) {
    $id = $msg_ids[$m['tg_id']];

    if (isset($m['reply_to'], $msg_ids[$m['reply_to']])) {
        q('UPDATE messages SET reply_to_id = ? WHERE id = ?', [$msg_ids[$m['reply_to']], $id]);
    }
    if ($m['kind'] === 'service') {
        $s = $m['service'];
        if (isset($s['tg_message_id'])) {
            $s['message_id'] = $msg_ids[$s['tg_message_id']] ?? null;
            unset($s['tg_message_id']);
        }
        q('UPDATE messages SET service = ? WHERE id = ?', [json_encode($s, JSON_UNESCAPED_UNICODE), $id]);
    }
    if (isset($m['poll'])) {
        q('INSERT INTO polls (message_id, question, is_anonymous, multiple, is_closed, imported) VALUES (?, ?, 0, 0, 1, 1)',
            [$id, mb_substr($m['poll']['question'], 0, 300)]);
        foreach ($m['poll']['options'] as $i => $o) {
            q('INSERT INTO poll_options (message_id, position, text, imported_votes) VALUES (?, ?, ?, ?)',
                [$id, $i, mb_substr($o['text'], 0, 100), $o['votes']]);
        }
    }
    if (isset($m['attachment'])) {
        $a = $m['attachment'];
        q('INSERT INTO attachments (message_id, kind, path, name, mime, size, width, height, duration) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, $a['kind'], $a['src'] ? 'import/' . preg_replace('#^chats/chat_\d+/#', '', $a['src']) : null,
            mb_substr($a['name'] ?? '', 0, 255), $a['mime'] ?? '', (int)($a['size'] ?? 0),
            $a['width'] ?? null, $a['height'] ?? null, $a['duration'] ?? null,
        ]);
    }
    // Mentions and replies to other people feed the @ badge.
    $mentioned = [];
    foreach (json_decode(q('SELECT entities FROM messages WHERE id = ?', [$id])->fetchColumn() ?: '[]', true) as $e) {
        if (!empty($e['user_id'])) {
            $mentioned[$e['user_id']] = true;
        }
    }
    if (isset($m['reply_to'], $msg_ids[$m['reply_to']])) {
        $mentioned[(int)q('SELECT user_id FROM messages WHERE id = ?', [$msg_ids[$m['reply_to']]])->fetchColumn()] = true;
    }
    $author = (int)q('SELECT user_id FROM messages WHERE id = ?', [$id])->fetchColumn();
    foreach (array_keys($mentioned) as $u) {
        if ($u !== $author) {
            q('INSERT IGNORE INTO mentions (message_id, user_id) VALUES (?, ?)', [$id, $u]);
        }
    }
}

// ---- Pins ----
foreach ($h['pins'] as $p) {
    if (isset($msg_ids[$p['tg_message_id']])) {
        $mid = $msg_ids[$p['tg_message_id']];
        q('INSERT IGNORE INTO pins (message_id, topic_id, pinned_by, pinned_at)
           SELECT id, topic_id, ?, ? FROM messages WHERE id = ?', [$uid($p['by']), $p['at'], $mid]);
    }
}

// ---- Topic activity and read state ----
q('UPDATE topics t SET last_message_id = (SELECT MAX(id) FROM messages m WHERE m.topic_id = t.id AND m.deleted_at IS NULL)');
// Everyone starts with the imported history marked as read.
q("INSERT INTO read_state (user_id, topic_id, last_read_id)
   SELECT u.id, t.id, COALESCE(t.last_message_id, 0) FROM users u CROSS JOIN topics t WHERE u.role <> 'system'
   ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))");

db()->commit();
echo 'Imported ' . count($new) . ' new messages; ' . count($h['messages']) . " in file.\n";
