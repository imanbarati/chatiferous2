<?php
// Direct messages. A DM is a two-person topic (kind 'dm'): replies, reactions, files, pins,
// live updates and read markers work exactly as in topics. It never appears in topic lists or
// search, and only its two members can read or act on it (topic_visible() is checked on every
// way in).
//
// DMs are end-to-end encrypted (web/assets/e2e.js): each member's browser holds their key;
// the server stores only public keys, locked copies it can't open, and sealed messages and
// files. Nobody with the server, the site's owner included, can read a DM.

const SEALED_RE = '/^e2e1:[A-Za-z0-9+\\/=]{24,}$/';
const SEALED_MAX = 300000;

function is_sealed(string $s): bool
{
    return strlen($s) <= SEALED_MAX && preg_match(SEALED_RE, $s) === 1;
}

// Text as it is stored (sealed for DMs), and its formatting. Kept for the older at-rest scheme's
// messages, of which none remain.
function msg_open(array $row): array
{
    return [(string)($row['text'] ?? ''), $row['entities'] ?? null];
}

// ---------- Keys (all locked; the server can't open any of them) ----------

function valid_locked(string $json, array $fields): bool
{
    $o = json_decode($json, true);
    if (!is_array($o) || strlen($json) > 4000) {
        return false;
    }
    foreach ($fields as $f) {
        if (!isset($o[$f]) || !is_string($o[$f]) && !is_int($o[$f])) {
            return false;
        }
    }
    return true;
}

function my_keys(int $me): ?array
{
    return q('SELECT * FROM user_keys WHERE user_id = ?', [$me])->fetch() ?: null;
}

// First set-up, or starting fresh after losing both password and recovery code (a new key pair:
// conversations then need the other person to restore them).
function save_keys(array $user, string $public, string $locked_pw, string $locked_code, bool $fresh): array
{
    if (strlen($public) > 400 || !preg_match('~^[A-Za-z0-9+/=]+$~', $public)
        || !valid_locked($locked_pw, ['salt', 'iv', 'ct', 'rounds']) || !valid_locked($locked_code, ['salt', 'iv', 'ct', 'rounds'])) {
        fail('Those keys don’t look right. Please reload the page and try again.');
    }
    $me = (int)$user['id'];
    $have = my_keys($me);
    if ($have && !$fresh) {
        fail('Your private messages are already set up.');
    }
    if ($have) {
        q('UPDATE user_keys SET version = version + 1, public_key = ?, locked_pw = ?, locked_code = ? WHERE user_id = ?',
            [$public, $locked_pw, $locked_code, $me]);
    } else {
        q('INSERT INTO user_keys (user_id, public_key, locked_pw, locked_code) VALUES (?, ?, ?, ?)', [$me, $public, $locked_pw, $locked_code]);
    }
    // The people you're in conversations with: their apps notice at once and share (or offer to
    // restore) each conversation's key for your new keys.
    foreach (q("SELECT id FROM topics WHERE kind = 'dm' AND (dm_a = ? OR dm_b = ?)", [$me, $me])->fetchAll(PDO::FETCH_COLUMN) as $t) {
        record_change((int)$t, null, 'keys');
    }
    return my_keys($me);
}

// A new password or a new recovery code: the same key pair, locked again.
function relock_keys(array $user, ?string $locked_pw, ?string $locked_code): void
{
    $me = (int)$user['id'];
    if (!my_keys($me)) {
        fail('Your private messages aren’t set up yet.');
    }
    if ($locked_pw !== null) {
        valid_locked($locked_pw, ['salt', 'iv', 'ct', 'rounds']) || fail('That doesn’t look right.');
        q('UPDATE user_keys SET locked_pw = ? WHERE user_id = ?', [$locked_pw, $me]);
    }
    if ($locked_code !== null) {
        valid_locked($locked_code, ['salt', 'iv', 'ct', 'rounds']) || fail('That doesn’t look right.');
        q('UPDATE user_keys SET locked_code = ? WHERE user_id = ?', [$locked_code, $me]);
    }
}

