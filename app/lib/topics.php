<?php
// Topic management: admins create; the creator or an admin edits,
// closes and reopens; admins pin (5 at most) and delete. General can't be
// deleted or closed. Changes post service messages, as in Telegram.

require_once APP_DIR . '/lib/actions.php';

const MAX_PINNED_TOPICS = 5;

function service_message(array $user, int $topic_id, array $service): int
{
    q("INSERT INTO messages (topic_id, user_id, kind, text, service, created_at) VALUES (?, ?, 'service', '', ?, UTC_TIMESTAMP())",
        [$topic_id, $user['id'], json_encode($service, JSON_UNESCAPED_UNICODE)]);
    $id = (int)db()->lastInsertId();
    q('UPDATE topics SET last_message_id = ? WHERE id = ?', [$id, $topic_id]);
    record_change($topic_id, $id, 'new');
    return $id;
}

function clean_topic_fields(string $title, ?string $icon, int $color): array
{
    $title = trim(preg_replace('/\s+/u', ' ', $title));
    if ($title === '' || mb_strlen($title) > 128) {
        fail('A topic needs a name of up to 128 characters.');
    }
    if ($icon !== null && $icon !== '' && !preg_match('/^[a-z0-9-]{1,40}$/', $icon)) {
        fail('That icon isn’t available.');
    }
    return [$title, $icon ?: null, max(0, min(5, $color))];
}

function can_manage_topic(array $user, array $topic): bool
{
    if (($topic['kind'] ?? 'topic') === 'dm') {
        return false;   // direct messages aren't topics: nobody renames, closes or deletes them
    }
    return is_admin($user) || (int)$topic['created_by'] === (int)$user['id'];
}

function create_topic(array $user, string $title, ?string $icon, int $color): int
{
    $members_may = setting('members_create_topics', '0') === '1';
    if (!is_admin($user) && !$members_may) {
        fail('Only admins can create topics.');
    }
    [$title, $icon, $color] = clean_topic_fields($title, $icon, $color);
    q('INSERT INTO topics (title, icon_color, icon_key, created_by) VALUES (?, ?, ?, ?)', [$title, $color, $icon, $user['id']]);
    $id = (int)db()->lastInsertId();
    mark_all_read((int)$user['id']);
    service_message($user, $id, ['action' => 'topic_created', 'title' => $title]);
    return $id;
}

function edit_topic(array $user, int $id, string $title, ?string $icon, int $color): void
{
    $t = load_topic($id);
    if (!can_manage_topic($user, $t)) {
        fail('Only the topic’s creator or an admin can edit it.');
    }
    [$title, $icon, $color] = clean_topic_fields($title, $icon, $color);
    if ($t['is_general']) {
        [$icon, $color] = [null, 0];   // General keeps its "#"
    }
    q('UPDATE topics SET title = ?, icon_key = ?, icon_color = ? WHERE id = ?', [$title, $icon, $color, $id]);
    if ($title !== $t['title']) {
        service_message($user, $id, ['action' => 'topic_renamed', 'title' => $title]);
    } else {
        record_change($id, null, 'topic');
    }
}

function close_topic(array $user, int $id, bool $closed): void
{
    $t = load_topic($id);
    if (!can_manage_topic($user, $t)) {
        fail('Only the topic’s creator or an admin can close it.');
    }
    if ($t['is_general']) {
        fail('General can’t be closed.');
    }
    q('UPDATE topics SET is_closed = ? WHERE id = ?', [$closed ? 1 : 0, $id]);
    service_message($user, $id, ['action' => $closed ? 'topic_closed' : 'topic_reopened']);
}

function pin_topic(array $user, int $id, bool $pin): void
{
    if (!is_admin($user)) {
        fail('Only admins can pin topics.');
    }
    if (load_topic($id)['kind'] === 'dm') {
        fail('That can’t be pinned.');
    }
    if ($pin) {
        $pinned = q('SELECT id FROM topics WHERE pin_order IS NOT NULL ORDER BY pin_order')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($id, array_map('intval', $pinned), true)) {
            if (count($pinned) >= MAX_PINNED_TOPICS) {
                fail('Sorry, you can’t pin more than ' . MAX_PINNED_TOPICS . ' topics to the top.');
            }
            q('UPDATE topics SET pin_order = ? WHERE id = ?', [count($pinned) + 1, $id]);
        }
    } else {
        q('UPDATE topics SET pin_order = NULL WHERE id = ?', [$id]);
        // Renumber the rest 1..n
        foreach (q('SELECT id FROM topics WHERE pin_order IS NOT NULL ORDER BY pin_order')->fetchAll(PDO::FETCH_COLUMN) as $i => $tid) {
            q('UPDATE topics SET pin_order = ? WHERE id = ?', [$i + 1, $tid]);
        }
    }
    record_change($id, null, 'topic');
}

// Deletes the topic and every message in it. Admins; or the creator while the
// topic has at most 10 messages, all their own (Telegram's rule).
function delete_topic(array $user, int $id): void
{
    $t = load_topic($id);
    if ($t['kind'] !== 'topic') {
        fail('That topic no longer exists.');   // direct messages aren't topics, even for admins
    }
    if ($t['is_general']) {
        fail('General can’t be deleted.');
    }
    if (!is_admin($user)) {
        $mine = (int)$t['created_by'] === (int)$user['id'];
        $stats = q("SELECT COUNT(*) n, SUM(user_id <> ?) others FROM messages WHERE topic_id = ? AND kind <> 'service' AND deleted_at IS NULL",
            [$user['id'], $id])->fetch();
        if (!$mine || $stats['n'] > 10 || $stats['others'] > 0) {
            fail('Only an admin can delete this topic.');
        }
    }
    q('DELETE FROM topics WHERE id = ?', [$id]);   // messages, pins, read state go with it (foreign keys)
    record_change($id, null, 'topic_deleted');
}
