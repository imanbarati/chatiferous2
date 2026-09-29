<?php
// A throwaway member for the reader tests, so they never read, mark or re-set anything as a real
// person. Reading a chapter is remembered — the place in each version, the Recent list, the
// settings — and a test that does it as the owner rearranges the owner's reading. Restoring a
// snapshot afterwards is worse: it erases whatever they read while the run was going.
//
//   php cli/test_reader_account.php make    <session-id> <csrf>   prints the user id
//   php cli/test_reader_account.php remove  <session-id>
require __DIR__ . '/../lib/bootstrap.php';

const TEST_READER = 'autotestreader';

$what = $argv[1] ?? '';
$sid = $argv[2] ?? '';
if (!in_array($what, ['make', 'remove'], true) || !preg_match('/^[a-f0-9]{16,64}$/', $sid)) {
    fwrite(STDERR, "usage: test_reader_account.php make|remove <session-id> [csrf]\n");
    exit(2);
}

$id = (int)q('SELECT id FROM users WHERE username = ?', [TEST_READER])->fetchColumn();

if ($what === 'make') {
    if ($id) {
        // It was left behind last time because its swept posts still point at it: wake it up.
        q('UPDATE users SET status = "active" WHERE id = ?', [$id]);
    }
    if (!$id) {
        q('INSERT INTO users (display_name, username, role, status, color_index, claimed_at, password_hash)
           VALUES (?, ?, "member", "active", 3, UTC_TIMESTAMP(), ?)',
            ['Automated Test Reader', TEST_READER, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT)]);
        $id = (int)db()->lastInsertId();
    }
    $file = APP_DIR . '/sessions/sess_' . $sid;
    file_put_contents($file, sprintf('user_id|i:%d;csrf|s:32:"%s";', $id, (string)($argv[3] ?? '')));
    chmod($file, 0600);
    echo $id, PHP_EOL;
    exit;
}

@unlink(APP_DIR . '/sessions/sess_' . $sid);
if (!$id) {
    exit;
}
// Everything it read, marked or set goes, which is the point of the account.
foreach (['bible_state', 'bible_history', 'bible_marks', 'user_prefs', 'read_state', 'user_keys'] as $t) {
    try {
        q("DELETE FROM $t WHERE user_id = ?", [$id]);
    } catch (Throwable $e) {
        // That table may not exist in every deployment; the reading is what matters.
    }
}
// The reader tests also quote a verse into the chat, and a message can't be taken out from under
// the things that point at it. Those posts are marked as tests and swept like any other; the
// account stays until they are gone, rather than the clean-up failing and leaving the session.
// A message can't be taken out from under the things that point at it, and the reader tests quote
// a verse into the chat. Where those swept posts remain, the account stays with them — but marked
// deleted, so it is no longer one of the group's members.
$posts = (int)q('SELECT COUNT(*) FROM messages WHERE user_id = ?', [$id])->fetchColumn();
if ($posts > 0) {
    q('UPDATE users SET status = "deleted" WHERE id = ?', [$id]);
    echo "test reader $id retired: $posts swept post(s) still reference it", PHP_EOL;
    exit;
}
q('DELETE FROM users WHERE id = ?', [$id]);
echo "removed test reader $id", PHP_EOL;
