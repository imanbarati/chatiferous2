<?php
// Everything that changes messages. Permission rules follow Telegram.

require_once APP_DIR . '/lib/compose.php';
require_once APP_DIR . '/lib/dm.php';

const EDIT_WINDOW_SECONDS = 48 * 3600;
const MESSAGES_PER_MINUTE = 20;
const REACTIONS = ['👍', '❤', '😁', '🔥', '💯', '🙏', '👏', '🤣', '🤔', '👌', '🥰', '🕊', '😢', '🆒', '⚡', '😱', '🤨',
                   '💔', '🤯', '😭', '🤓', '❤‍🔥', '😎', '🎉', '👀', '🤩', '😇', '🫡', '👎'];

final class ActionError extends Exception {}

function fail(string $message): never
{
    throw new ActionError($message);
}

function record_change(int $topic_id, ?int $message_id, string $kind): void
{
    q('INSERT INTO changes (topic_id, message_id, kind) VALUES (?, ?, ?)', [$topic_id, $message_id, $kind]);
}

function load_topic(int $id): array
{
    return q('SELECT * FROM topics WHERE id = ?', [$id])->fetch() ?: fail('That topic no longer exists.');
}

function load_message(int $id): array
{
    return q('SELECT * FROM messages WHERE id = ? AND deleted_at IS NULL', [$id])->fetch() ?: fail('That message was deleted.');
}

function can_post(array $user, array $topic): bool
{
    return !$topic['is_closed'] || is_admin($user) || (int)$topic['created_by'] === (int)$user['id'];
}

function refresh_topic_last(int $topic_id): void
{
    q('UPDATE topics SET last_message_id = (SELECT MAX(id) FROM messages WHERE topic_id = ? AND deleted_at IS NULL) WHERE id = ?',
        [$topic_id, $topic_id]);
}

function save_mentions(int $message_id, int $author, array $ents, ?int $reply_to): void
{
    q('DELETE FROM mentions WHERE message_id = ?', [$message_id]);
    $ids = mentioned_users($ents);
    if ($reply_to) {
        $ids[] = (int)q('SELECT user_id FROM messages WHERE id = ?', [$reply_to])->fetchColumn();
    }
    foreach (array_unique($ids) as $u) {
        if ($u && $u !== $author) {
            q('INSERT IGNORE INTO mentions (message_id, user_id) VALUES (?, ?)', [$message_id, $u]);
        }
    }
}

