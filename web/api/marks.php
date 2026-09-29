<?php
// A member's own marks in the Bible.
//   GET                          every mark, for the reader and the My marks screen
//   POST kind, book, c, verse…   mark (or re-mark) verses
//   POST action=clear            take a mark off again
//   POST action=delete, id       remove one mark
//   POST action=import, file     put an exported file back
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';
require APP_DIR . '/lib/bible.php';
require APP_DIR . '/lib/bible_marks.php';

if (!config('bible_reader')) {
    json_out(['error' => 'The Bible reader is switched off.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = api_post();
    $action = (string)($_POST['action'] ?? 'set');

    if ($action === 'delete') {
        json_out(['ok' => bible_mark_delete((int)$user['id'], (int)($_POST['id'] ?? 0))]);
    }

    if ($action === 'clear') {
        $book = strtoupper((string)($_POST['book'] ?? ''));
        $verse = (int)($_POST['verse'] ?? 0);
        $gone = bible_mark_clear((int)$user['id'], (string)($_POST['kind'] ?? ''), $book,
            (int)($_POST['chapter'] ?? 0), $verse, max($verse, (int)($_POST['end_verse'] ?? $verse)));
        json_out(['ok' => true, 'removed' => $gone]);
    }

    if ($action === 'import') {
        $json = (string)($_POST['json'] ?? '');
        if (isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $json = (string)file_get_contents($_FILES['file']['tmp_name'], false, null, 0, 4 << 20);
        }
        json_out(['ok' => true, 'added' => bible_marks_import((int)$user['id'], $json)]);
    }

    $mark = bible_mark_set((int)$user['id'], [
        'kind' => (string)($_POST['kind'] ?? ''),
        'book' => (string)($_POST['book'] ?? ''),
        'chapter' => (int)($_POST['chapter'] ?? 0),
        'verse' => (int)($_POST['verse'] ?? 0),
        'end_verse' => (int)($_POST['end_verse'] ?? 0),
        'color' => (string)($_POST['color'] ?? ''),
        'body' => (string)($_POST['body'] ?? ''),
        'version' => (string)($_POST['version'] ?? ''),
    ]);
    if (!$mark) {
        json_out(['error' => 'That mark doesn’t make sense.'], 400);
    }
    json_out(['ok' => true, 'mark' => $mark]);
}

$user = api_user();
json_out(['marks' => bible_marks((int)$user['id'])]);
