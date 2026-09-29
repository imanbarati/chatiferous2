<?php
// The Bible reader. URLs: /bible/read/<BOOK>/<chapter>[?v=WEB], or with no book, wherever the
// reader was last. The chapter is rendered here; assets/bible.js takes over for swiping to the
// next chapter, footnotes, and the settings.
require __DIR__ . '/boot.php';
require APP_DIR . '/lib/bible.php';

$user = require_login();
if (!config('bible_reader') || !bible_versions()) {
    redirect('');
}

$version = bible_version_for((int)$user['id'], (string)($_GET['v'] ?? ''));
$code = strtoupper((string)($_GET['b'] ?? ''));
$chapter = (int)($_GET['c'] ?? 0);

// No book asked for: carry on where this person left off, or start at John.
if (!$code) {
    $place = bible_place((int)$user['id'], $version);
    $code = $place['book'] ?? 'JHN';
    $chapter = (int)($place['chapter'] ?? 1);
}
$ch = bible_chapter($version, $code, $chapter ?: 1) ?: bible_chapter($version, 'JHN', 1);
if (!$ch) {
    redirect('');
}
bible_save_place((int)$user['id'], $version, $ch['book'], $ch['chapter']);

$books = bible_books($version);
$app = [
    'base'     => url(),
    'csrf'     => csrf_token(),
    'version'  => $version,
    'versions' => bible_versions(),
    // The ones read from API.Bible a chapter at a time: they can't be kept on the device.
    'fetched'  => api_bible_versions(),
    'book'     => $ch['book'],
    'chapter'  => $ch['chapter'],
    'biblehub' => (bool)config('biblehub_links'),
    'settings' => user_pref((int)$user['id'], 'reader'),
    'recent'   => bible_recent((int)$user['id']),
    'books'    => array_map(fn($b) => [
        'code' => $b['code'], 'name' => $b['name'], 'abbrev' => $b['abbrev'],
        'chapters' => (int)$b['chapters'], 'testament' => $b['testament'],
    ], $books),
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#ffffff">
<script src="<?= asset('theme.js') ?>"></script>
<link rel="manifest" href="<?= url('manifest.php') ?>">
<link rel="apple-touch-icon" href="<?= url(config('app_icons') . 'apple-touch-icon.png') ?>">
<link rel="icon" type="image/png" href="<?= url(config('app_icons') . 'favicon-32.png') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h(config('short_name')) ?>">
<title><?= h($ch['name'] . ' ' . $ch['chapter']) ?></title>
<link rel="stylesheet" href="<?= asset('app.css') ?>">
<link rel="stylesheet" href="<?= asset('bible.css') ?>">
</head>
<body class="bible-app" data-bible="<?= h(json_encode($app, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
<header class="bar b-bar">
  <a class="bar-back" href="<?= url() ?>" aria-label="Back to the chat">‹</a>
  <button class="b-where" aria-label="Choose a book"><span class="b-ref"><?= h($ch['name'] . ' ' . $ch['chapter']) ?></span> ▾</button>
  <button class="b-search" aria-label="Search the Bible">🔍</button>
  <button class="b-version"><?= h($version) ?></button>
  <details class="menu">
    <summary aria-label="Reading settings">⋮</summary>
    <nav>
      <button type="button" data-set="font">Font: <span data-val="font">Serif</span></button>
      <div class="text-size"><span>Size</span><button type="button" data-size="-1" aria-label="Smaller">A−</button><button type="button" data-size="1" aria-label="Larger">A+</button></div>
      <button type="button" data-set="theme">Background: <span data-val="theme">App</span></button>
      <button type="button" data-set="red">Red letters: <span data-val="red">Off</span></button>
      <button type="button" data-set="numbers">Verse numbers: <span data-val="numbers">On</span></button>
      <button type="button" data-set="notes">Footnotes: <span data-val="notes">Markers</span></button>
      <button type="button" data-set="xrefs">Cross-references: <span data-val="xrefs">On</span></button>
      <button type="button" data-set="commfull">Commentary: <span data-val="commfull">With the text</span></button>
      <button type="button" data-set="plain">Reading view: <span data-val="plain">Off</span></button>
      <button type="button" data-offline>Read offline: <span data-val="offline">Off</span></button>
      <a href="<?= url('marks') ?>">My marks</a>
      <?php if (config('daily_reading')): ?><a href="<?= url('reading') ?>">Today’s reading</a><?php endif ?>
      <button type="button" data-copy-link>Copy link to this page</button>
      <a href="<?= url() ?>">Back to the chat</a>
    </nav>
  </details>
</header>

<main class="b-page" id="b-page">
  <article class="b-chapter" data-book="<?= h($ch['book']) ?>" data-chapter="<?= (int)$ch['chapter'] ?>"
           data-slug="<?= h($ch['biblehub']) ?>" data-notes="<?= h(json_encode($ch['notes'], JSON_UNESCAPED_UNICODE)) ?>"
           data-xrefs="<?= h(json_encode($ch['xrefs'])) ?>">
    <h1 class="b-title"><?= h($ch['name']) ?> <?= (int)$ch['chapter'] ?></h1>
    <?= $ch['html'] ?>
    <?php if (!empty($ch['notice'])): ?>
      <p class="b-notice"><?= h($ch['notice']) ?></p>
    <?php endif ?>
  </article>
</main>

<nav class="b-steps" aria-label="Chapters">
  <?php if ($ch['prev']): ?><a class="b-prev" data-go="<?= h($ch['prev']['book'] . ':' . $ch['prev']['chapter']) ?>" href="<?= url('read/' . $ch['prev']['book'] . '/' . $ch['prev']['chapter']) ?>?v=<?= h($version) ?>">‹ <?= h($ch['prev']['name'] . ' ' . $ch['prev']['chapter']) ?></a><?php else: ?><span></span><?php endif ?>
  <?php if ($ch['next']): ?><a class="b-next" data-go="<?= h($ch['next']['book'] . ':' . $ch['next']['chapter']) ?>" href="<?= url('read/' . $ch['next']['book'] . '/' . $ch['next']['chapter']) ?>?v=<?= h($version) ?>"><?= h($ch['next']['name'] . ' ' . $ch['next']['chapter']) ?> ›</a><?php else: ?><span></span><?php endif ?>
</nav>

<script src="<?= asset('bible.js') ?>"></script>
</body>
</html>