function public_key(int $user_id): ?array
{
    $r = q('SELECT public_key, version FROM user_keys WHERE user_id = ?', [$user_id])->fetch();
    return $r ? ['key' => $r['public_key'], 'version' => (int)$r['version']] : null;
}

// Stores a conversation's key locked for one of its members. Anyone in the conversation may lock
// it for the other person when they have none, or only an out-of-date one (after starting
// fresh) - that's how a conversation is shared and restored - and for themselves; never
// replace someone's current one.
function save_dm_key(array $user, int $topic_id, int $for, string $locked): void
{
    $t = load_topic_for($user, $topic_id);
    if ($t['kind'] !== 'dm' || ($for !== (int)$t['dm_a'] && $for !== (int)$t['dm_b'])) {
        fail('That isn’t part of this conversation.');
    }
    valid_locked($locked, ['epk', 'iv', 'ct']) || fail('That doesn’t look right.');
    $pk = public_key($for) ?? fail('That person hasn’t set up private messages yet.');
    $have = q('SELECT key_version FROM dm_keys WHERE topic_id = ? AND user_id = ?', [$topic_id, $for])->fetchColumn();
    if ($have !== false && (int)$have >= $pk['version'] && $for !== (int)$user['id']) {
        return;   // they already have a working one
    }
    q('REPLACE INTO dm_keys (topic_id, user_id, key_version, locked) VALUES (?, ?, ?, ?)', [$topic_id, $for, $pk['version'], $locked]);
    record_change($topic_id, null, 'keys');
}

// What this member's app needs to open a DM: their locked copy, and whether the other person
// needs one (never shared yet, or they started fresh and need it restored).
function dm_key_state(int $me, array $t): array
{
    $other = dm_partner($t, $me);
    $rows = q('SELECT user_id, key_version, locked FROM dm_keys WHERE topic_id = ?', [$t['id']])->fetchAll(PDO::FETCH_UNIQUE);
    $mine = my_keys($me);
    $theirs = public_key($other);
    $my_row = $rows[$me] ?? null;
    $their_row = $rows[$other] ?? null;
    return [
        'lock'        => $my_row && $mine && (int)$my_row['key_version'] === (int)$mine['version'] ? $my_row['locked'] : null,
        'has_key'     => (bool)$rows,                    // the conversation already has a key
        'partner_key' => $theirs,                        // null: they haven't set up private messages
        'partner_needs' => $theirs && (!$their_row || (int)$their_row['key_version'] < $theirs['version'])
            ? ($their_row ? 'restore' : 'share') : null,  // restore = they started fresh: ask before sharing
    ];
}

// ---------- Who can see what ----------

function topic_visible(int $me, array $t): bool
{
    return ($t['kind'] ?? 'topic') !== 'dm' || (int)$t['dm_a'] === $me || (int)$t['dm_b'] === $me;
}

// The topic, if this person may see it; otherwise "not available" (the same whether it
// exists or not, so nothing is given away).
function load_topic_for(array $user, int $id): array
{
    $t = q('SELECT * FROM topics WHERE id = ?', [$id])->fetch();
    if (!$t || !topic_visible((int)$user['id'], $t)) {
        fail('That chat isn’t available.');
    }
    return $t;
}

function load_message_for(array $user, int $id): array
{
    $m = q('SELECT * FROM messages WHERE id = ? AND deleted_at IS NULL', [$id])->fetch();
    if (!$m) {
        fail('That message was deleted.');
    }
    load_topic_for($user, (int)$m['topic_id']);
    return $m;
}

// ---------- Direct messages ----------

function dm_partner(array $t, int $me): int
{
    return (int)$t['dm_a'] === $me ? (int)$t['dm_b'] : (int)$t['dm_a'];
}

function is_blocked(int $by, int $who): bool
{
    return (bool)q('SELECT 1 FROM blocks WHERE user_id = ? AND blocked_id = ?', [$by, $who])->fetch();
}