// $poll: null, or ['question', 'options' => [...], 'anonymous' => bool, 'multiple' => bool]
// $quote: for a reply, the words of the original being answered (quote-reply).
function send_message(array $user, int $topic_id, string $input, array $name_mentions, int $reply_to, array $att_ids, ?array $poll, string $quote = ''): int
{
    $me = (int)$user['id'];
    $topic = load_topic_for($user, $topic_id);
    $dm = $topic['kind'] === 'dm';
    if (!$dm && !can_post($user, $topic)) {
        fail('This topic is closed.');
    }
    dm_check_send($user, $topic);
    if ($dm && $poll) {
        fail('Polls can only be posted in topics.');
    }
    $recent = (int)q('SELECT COUNT(*) FROM messages WHERE user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 MINUTE', [$me])->fetchColumn();
    if ($recent >= MESSAGES_PER_MINUTE) {
        fail('You’re sending messages very quickly. Please wait a minute.');
    }
    if ($dm) {
        // End-to-end: the text, its formatting, any quote and file details arrive sealed by the
        // sender's browser. The server can't read them and stores them as they are.
        if (!is_sealed($input)) {
            fail('This message couldn’t be sent securely. Please reload the page and try again.');
        }
        [$text, $ents] = [$input, []];
    } else {
        [$text, $ents] = compose_message($input, $name_mentions);
        if (mb_strlen($text) > MAX_MESSAGE_CHARS) {
            fail('Messages can be up to ' . MAX_MESSAGE_CHARS . ' characters.');
        }
    }
    $att_ids = array_values(array_unique(array_map('intval', $att_ids)));
    if ($att_ids) {
        $in = implode(',', $att_ids);
        $mine = (int)q("SELECT COUNT(*) FROM attachments WHERE id IN ($in) AND uploader_id = ? AND message_id IS NULL", [$me])->fetchColumn();
        if ($mine !== count($att_ids) || count($att_ids) > 10) {
            fail('Some of the attached files couldn’t be found. Please attach them again.');
        }
    }
    if ($poll) {
        $question = trim($poll['question'] ?? '');
        $options = array_values(array_filter(array_map(fn($o) => mb_substr(trim((string)$o), 0, 100), $poll['options'] ?? []), 'strlen'));
        if ($question === '' || mb_strlen($question) > 300) {
            fail('A poll needs a question of up to 300 characters.');
        }
        if (count($options) < 2 || count($options) > 10) {
            fail('A poll needs between 2 and 10 options.');
        }
    } elseif ($text === '' && !$att_ids) {
        fail('The message is empty.');
    }
    if ($reply_to) {
        $parent = q('SELECT topic_id, text, entities FROM messages WHERE id = ? AND deleted_at IS NULL', [$reply_to])->fetch();
        if (!$parent || (int)$parent['topic_id'] !== $topic_id) {
            $reply_to = 0; // replying to something gone or elsewhere: just send it
        }
    }
    // A quote must be words that really are in the original (as in Telegram); otherwise it's a plain reply.
    $quote = trim(str_replace("\r\n", "\n", $quote));
    $quote = !$dm && $reply_to && $quote !== '' && mb_strlen($quote) <= 1024 && str_contains((string)$parent['text'], $quote) ? $quote : null;
    $stored_text = $poll ? '' : $text;
    $stored_ents = $ents && !$poll ? json_encode($ents, JSON_UNESCAPED_UNICODE) : null;

    db()->beginTransaction();
    q('INSERT INTO messages (topic_id, user_id, kind, text, entities, reply_to_id, quote_text, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [
        $topic_id, $me, $poll ? 'poll' : 'text', $stored_text, $stored_ents, $reply_to ?: null, $quote,
    ]);
    $id = (int)db()->lastInsertId();
    if ($att_ids && !$poll) {
        q('UPDATE attachments SET message_id = ? WHERE id IN (' . implode(',', $att_ids) . ') AND uploader_id = ?', [$id, $me]);
    }
    if ($poll) {
        q('INSERT INTO polls (message_id, question, is_anonymous, multiple) VALUES (?, ?, ?, ?)',
            [$id, $question, empty($poll['anonymous']) ? 0 : 1, empty($poll['multiple']) ? 0 : 1]);
        foreach ($options as $i => $o) {
            q('INSERT INTO poll_options (message_id, position, text) VALUES (?, ?, ?)', [$id, $i, $o]);
        }
    }
    if (!$dm) {   // in a DM everything is addressed to the other person anyway
        save_mentions($id, $me, $poll ? [] : $ents, $reply_to ?: null);
    }
    q('UPDATE topics SET last_message_id = ? WHERE id = ?', [$id, $topic_id]);
    q('INSERT INTO read_state (user_id, topic_id, last_read_id) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))', [$me, $topic_id, $id]);
    record_change($topic_id, $id, 'new');
    db()->commit();
    return $id;
}

// Forward a message into a topic, as in Telegram: a copy with a "Forwarded from X" header.
// A forward of a forward keeps the original author. Files are shared, not copied (uploads
// are never deleted once sent). Polls and service lines can't be forwarded.
function forward_message(array $user, int $source_id, int $topic_id, string $sealed = ''): int
{
    $me = (int)$user['id'];
    load_message_for($user, $source_id);   // you can only forward what you can see
    $src = q('SELECT m.*, u.display_name FROM messages m JOIN users u ON u.id = m.user_id WHERE m.id = ? AND m.deleted_at IS NULL', [$source_id])->fetch()
        ?: fail('That message no longer exists.');
    if (!in_array($src['kind'], ['text', 'poll'], true)) {
        fail('That can’t be forwarded.');
    }
    $target = load_topic_for($user, $topic_id);
    if ($target['kind'] !== 'dm' && !can_post($user, $target)) {
        fail('This topic is closed.');
    }
    dm_check_send($user, $target);
    $recent = (int)q('SELECT COUNT(*) FROM messages WHERE user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 MINUTE', [$me])->fetchColumn();
    if ($recent >= MESSAGES_PER_MINUTE) {
        fail('You’re sending messages very quickly. Please wait a minute.');
    }
    $again = (string)$src['forwarded_from'] !== '';   // already a forward: credit the original author
    $from = $again ? $src['forwarded_from'] : $src['display_name'];
    $orig = $again ? $src['forwarded_msg_id'] : $src['id'];

    if (load_topic((int)$src['topic_id'])['kind'] === 'dm') {
        fail('Please reload the page to forward this.');   // the app forwards DM messages itself (only it can read them)
    }
    [$stored, $stored_ents] = [$src['text'], $src['entities']];
    if ($target['kind'] === 'dm') {   // into a DM: the text arrives sealed by the sender's browser
        is_sealed($sealed) || fail('This message couldn’t be forwarded securely. Please reload the page and try again.');
        [$stored, $stored_ents] = [$sealed, null];
    }

    db()->beginTransaction();
    if ($src['kind'] === 'poll') {
        $orig = poll_source($src);   // a forwarded poll points at the original poll itself
    }
    q("INSERT INTO messages (topic_id, user_id, kind, text, entities, forwarded_from, forwarded_msg_id, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())", [$topic_id, $me, $src['kind'], $stored, $stored_ents, mb_substr($from, 0, 128), $orig]);
    $id = (int)db()->lastInsertId();
    // Files are shared with the copy, as stored (an encrypted DM file stays encrypted).
    q('INSERT INTO attachments (message_id, uploader_id, kind, path, name, mime, size, width, height, duration, encrypted, created_at)
       SELECT ?, ?, kind, path, name, mime, size, width, height, duration, encrypted, UTC_TIMESTAMP() FROM attachments WHERE message_id = ? ORDER BY id',
        [$id, $me, $source_id]);
    q('UPDATE topics SET last_message_id = ? WHERE id = ?', [$id, $topic_id]);
    q('INSERT INTO read_state (user_id, topic_id, last_read_id) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))', [$me, $topic_id, $id]);
    record_change($topic_id, $id, 'new');
    db()->commit();
    return $id;
}

