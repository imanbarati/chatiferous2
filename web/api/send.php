<?php
// POST topic, text, reply_to?, attachments? (comma-separated ids), mentions? (JSON),
// or a poll: poll_question, poll_options[] , poll_anonymous, poll_multiple.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';

$user = api_post();
api_run(function () use ($user) {
    $poll = null;
    if (isset($_POST['poll_question'])) {
        $poll = [
            'question'  => (string)$_POST['poll_question'],
            'options'   => (array)($_POST['poll_options'] ?? []),
            'anonymous' => !empty($_POST['poll_anonymous']),
            'multiple'  => !empty($_POST['poll_multiple']),
        ];
    }
    // Forward (with an optional comment, sent first, as in Telegram).
    if (!empty($_POST['forward'])) {
        $topic = (int)($_POST['topic'] ?? 0);
        $also = [];
        if (trim((string)($_POST['text'] ?? '')) !== '') {
            $also[] = message_json(send_message($user, $topic, (string)$_POST['text'], post_mentions(), 0, [], null), (int)$user['id']);
        }
        return message_json(forward_message($user, (int)$_POST['forward'], $topic, (string)($_POST['sealed'] ?? '')), (int)$user['id']) + ['also' => $also];
    }
    $att = array_filter(explode(',', (string)($_POST['attachments'] ?? '')), 'strlen');
    $id = send_message($user, (int)($_POST['topic'] ?? 0), (string)($_POST['text'] ?? ''), post_mentions(),
        (int)($_POST['reply_to'] ?? 0), $att, $poll, (string)($_POST['quote'] ?? ''));
    // A forward the app made itself (out of a DM, which only it can read): keep the credit.
    // Only for a DM the sender is in, and only crediting its author (or whoever that message was
    // itself forwarded from), so a credit can't be made up.
    if (!empty($_POST['fwd_id'])) {
        $orig = load_message_for($user, (int)$_POST['fwd_id']);
        if (load_topic((int)$orig['topic_id'])['kind'] === 'dm') {
            $author = (string)q('SELECT display_name FROM users WHERE id = ?', [$orig['user_id']])->fetchColumn();
            $asked = trim((string)($_POST['fwd_from'] ?? ''));
            $from = $asked !== '' && $asked === (string)$orig['forwarded_from'] ? $asked : $author;
            q('UPDATE messages SET forwarded_from = ?, forwarded_msg_id = ? WHERE id = ? AND user_id = ?',
                [mb_substr($from, 0, 128), (int)$orig['id'], $id, $user['id']]);
        }
    }
    return message_json($id, (int)$user['id']);
});