function set_block(array $user, int $who, bool $block): void
{
    if ($who === (int)$user['id']) {
        fail('You can’t block yourself.');
    }
    if ($block) {
        q('INSERT IGNORE INTO blocks (user_id, blocked_id) VALUES (?, ?)', [$user['id'], $who]);
    } else {
        q('DELETE FROM blocks WHERE user_id = ? AND blocked_id = ?', [$user['id'], $who]);
    }
}

// Refuses a message in a DM if either person has blocked the other.
function dm_check_send(array $user, array $topic): void
{
    if (($topic['kind'] ?? 'topic') !== 'dm') {
        return;
    }
    $me = (int)$user['id'];
    $other = dm_partner($topic, $me);
    if (is_blocked($me, $other)) {
        fail('You’ve blocked this person. Unblock them to send a message.');
    }
    if (is_blocked($other, $me)) {
        fail('You can’t message this person.');
    }
}

// The DM between you and someone (created the first time; it shows in lists once it has a message).
function dm_with(array $user, int $other): int
{
    $me = (int)$user['id'];
    if ($other === $me) {
        fail('You can’t send a direct message to yourself.');
    }
    $o = q('SELECT role, status FROM users WHERE id = ?', [$other])->fetch();
    if (!$o || $o['role'] === 'system' || !in_array($o['status'], ['active', 'unclaimed'], true)) {
        fail('That person can’t receive direct messages.');
    }
    [$a, $b] = [min($me, $other), max($me, $other)];
    $find = fn() => q("SELECT id FROM topics WHERE kind = 'dm' AND dm_a = ? AND dm_b = ?", [$a, $b])->fetchColumn();
    if (!$find()) {
        q("INSERT IGNORE INTO topics (kind, title, dm_a, dm_b, created_by, created_at) VALUES ('dm', '', ?, ?, ?, UTC_TIMESTAMP())", [$a, $b, $me]);
    }
    return (int)$find();
}

// Your conversations, newest first, with previews and unread counts.
function dm_list(int $me): array
{
    $rows = q("SELECT t.id, t.dm_a, t.dm_b, m.id AS last_id, m.user_id AS last_user, m.kind AS last_kind, m.text, m.entities,
                 m.service, m.created_at AS last_at, u.display_name AS last_name, COALESCE(rs.muted, 0) AS muted,
                 (SELECT a.kind FROM attachments a WHERE a.message_id = m.id LIMIT 1) AS att,
                 (SELECT a.name FROM attachments a WHERE a.message_id = m.id LIMIT 1) AS att_name,
                 (SELECT p.question FROM polls p WHERE p.message_id = m.forwarded_msg_id) AS poll,
                 (SELECT COUNT(*) FROM messages x WHERE x.topic_id = t.id AND x.id > COALESCE(rs.last_read_id, 0)
                    AND x.deleted_at IS NULL AND x.user_id <> ?) AS unread
               FROM topics t
               JOIN messages m ON m.id = t.last_message_id
               JOIN users u ON u.id = m.user_id
               LEFT JOIN read_state rs ON rs.topic_id = t.id AND rs.user_id = ?
               WHERE t.kind = 'dm' AND (t.dm_a = ? OR t.dm_b = ?)
               ORDER BY t.last_message_id DESC", [$me, $me, $me, $me])->fetchAll();
    return array_map(function ($r) use ($me) {
        $sealed = is_sealed((string)$r['text']);
        return [
            'id'      => (int)$r['id'],
            'partner' => dm_partner($r, $me),
            'unread'  => (int)$r['unread'],
            'muted'   => (bool)$r['muted'],
            'keys'    => dm_key_state($me, $r),   // the app opens the preview with the conversation key
            'last'    => [
                'id'      => (int)$r['last_id'],
                'at'      => iso($r['last_at']),
                'mine'    => (int)$r['last_user'] === $me,
                'sealed'  => $sealed ? $r['text'] : null,
                'preview' => $sealed ? '' : message_preview($r['last_kind'], mb_substr((string)$r['text'], 0, 160), $r['poll'], $r['att'], $r['att_name'], $r['service'], $r['last_name']),
            ],
        ];
    }, $rows);
}
