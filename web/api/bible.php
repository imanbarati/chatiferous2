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
    // v may name several translations, and scope may be a list of books chosen by hand.
    $vs = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['vs'] ?? $version)))));
    $scope = (string)($_GET['scope'] ?? 'all');
    if (str_contains($scope, ',') || (strlen($scope) === 3 && $scope !== 'all')) {
        $books = array_values(array_filter(array_map(fn($c) => strtoupper(trim($c)), explode(',', $scope))));
        $scope = count($books) === 1 && in_array($books[0], ['ALL'], true) ? 'all' : $books;
    }
    json_out(bible_search($vs ?: [$version], (string)($_GET['q'] ?? ''), $scope));
}

// The words of some cross-referenced verses, as the translation being read words them. Only for
// the fetched translations: for the ones we hold, the list already quotes the right text.
if (($_GET['action'] ?? '') === 'xreftext') {
    $refs = array_slice(array_filter(explode(',', (string)($_GET['refs'] ?? ''))), 0, 24);
    json_out(['texts' => api_bible_is($version) ? api_bible_verses($version, $refs) : []]);
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