function edit_message(array $user, int $id, string $input, array $name_mentions): array
{
    $m = load_message_for($user, $id);
    $topic = load_topic((int)$m['topic_id']);
    if ((int)$m['user_id'] !== (int)$user['id']) {
        fail('You can only edit your own messages.');
    }
    if ($m['kind'] !== 'text') {
        fail('This message can’t be edited.');
    }
    if (!is_admin($user) && time() - strtotime($m['created_at'] . ' UTC') > EDIT_WINDOW_SECONDS) {
        fail('Messages can only be edited for 48 hours after sending.');
    }
    if ($topic['kind'] === 'dm') {
        is_sealed($input) || fail('This edit couldn’t be saved securely. Please reload the page and try again.');
        q('UPDATE messages SET text = ?, entities = NULL, edited_at = UTC_TIMESTAMP() WHERE id = ?', [$input, $id]);
        record_change((int)$m['topic_id'], $id, 'edit');
        return $m;
    }
    [$text, $ents] = compose_message($input, $name_mentions);
    $has_att = (bool)q('SELECT 1 FROM attachments WHERE message_id = ? LIMIT 1', [$id])->fetch();
    if ($text === '' && !$has_att) {
        fail('The message can’t be empty. To remove it, delete it instead.');
    }
    if (mb_strlen($text) > MAX_MESSAGE_CHARS) {
        fail('Messages can be up to ' . MAX_MESSAGE_CHARS . ' characters.');
    }
    if ($text === $m['text'] && json_encode($ents, JSON_UNESCAPED_UNICODE) === ($m['entities'] ?? '[]')) {
        return $m;
    }
    q('UPDATE messages SET text = ?, entities = ?, edited_at = UTC_TIMESTAMP() WHERE id = ?',
        [$text, $ents ? json_encode($ents, JSON_UNESCAPED_UNICODE) : null, $id]);
    save_mentions($id, (int)$user['id'], $ents, $m['reply_to_id'] ? (int)$m['reply_to_id'] : null);
    record_change((int)$m['topic_id'], $id, 'edit');
    return $m;
}

