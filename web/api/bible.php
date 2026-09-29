<?php
// The reader's data: a chapter (so swiping on doesn't reload the page) and where someone was
// reading.
//   GET  ?v=WEB&b=JHN&c=3          a chapter with its notes
//   POST v, b, c, verse            remember this place
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';
require APP_DIR . '/lib/bible.php';

if (!config('bible_reader')) {
    json_out(['error' => 'The Bible reader is switched off.'], 404);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = api_post();
    if (($_POST['action'] ?? '') === 'prefs') {
        $settings = json_decode((string)($_POST['settings'] ?? ''), true);
        if (!is_array($settings) || count($settings) > 20) {
            json_out(['error' => 'Those settings look wrong.'], 400);
        }
        set_user_pref((int)$user['id'], 'reader', $settings);
        json_out(['ok' => true]);
    }
    $version = bible_version_ok((string)($_POST['v'] ?? ''));
    $code = strtoupper((string)($_POST['b'] ?? ''));
    if (!bible_book($version, $code)) {
        json_out(['error' => 'No such book.'], 400);
    }
    bible_save_place((int)$user['id'], $version, $code, (int)($_POST['c'] ?? 1), (int)($_POST['verse'] ?? 1));
    json_out(['ok' => true, 'recent' => bible_recent((int)$user['id'])]);
}

$user = api_user();
$version = bible_version_ok((string)($_GET['v'] ?? ''));

// One book at a time, for a version being downloaded to a device.
if (($_GET['action'] ?? '') === 'book') {
    $bundle = bible_book_bundle($version, strtoupper((string)($_GET['b'] ?? '')));
    if (!$bundle) {
        json_out(['error' => 'No such book.'], 404);
    }
    json_out($bundle);
}

// Search: references, phrases and words.
if (($_GET['action'] ?? '') === 'search') {
    json_out(bible_search($version, (string)($_GET['q'] ?? ''), (string)($_GET['scope'] ?? 'all')));
}

// The cross-references for one verse, for the ✦ dialog.
if (($_GET['action'] ?? '') === 'xrefs') {
    $code = strtoupper((string)($_GET['b'] ?? ''));
    if (!bible_book($version, $code)) {
        json_out(['error' => 'No such book.'], 404);
    }
    json_out(['refs' => bible_xrefs($version, $code, (int)($_GET['c'] ?? 0), (int)($_GET['verse'] ?? 0))]);
}
$ch = bible_chapter($version, strtoupper((string)($_GET['b'] ?? '')), (int)($_GET['c'] ?? 0));
if (!$ch) {
    json_out(['error' => 'That chapter doesn’t exist.'], 404);
}
json_out($ch);
