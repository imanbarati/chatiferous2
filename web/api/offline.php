<?php
// GET [skip=<topic ids>]: the latest page of several topics at once, in one reply.
//
// A device that wants to be readable with no signal needs a page of every topic. Asking for them
// one at a time is dozens of requests on a host that would much rather not be asked dozens of
// times, and a run that stops halfway leaves the device half ready. This is the same thing in one
// piece: the most recently active topics, skipping any the device says it already holds.
//
// Direct messages are never in it. Their copy is kept only when one is opened, still sealed.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/chat.php';

const OFFLINE_BUNDLE_TOPICS = 25;   // the rest keep until the device asks again

$user = api_user();
$me = (int)$user['id'];
$skip = array_flip(array_map('intval', array_filter(explode(',', (string)($_GET['skip'] ?? '')))));

$out = [];
foreach (topic_list($me) as $t) {
    if (count($out) >= OFFLINE_BUNDLE_TOPICS) {
        break;
    }
    $id = (int)$t['id'];
    if (($t['kind'] ?? '') === 'dm' || isset($skip[$id])) {
        continue;
    }
    // topic_messages() answers 404 and stops the whole request for a topic that isn't this
    // member's to read, so the question is asked here first, where it can simply be skipped.
    $topic = q('SELECT * FROM topics WHERE id = ?', [$id])->fetch();
    if (!$topic || !topic_visible($me, $topic)) {
        continue;
    }
    $page = topic_messages($me, $id, 'unread');
    $page['can_post'] = !$topic['is_closed'] || is_admin($user) || (int)$topic['created_by'] === $me;
    $out[(string)$id] = $page;
}

json_out(['topics' => $out]);
