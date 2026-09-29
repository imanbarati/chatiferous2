<?php
// The reader's tests sign in as a real member and read real chapters, which the app dutifully
// remembers: the settings (including the commentary's own size), the saved place in each version,
// and the Recent list. Left alone, a test run rearranges whoever it ran as.
//
//   php cli/reader_state.php save    <file> [user_id]   before a run
//   php cli/reader_state.php restore <file>             after it, however it ended
//
// Restore puts the three tables back exactly as they were — rows the run added are deleted, rows
// it changed are set back, rows it deleted return.
require __DIR__ . '/../lib/bootstrap.php';

$what = $argv[1] ?? '';
$file = $argv[2] ?? '';
if (!in_array($what, ['save', 'restore'], true) || $file === '') {
    fwrite(STDERR, "usage: reader_state.php save|restore <file> [user_id]\n");
    exit(2);
}

if ($what === 'save') {
    $id = (int)($argv[3] ?? 0) ?: (int)q('SELECT id FROM users WHERE role = "owner"')->fetchColumn();
    $state = [
        'user_id' => $id,
        'prefs'   => q('SELECT name, value FROM user_prefs WHERE user_id = ?', [$id])->fetchAll(PDO::FETCH_ASSOC),
        'state'   => q('SELECT version, book, chapter, verse FROM bible_state WHERE user_id = ?', [$id])->fetchAll(PDO::FETCH_ASSOC),
        'history' => q('SELECT version, book, chapter, seen_at FROM bible_history WHERE user_id = ?', [$id])->fetchAll(PDO::FETCH_ASSOC),
    ];
    file_put_contents($file, json_encode($state));
    chmod($file, 0600);
    printf("saved %d prefs, %d places, %d recent for user %d\n",
        count($state['prefs']), count($state['state']), count($state['history']), $id);
    exit;
}

$state = json_decode((string)file_get_contents($file), true);
if (!is_array($state) || empty($state['user_id'])) {
    fwrite(STDERR, "reader_state: nothing to restore from $file\n");
    exit(1);
}
$id = (int)$state['user_id'];

q('DELETE FROM user_prefs WHERE user_id = ?', [$id]);
foreach ($state['prefs'] as $p) {
    q('INSERT INTO user_prefs (user_id, name, value) VALUES (?, ?, ?)', [$id, $p['name'], $p['value']]);
}
q('DELETE FROM bible_state WHERE user_id = ?', [$id]);
foreach ($state['state'] as $r) {
    q('INSERT INTO bible_state (user_id, version, book, chapter, verse) VALUES (?, ?, ?, ?, ?)',
        [$id, $r['version'], $r['book'], $r['chapter'], $r['verse']]);
}
q('DELETE FROM bible_history WHERE user_id = ?', [$id]);
foreach ($state['history'] as $r) {
    q('INSERT INTO bible_history (user_id, version, book, chapter, seen_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $r['version'], $r['book'], $r['chapter'], $r['seen_at']]);
}
printf("restored %d prefs, %d places, %d recent for user %d\n",
    count($state['prefs']), count($state['state']), count($state['history']), $id);