// Own messages any time; admins anyone's. Deleted for everyone.
function delete_message(array $user, int $id): void
{
    $m = load_message_for($user, $id);
    $in_dm = load_topic((int)$m['topic_id'])['kind'] === 'dm';
    if (!$in_dm && (int)$m['user_id'] !== (int)$user['id'] && !is_admin($user)) {
        fail('You can only delete your own messages.');
    }
    if ($m['kind'] === 'service' && !is_admin($user)) {
        fail('This can’t be deleted.');
    }
    q('UPDATE messages SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
    q('DELETE FROM pins WHERE message_id = ?', [$id]);
    refresh_topic_last((int)$m['topic_id']);
    record_change((int)$m['topic_id'], $id, 'delete');
}

// One reaction per person: the same emoji again removes it, a different one replaces it.
function react(array $user, int $id, string $emoji): void
{
    $m = load_message_for($user, $id);
    if ($m['kind'] === 'service') {
        fail('You can’t react to that.');
    }
    if ($emoji !== '') {
        dm_check_send($user, load_topic((int)$m['topic_id']));   // blocked either way: no new reactions
    }
    $me = (int)$user['id'];
    $current = q('SELECT emoji FROM reactions WHERE message_id = ? AND user_id = ?', [$id, $me])->fetchColumn();
    if ($emoji === '' || $emoji === $current) {
        q('DELETE FROM reactions WHERE message_id = ? AND user_id = ?', [$id, $me]);
    } else {
        if (!in_array($emoji, REACTIONS, true)) {
            fail('That reaction isn’t available.');
        }
        // New to the message's author until they see it (not when you react to your own).
        $seen = (int)$m['user_id'] === $me ? 1 : 0;
        q('INSERT INTO reactions (message_id, user_id, emoji, created_at, author_seen) VALUES (?, ?, ?, UTC_TIMESTAMP(), ?)
           ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), created_at = VALUES(created_at), author_seen = VALUES(author_seen)', [$id, $me, $emoji, $seen]);
    }
    record_change((int)$m['topic_id'], $id, 'react');
}

// Vote (or with no options, retract). Imported and closed polls can't be voted in.
// The message holding a poll: a forwarded poll's votes go to the original.
function poll_source(array $m): int
{
    return $m['kind'] === 'poll' && $m['forwarded_msg_id'] ? (int)$m['forwarded_msg_id'] : (int)$m['id'];
}

function vote(array $user, int $id, array $option_ids): void
{
    $m = load_message_for($user, $id);
    $shown = $m;
    $id = poll_source($m);
    if ($id !== (int)$m['id']) {
        $m = q('SELECT * FROM messages WHERE id = ?', [$id])->fetch() ?: fail('That poll no longer exists.');
    }
    $poll = q('SELECT * FROM polls WHERE message_id = ?', [$id])->fetch() ?: fail('That poll no longer exists.');
    if ($poll['is_closed']) {   // imported polls stay open, as they were in Telegram
        fail('This poll is closed.');
    }
    $valid = q('SELECT id FROM poll_options WHERE message_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
    $option_ids = array_values(array_intersect(array_map('intval', $option_ids), array_map('intval', $valid)));
    if (!$poll['multiple'] && count($option_ids) > 1) {
        fail('Please choose one answer.');
    }
    $me = (int)$user['id'];
    q('DELETE FROM poll_votes WHERE message_id = ? AND user_id = ?', [$id, $me]);
    foreach ($option_ids as $o) {
        q('INSERT INTO poll_votes (option_id, user_id, message_id, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$o, $me, $id]);
    }
    record_change((int)$m['topic_id'], $id, 'vote');
    if ((int)$shown['id'] !== $id) {
        record_change((int)$shown['topic_id'], (int)$shown['id'], 'vote');   // the forwarded copy voted on
    }
}

function close_poll(array $user, int $id): void
{
    $m = load_message_for($user, $id);
    if (poll_source($m) !== $id) {
        $id = poll_source($m);
        $m = load_message($id);   // stopping a forwarded poll stops the original: its author or an admin
    }
    if ((int)$m['user_id'] !== (int)$user['id'] && !is_admin($user)) {
        fail('Only the poll’s author or an admin can close it.');
    }
    q('UPDATE polls SET is_closed = 1 WHERE message_id = ?', [$id]);
    record_change((int)$m['topic_id'], $id, 'vote');
}

// Admins pin; pinning also posts "X pinned “…”", as in Telegram.
function set_pin(array $user, int $id, bool $pin): void
{
    // Unpinning also works for a pinned message that has since been deleted.
    $m = $pin ? load_message($id) : (q('SELECT * FROM messages WHERE id = ?', [$id])->fetch() ?: fail('That message no longer exists.'));
    $pt = load_topic_for($user, (int)$m['topic_id']);
    if ($pt['kind'] !== 'dm' && !is_admin($user)) {   // in a DM, both people may pin
        fail('Only admins can pin messages.');
    }
    if ($pin) {
        dm_check_send($user, $pt);
    }
    $topic_id = (int)$m['topic_id'];
    if ($pin) {
        q('INSERT IGNORE INTO pins (message_id, topic_id, pinned_by, pinned_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$id, $topic_id, $user['id']]);
        q("INSERT INTO messages (topic_id, user_id, kind, text, service, created_at) VALUES (?, ?, 'service', '', ?, UTC_TIMESTAMP())",
            [$topic_id, $user['id'], json_encode(['action' => 'pinned', 'message_id' => $id])]);
        $sid = (int)db()->lastInsertId();
        q('UPDATE topics SET last_message_id = ? WHERE id = ?', [$sid, $topic_id]);
        record_change($topic_id, $sid, 'new');
    } else {
        q('DELETE FROM pins WHERE message_id = ?', [$id]);
    }
    record_change($topic_id, $id, 'pin');
}
