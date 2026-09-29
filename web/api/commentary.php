<?php
// What the commentaries say about a verse, and which of them someone wants to see.
//   GET  ?b=JHN&c=3&verse=16     the chosen works on that verse, and the list to choose from
//   POST works=<json array>      remember which works to show
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';
require APP_DIR . '/lib/bible.php';
require APP_DIR . '/lib/commentary.php';

if (!config('bible_reader') || config('commentaries') === false) {
    json_out(['error' => 'The commentaries are switched off.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = api_post();
    $codes = json_decode((string)($_POST['works'] ?? ''), true);
    if (!is_array($codes)) {
        json_out(['error' => 'That list looks wrong.'], 400);
    }
    json_out(['ok' => true, 'chosen' => commentary_set_chosen((int)$user['id'], $codes)]);
}

$user = api_user();
$book = strtoupper((string)($_GET['b'] ?? ''));
$chapter = (int)($_GET['c'] ?? 0);
$verse = (int)($_GET['verse'] ?? 0);
// verse=0 asks for the whole chapter at once, which is how the reader now fetches it.
if (!isset(BIBLE_CANON[$book]) || $chapter < 1 || $verse < 0) {
    json_out(['error' => 'No such verse.'], 400);
}

$chosen = commentary_chosen((int)$user['id']);
$have = commentary_have($book, $chapter);

// A whole chapter is normally a sensible size — three works, the usual choice, come to about
// 160 KB — and one request per chapter beats one per verse for everybody. But somebody who turns
// on a dozen works would be fetching megabytes every time they tapped a verse number, so past a
// budget the chapter is declined and the reader goes back to asking verse by verse.
const COMMENTARY_CHAPTER_BUDGET = 500 * 1024;
$entries = commentary_for($book, $chapter, $verse, $chosen);
$partial = false;
if ($verse === 0 && strlen(json_encode($entries)) > COMMENTARY_CHAPTER_BUDGET) {
    $entries = [];
    $partial = true;
}

json_out([
    'book'    => $book,
    'chapter' => $chapter,
    'verse'   => $verse,
    'chosen'  => $chosen,
    'entries' => $entries,
    // True when the chapter was too big to send whole: ask for the verses one at a time.
    'partial' => $partial,
    // The whole catalog, each marked with whether this chapter has it, so the chooser can be
    // honest about what's there before anyone taps it.
    'works'   => array_map(fn($w) => [
        'code' => $w['code'], 'name' => $w['name'], 'edition' => $w['edition'],
        'years' => $w['years'], 'here' => in_array($w['code'], $have, true),
    ], commentary_works()),
]);
