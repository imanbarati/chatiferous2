<?php
// Reading topics and messages for the API.

require_once APP_DIR . '/lib/dm.php';

const PAGE_SIZE = 50;

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_user(): array
{
    $user = current_user();
    if (!$user || $user['password_hash'] === null) {
        json_out(['error' => 'Please sign in again.'], 401);
    }
    session_write_close(); // don't hold the session lock while serving
    return $user;
}

function iso(?string $utc): ?string
{
    return $utc === null ? null : str_replace(' ', 'T', $utc) . 'Z';
}

// Topic list, in Telegram's order: pinned topics first, then latest activity.
function topic_list(int $me): array
{
    $rows = q("SELECT t.id, t.title, t.icon_color, t.icon_emoji, t.icon_key, t.is_general, t.is_closed, t.pin_order, t.created_by,
          m.id AS last_id, m.kind AS last_kind, LEFT(m.text, 160) AS last_text, m.service AS last_service,
          m.created_at AS last_at, m.user_id AS last_user, u.display_name AS last_name,
          (SELECT p.question FROM polls p WHERE p.message_id = m.id) AS last_poll,
          (SELECT a.kind FROM attachments a WHERE a.message_id = m.id LIMIT 1) AS last_att,
          (SELECT a.name FROM attachments a WHERE a.message_id = m.id LIMIT 1) AS last_att_name,
          COALESCE(rs.muted, 0) AS muted,
          (SELECT COUNT(*) FROM messages x WHERE x.topic_id = t.id AND x.id > COALESCE(rs.last_read_id, 0)
             AND x.deleted_at IS NULL AND x.user_id <> ?) AS unread,
          (SELECT COUNT(*) FROM mentions mn JOIN messages x ON x.id = mn.message_id
             WHERE mn.user_id = ? AND x.topic_id = t.id AND x.id > COALESCE(rs.last_read_id, 0) AND x.deleted_at IS NULL) AS mentions,
          (SELECT COUNT(DISTINCT r.message_id) FROM reactions r JOIN messages x ON x.id = r.message_id
             WHERE r.author_seen = 0 AND x.user_id = ? AND x.topic_id = t.id AND x.deleted_at IS NULL) AS reactions
        FROM topics t
        LEFT JOIN messages m ON m.id = t.last_message_id
        LEFT JOIN users u ON u.id = m.user_id
        LEFT JOIN read_state rs ON rs.topic_id = t.id AND rs.user_id = ?
        WHERE t.kind = 'topic'
        ORDER BY t.pin_order IS NULL, t.pin_order, t.last_message_id DESC", [$me, $me, $me, $me])->fetchAll();

    return array_map(fn($r) => [
        'id'        => (int)$r['id'],
        'title'     => $r['title'],
        'color'     => (int)$r['icon_color'],
        'emoji'     => $r['icon_emoji'],
        'icon'      => $r['icon_key'],
        'general'   => (bool)$r['is_general'],
        'closed'    => (bool)$r['is_closed'],
        'pinned'    => $r['pin_order'] !== null,
        'created_by'=> (int)$r['created_by'],
        'muted'     => (bool)$r['muted'],
        'unread'    => (int)$r['unread'],
        'mentions'  => (int)$r['mentions'],
        'reactions' => (int)$r['reactions'],    // your messages with reactions you haven't seen
        'last'      => $r['last_id'] === null ? null : [
            'id'      => (int)$r['last_id'],
            'at'      => iso($r['last_at']),
            'name'    => $r['last_name'],
            'mine'    => (int)$r['last_user'] === $me,
            'preview' => message_preview($r['last_kind'], (string)$r['last_text'], $r['last_poll'], $r['last_att'], $r['last_att_name'], $r['last_service'], $r['last_name']),
        ],
    ], $rows);
}

// One-line summary of a message, as in the topic list and reply headers.
function message_preview(string $kind, string $text, ?string $poll, ?string $att, ?string $att_name, ?string $service, ?string $name): string
{
    if ($kind === 'service') {
        $s = json_decode((string)$service, true) ?: [];
        return match ($s['action'] ?? '') {
            'topic_created' => $name . ' created the topic',
            'topic_renamed' => $name . ' renamed the topic to “' . ($s['title'] ?? '') . '”',
            'topic_closed'   => $name . ' closed the topic',
            'topic_reopened' => $name . ' reopened the topic',
            'pinned'        => $name . ' pinned a message',
            default         => '',
        };
    }
    if ($kind === 'poll') {
        return '📊 ' . preg_replace('/\s+/u', ' ', (string)$poll);
    }
    $text = preg_replace('/\s+/u', ' ', trim($text));
    $label = match ($att) {
        'photo'     => '🖼 Photo',
        'video'     => '📹 Video',
        'voice'     => '🎤 Voice message',
        'animation' => 'GIF',
        'file'      => '📎 ' . ($att_name ?: 'File'),
        default     => '',
    };
    return trim($label . ($label && $text !== '' ? ', ' : '') . $text);
}

// Messages for one topic. $mode: 'unread' (open at first unread), 'around' ($id),
// 'before' ($id, older page), 'after' ($id, newer page), 'bottom'.
function topic_messages(int $me, int $topic_id, string $mode, int $id = 0): array
{
    $topic = q('SELECT * FROM topics WHERE id = ?', [$topic_id])->fetch();
    if (!$topic || !topic_visible($me, $topic)) {
        json_out(['error' => 'That chat isn’t available.'], 404);
    }
    $last_read = (int)(q('SELECT last_read_id FROM read_state WHERE user_id = ? AND topic_id = ?', [$me, $topic_id])->fetchColumn() ?: 0);
    $first_unread = (int)(q('SELECT MIN(id) FROM messages WHERE topic_id = ? AND id > ? AND deleted_at IS NULL AND user_id <> ?',
        [$topic_id, $last_read, $me])->fetchColumn() ?: 0);

    $base = 'SELECT * FROM messages WHERE topic_id = ? AND deleted_at IS NULL';
    $n = PAGE_SIZE;
    if ($mode === 'unread' && $first_unread) {
        [$mode, $id] = ['around', $first_unread];
    }
    if ($mode === 'all' && ($topic['kind'] ?? 'topic') === 'dm') {
        $rows = q("$base ORDER BY id LIMIT 5000", [$topic_id])->fetchAll();
    } elseif ($mode === 'around') {
        $older = q("$base AND id < ? ORDER BY id DESC LIMIT 20", [$topic_id, $id])->fetchAll();
        $newer = q("$base AND id >= ? ORDER BY id LIMIT $n", [$topic_id, $id])->fetchAll();
        $rows = array_merge(array_reverse($older), $newer);
    } elseif ($mode === 'before') {
        $rows = array_reverse(q("$base AND id < ? ORDER BY id DESC LIMIT $n", [$topic_id, $id])->fetchAll());
    } elseif ($mode === 'after') {
        $rows = q("$base AND id > ? ORDER BY id LIMIT $n", [$topic_id, $id])->fetchAll();
    } else {
        $rows = array_reverse(q("$base ORDER BY id DESC LIMIT $n", [$topic_id])->fetchAll());
    }

    $first = $rows ? (int)$rows[0]['id'] : 0;
    $last = $rows ? (int)end($rows)['id'] : 0;
    $has_older = $first && (bool)q("$base AND id < ? LIMIT 1", [$topic_id, $first])->fetch();
    $has_newer = $last && (bool)q("$base AND id > ? LIMIT 1", [$topic_id, $last])->fetch();

    $users = [];
    $messages = hydrate_messages($rows, $me, $users);
    $pins = q('SELECT p.message_id FROM pins p JOIN messages m ON m.id = p.message_id
               WHERE p.topic_id = ? AND m.deleted_at IS NULL ORDER BY p.pinned_at DESC', [$topic_id])->fetchAll(PDO::FETCH_COLUMN);
    $pin_previews = [];
    foreach (array_slice($pins, 0, 20) as $pid) {
        $pin_previews[] = reply_preview((int)$pid, $users);
    }

    return [
        'topic'        => [
            'id' => (int)$topic['id'], 'title' => $topic['title'], 'color' => (int)$topic['icon_color'],
            'emoji' => $topic['icon_emoji'], 'icon' => $topic['icon_key'], 'general' => (bool)$topic['is_general'], 'closed' => (bool)$topic['is_closed'],
        ] + dm_info($me, $topic, $users),
        'last_read_id' => $last_read,
        'first_unread' => $first_unread,
        'messages'     => $messages,
        'has_older'    => $has_older,
        'has_newer'    => $has_newer,
        'pins'         => $pin_previews,
        'mentions'     => unread_mentions($me, $topic_id, $last_read),
        'reactions'    => unseen_reactions($me, $topic_id),
        'users'        => user_map($users),
        'cursor'       => change_cursor(),
    ];
}

// Adds reactions, polls, attachments and reply headers to message rows.
function hydrate_messages(array $rows, int $me, array &$users): array
{
    if (!$rows) {
        return [];
    }
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in = implode(',', $ids);

    $reactions = [];
    foreach (q("SELECT message_id, user_id, emoji FROM reactions WHERE message_id IN ($in) ORDER BY created_at")->fetchAll() as $r) {
        $reactions[$r['message_id']][$r['emoji']][] = (int)$r['user_id'];
    }
    // A forwarded poll is the original poll (as in Telegram): its votes are shared.
    $poll_src = [];
    foreach ($rows as $r) {
        if ($r['kind'] === 'poll') {
            $poll_src[(int)$r['id']] = (int)($r['forwarded_msg_id'] ?? 0) ?: (int)$r['id'];
        }
    }
    $polls = [];
    $options = [];
    $voters = [];
    if ($poll_src) {
        $pin = implode(',', array_unique($poll_src));
        foreach (q("SELECT * FROM polls WHERE message_id IN ($pin)")->fetchAll() as $p) {
            $polls[$p['message_id']] = $p;
        }
        foreach (q("SELECT o.*, (SELECT COUNT(*) FROM poll_votes v WHERE v.option_id = o.id) AS votes,
                      (SELECT COUNT(*) FROM poll_votes v WHERE v.option_id = o.id AND v.user_id = ?) AS mine
                    FROM poll_options o WHERE o.message_id IN ($pin) ORDER BY o.position", [$me])->fetchAll() as $o) {
            $options[$o['message_id']][] = $o;
        }
        // Who voted, for public polls only (most recent first).
        foreach (q("SELECT v.option_id, v.user_id FROM poll_votes v JOIN polls p ON p.message_id = v.message_id
                    WHERE v.message_id IN ($pin) AND p.is_anonymous = 0 ORDER BY v.created_at DESC, v.user_id")->fetchAll() as $v) {
            $voters[$v['option_id']][] = (int)$v['user_id'];
            $users[(int)$v['user_id']] = true;
        }
    }
    $atts = [];
    foreach (q("SELECT * FROM attachments WHERE message_id IN ($in) ORDER BY id")->fetchAll() as $a) {
        $atts[$a['message_id']][] = [
            'id' => (int)$a['id'], 'kind' => $a['kind'], 'name' => $a['name'], 'mime' => $a['mime'],
            'size' => (int)$a['size'], 'w' => $a['width'] ? (int)$a['width'] : null, 'h' => $a['height'] ? (int)$a['height'] : null,
            'available' => $a['path'] !== null,
        ];
    }
    $pinned = array_flip(q("SELECT message_id FROM pins WHERE message_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
    $rx_new = array_flip(q("SELECT DISTINCT message_id FROM reactions WHERE author_seen = 0 AND message_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
    // Forwarded copies: which topic the original is in, if it still exists.
    $fwd_ids = array_filter(array_map(fn($r) => (int)($r['forwarded_msg_id'] ?? 0), $rows));
    $fwd_topic = [];
    foreach ($fwd_ids ? q('SELECT m.id, m.topic_id, t.kind, t.dm_a, t.dm_b FROM messages m JOIN topics t ON t.id = m.topic_id
                           WHERE m.deleted_at IS NULL AND m.id IN (' . implode(',', $fwd_ids) . ')')->fetchAll() : [] as $f) {
        if (topic_visible($me, $f)) {
            $fwd_topic[(int)$f['id']] = (int)$f['topic_id'];
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $users[(int)$r['user_id']] = true;
        [$plain, $plain_ents] = msg_open($r);
        $m = [
            'id'      => $id,
            'user_id' => (int)$r['user_id'],
            'kind'    => $r['kind'],
            'text'    => $plain,
            'ents'    => $plain_ents ? json_decode($plain_ents, true) : [],
            'at'      => iso($r['created_at']),
            'edited'  => $r['edited_at'] !== null,
            'fwd'     => $r['forwarded_from'],
            'fwd_id'  => isset($fwd_topic[$r['forwarded_msg_id'] ?? 0]) ? (int)$r['forwarded_msg_id'] : null,
            'fwd_topic' => isset($fwd_topic[$r['forwarded_msg_id'] ?? 0]) ? (int)$fwd_topic[$r['forwarded_msg_id']] : null,
            'pinned'  => isset($pinned[$id]),
            'rx_new'  => (int)$r['user_id'] === $me && isset($rx_new[$id]),
        ];
        if ($r['kind'] === 'service') {
            $m['service'] = json_decode((string)$r['service'], true) ?: [];
            if (!empty($m['service']['message_id'])) {
                $m['service']['preview'] = reply_preview((int)$m['service']['message_id'], $users)['text'] ?? '';
            }
        }
        if ($r['reply_to_id']) {
            $m['reply'] = reply_preview((int)$r['reply_to_id'], $users);
            if (($r['quote_text'] ?? null) !== null && empty($m['reply']['deleted'])) {
                $m['reply']['quote'] = $r['quote_text'];   // (in a DM the quote is inside the sealed message)
            }
        }

        // Reactions: per emoji, the count, whether I reacted, and (when 3 or
        // fewer in all) who, for the small avatars.
        $extra = $r['extra_reactions'] ? json_decode($r['extra_reactions'], true) : [];
        $by_emoji = $reactions[$id] ?? [];
        $total = array_sum(array_map('count', $by_emoji)) + array_sum($extra);
        $rx = [];
        foreach (array_unique(array_merge(array_keys($by_emoji), array_keys($extra))) as $emoji) {
            $who = $by_emoji[$emoji] ?? [];
            $item = ['emoji' => (string)$emoji, 'count' => count($who) + (int)($extra[$emoji] ?? 0), 'mine' => in_array($me, $who, true)];
            if ($total <= 3 && empty($extra[$emoji])) {
                $item['users'] = $who;
                foreach ($who as $u) {
                    $users[$u] = true;
                }
            }
            $rx[] = $item;
        }
        usort($rx, fn($a, $b) => $b['count'] <=> $a['count']);
        if ($rx) {
            $m['reactions'] = $rx;
        }

        $src = $poll_src[$id] ?? $id;
        if (isset($polls[$src])) {
            $p = $polls[$src];
            $opts = array_map(fn($o) => [
                'id' => (int)$o['id'], 'text' => $o['text'],
                'votes' => (int)$o['votes'] + (int)$o['imported_votes'], 'mine' => (bool)$o['mine'],
                'imported' => (int)$o['imported_votes'],                  // earlier votes whose voters aren't known
                'voters' => $p['is_anonymous'] ? [] : ($voters[$o['id']] ?? []),
            ], $options[$src] ?? []);
            $m['poll'] = [
                'question'  => $p['question'],
                'anonymous' => (bool)$p['is_anonymous'],
                'multiple'  => (bool)$p['multiple'],
                'closed'    => (bool)$p['is_closed'],
                'imported'  => (bool)$p['imported'],
                'total'     => array_sum(array_column($opts, 'votes')),
                'voted'     => (bool)array_filter(array_column($opts, 'mine')),
                'options'   => $opts,
            ];
        }
        if (isset($atts[$id])) {
            $m['att'] = $atts[$id];
        }
        $out[] = $m;
    }
    return $out;
}

// For a DM: who's on the other side, how far they've read (for ✓✓), and blocks.
function dm_info(int $me, array $topic, array &$users): array
{
    if (($topic['kind'] ?? 'topic') !== 'dm') {
        return ['kind' => 'topic'];
    }
    $other = dm_partner($topic, $me);
    $users[$other] = true;
    return [
        'kind'         => 'dm',
        'partner'      => $other,
        'partner_read' => (int)(q('SELECT last_read_id FROM read_state WHERE user_id = ? AND topic_id = ?', [$other, $topic['id']])->fetchColumn() ?: 0),
        'i_blocked'    => is_blocked($me, $other),
        'blocked_me'   => is_blocked($other, $me),
        'keys'         => dm_key_state($me, $topic),
    ];
}

function reply_preview(int $id, array &$users): array
{
    $r = q('SELECT m.id, m.user_id, m.kind, m.text, m.entities, m.service, m.deleted_at, m.created_at, u.display_name,
              (SELECT p.question FROM polls p WHERE p.message_id = m.id) AS poll,
              (SELECT a.kind FROM attachments a WHERE a.message_id = m.id LIMIT 1) AS att,
              (SELECT a.name FROM attachments a WHERE a.message_id = m.id LIMIT 1) AS att_name
            FROM messages m JOIN users u ON u.id = m.user_id WHERE m.id = ?', [$id])->fetch();
    if (!$r || $r['deleted_at'] !== null) {
        return ['id' => $id, 'deleted' => true];
    }
    $users[(int)$r['user_id']] = true;
    return [
        'id'      => $id,
        'user_id' => (int)$r['user_id'],
        'text'    => is_sealed((string)$r['text']) ? '' : message_preview($r['kind'], mb_substr((string)$r['text'], 0, 160), $r['poll'], $r['att'], $r['att_name'], $r['service'], $r['display_name']),
        'sealed'  => is_sealed((string)$r['text']) ? $r['text'] : null,   // a DM message: the app opens it
        'at'      => iso($r['created_at']),
    ];
}

function user_map(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_map('intval', array_keys($ids)));
    $map = [];
    foreach (q("SELECT id, display_name, username, color_index, role, status, avatar_path, avatar_version FROM users WHERE id IN ($in)")->fetchAll() as $u) {
        $map[$u['id']] = [
            'name'     => $u['display_name'],
            'color'    => (int)$u['color_index'],
            'photo'    => $u['avatar_path'] ? url('avatar.php?u=' . (int)$u['id'] . '&v=' . (int)$u['avatar_version']) : null,
            'role'     => in_array($u['role'], ['owner', 'admin'], true) ? $u['role'] : null,
            'username' => $u['username'],
            'system'   => $u['role'] === 'system',        // Daily Reading and the like: no direct messages
            'pending'  => $u['status'] === 'unclaimed',  // hasn't come over to the new site yet
        ];
    }
    return $map;
}

function mark_read(int $me, int $topic_id, int $message_id): void
{
    q('INSERT INTO read_state (user_id, topic_id, last_read_id) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))', [$me, $topic_id, $message_id]);
}

function change_cursor(): int
{
    return (int)(q('SELECT MAX(id) FROM changes')->fetchColumn() ?: 0);
}

// What changed since $since: changed messages in the open topic, deletions,
// pins, and (if anything changed anywhere) a fresh topic list for the badges.
function sync_changes(int $me, int $topic_id, int $since): array
{
    $cursor = change_cursor();
    if ($since >= $cursor) {
        return ['cursor' => $cursor];
    }
    if ($since === 0 || $cursor - $since > 2000) {
        return ['cursor' => $cursor, 'reload' => true]; // too far behind: start over
    }
    $out = ['cursor' => $cursor, 'topics' => topic_list($me), 'dms' => dm_list($me)];
    $open = $topic_id ? q('SELECT * FROM topics WHERE id = ?', [$topic_id])->fetch() : null;
    if ($open && !topic_visible($me, $open)) {
        $topic_id = 0;
    }
    if ($open && $topic_id && $open['kind'] === 'dm') {
        $out['partner_read'] = (int)(q('SELECT last_read_id FROM read_state WHERE user_id = ? AND topic_id = ?',
            [dm_partner($open, $me), $topic_id])->fetchColumn() ?: 0);
    }
    if ($topic_id) {
        $rows = q('SELECT message_id, kind FROM changes WHERE id > ? AND topic_id = ? ORDER BY id', [$since, $topic_id])->fetchAll();
        $ids = [];
        $deleted = [];
        foreach ($rows as $r) {
            if ($r['kind'] === 'keys') {
                $out['keys_changed'] = true;   // a DM's key was shared or restored: the app reopens it
            }
            if ($r['message_id'] === null) {
                continue;
            }
            if ($r['kind'] === 'delete') {
                $deleted[(int)$r['message_id']] = true;
            } else {
                $ids[(int)$r['message_id']] = true;
            }
            if ($r['kind'] === 'pin') {
                $out['pins_changed'] = true;
            }
        }
        $ids = array_diff_key($ids, $deleted);
        $users = [];
        $msgs = [];
        if ($ids) {
            $in = implode(',', array_keys($ids));
            $msgs = hydrate_messages(q("SELECT * FROM messages WHERE id IN ($in) AND deleted_at IS NULL ORDER BY id")->fetchAll(), $me, $users);
        }
        $out['messages'] = $msgs;
        $out['deleted'] = array_keys($deleted);
        if (!empty($out['pins_changed'])) {
            $out['pins'] = array_map(fn($pid) => reply_preview((int)$pid, $users),
                q('SELECT p.message_id FROM pins p JOIN messages m ON m.id = p.message_id
                   WHERE p.topic_id = ? AND m.deleted_at IS NULL ORDER BY p.pinned_at DESC LIMIT 20', [$topic_id])->fetchAll(PDO::FETCH_COLUMN));
        }
        $out['users'] = user_map($users);
    }
    return $out;
}

// Unread messages in a topic that mention or reply to me (for the @ button).
// Your messages in this topic with reactions you haven't seen yet (oldest first).
function unseen_reactions(int $me, int $topic_id): array
{
    return array_map('intval', q('SELECT DISTINCT m.id FROM reactions r JOIN messages m ON m.id = r.message_id
        WHERE r.author_seen = 0 AND m.user_id = ? AND m.topic_id = ? AND m.deleted_at IS NULL ORDER BY m.id', [$me, $topic_id])->fetchAll(PDO::FETCH_COLUMN));
}

// You've now seen these messages of yours: their reactions are no longer new.
function mark_reactions_seen(int $me, array $ids): void
{
    $ids = array_slice(array_filter(array_map('intval', $ids)), 0, 200);
    if ($ids) {
        q('UPDATE reactions r JOIN messages m ON m.id = r.message_id SET r.author_seen = 1
           WHERE m.user_id = ? AND r.author_seen = 0 AND r.message_id IN (' . implode(',', $ids) . ')', [$me]);
    }
}

function unread_mentions(int $me, int $topic_id, int $last_read): array
{
    return array_map('intval', q('SELECT m.id FROM mentions mn JOIN messages m ON m.id = mn.message_id
        WHERE mn.user_id = ? AND m.topic_id = ? AND m.id > ? AND m.deleted_at IS NULL ORDER BY m.id', [$me, $topic_id, $last_read])->fetchAll(PDO::FETCH_COLUMN));
}
