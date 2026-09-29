<?php
// Names on polls imported from Telegram (see MIGRATING-FROM-TELEGRAM.md). The export only counted votes; tools/fetch_poll_voters.py
// asked Telegram who voted for what. Each vote becomes a real, changeable vote here, and leaves the
// option's anonymous "imported" count, so totals don't change. Anyone who has already voted again
// here keeps that vote; their Telegram vote just leaves the count (it would be counted twice).
// Polls that were anonymous on Telegram are marked anonymous here too.
//
// Input (in data/): poll_voters.jsonl ({"msg", "pos": [..], "user"} per line, Telegram ids) and
// poll_anonymous.txt (Telegram message ids, one per line; optional). Runs once: it leaves data/poll_voters.imported.
// --dry does everything, reports, then rolls it all back.
require __DIR__ . '/../lib/bootstrap.php';

$dir = APP_DIR . '/data';
$dry = in_array('--dry', $argv, true);
if (is_file("$dir/poll_voters.imported")) {
    exit("Already imported (data/poll_voters.imported exists).\n");
}
$msg = [];      // telegram message id => poll message id here
foreach (q("SELECT telegram_id, id FROM messages WHERE kind = 'poll' AND telegram_id IS NOT NULL")->fetchAll() as $r) {
    $msg[(int)$r['telegram_id']] = (int)$r['id'];
}
$usr = [];      // telegram user id => user id here
foreach (q('SELECT telegram_id, id FROM users WHERE telegram_id IS NOT NULL')->fetchAll() as $r) {
    $usr[(int)$r['telegram_id']] = (int)$r['id'];
}
$opt = [];      // "message id:position" => option id
foreach (q('SELECT o.id, o.message_id, o.position FROM poll_options o JOIN polls p ON p.message_id = o.message_id WHERE p.imported = 1')->fetchAll() as $r) {
    $opt[$r['message_id'] . ':' . $r['position']] = (int)$r['id'];
}

$n = ['votes' => 0, 'already_voted_here' => 0, 'unknown_user' => 0, 'unknown_poll' => 0, 'anonymous' => 0];
db()->beginTransaction();
foreach (file("$dir/poll_voters.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $v = json_decode($line, true);
    $mid = $msg[(int)$v['msg']] ?? null;
    $uid = $usr[(int)$v['user']] ?? null;
    if (!$mid) { $n['unknown_poll']++; continue; }
    if (!$uid) { $n['unknown_user']++; continue; }        // not a member here: stays in the count
    $opts = array_values(array_filter(array_map(fn($p) => $opt["$mid:$p"] ?? null, $v['pos'])));
    if (!$opts) { $n['unknown_poll']++; continue; }
    $here = (bool)q('SELECT 1 FROM poll_votes WHERE message_id = ? AND user_id = ?', [$mid, $uid])->fetch();
    foreach ($opts as $o) {
        if (!$here) {
            q('INSERT INTO poll_votes (option_id, user_id, message_id, created_at) SELECT ?, ?, ?, created_at FROM messages WHERE id = ?',
                [$o, $uid, $mid, $mid]);
        }
        q('UPDATE poll_options SET imported_votes = GREATEST(imported_votes - 1, 0) WHERE id = ?', [$o]);
    }
    if (count($opts) > 1) {
        q('UPDATE polls SET multiple = 1 WHERE message_id = ?', [$mid]);
    }
    $here ? $n['already_voted_here']++ : $n['votes']++;
}
foreach (is_file("$dir/poll_anonymous.txt") ? file("$dir/poll_anonymous.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $tg) {
    if (isset($msg[(int)$tg])) {
        $n['anonymous'] += q('UPDATE polls SET is_anonymous = 1 WHERE message_id = ?', [$msg[(int)$tg]])->rowCount();
    }
}
if ($dry) {
    db()->rollBack();
    echo 'DRY RUN (nothing kept): ';
} else {
    db()->commit();
    touch("$dir/poll_voters.imported");
}
echo json_encode($n), "\n";
