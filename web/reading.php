<?php
// Today's reading: the day's passages, from the group's own schedule, as one continuous read.
// URLs: /bible/reading  (today) or /bible/reading/2026-09-19
require __DIR__ . '/boot.php';
require APP_DIR . '/lib/bible.php';
require APP_DIR . '/lib/reading.php';

$user = require_login();
if (!config('bible_reader') || !config('daily_reading') || !bible_versions()) {
    redirect('');
}

$date = (string)($_GET['d'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
    $date = gmdate('Y-m-d');
}
$version = bible_version_for((int)$user['id'], (string)($_GET['v'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    bible_reading_finished((int)$user['id'], $date);
    redirect('reading/' . $date . '?v=' . $version . '&done=1');
}

$passages = bible_reading_day($version, $date);
$place = bible_reading_place((int)$user['id'], $date);
$done = $place && $place['finished_at'] || isset($_GET['done']);
$pretty = date('l, j F Y', strtotime($date . ' 12:00 UTC'));
$topic = (int)setting('daily_reading_topic');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<script src="<?= asset('theme.js') ?>"></script>
<link rel="apple-touch-icon" href="<?= url(config('app_icons') . 'apple-touch-icon.png') ?>">
<link rel="icon" type="image/png" href="<?= url(config('app_icons') . 'favicon-32.png') ?>">
<title>Reading for <?= h($pretty) ?></title>
<link rel="stylesheet" href="<?= asset('app.css') ?>">
<link rel="stylesheet" href="<?= asset('bible.css') ?>">
</head>
<body class="bible-app" data-bible="<?= h(json_encode([
    'base' => url(), 'csrf' => csrf_token(), 'version' => $version, 'versions' => bible_versions(),
    'book' => $passages[0]['book'] ?? 'GEN', 'chapter' => $passages[0]['chapter'] ?? 1,
    'biblehub' => (bool)config('biblehub_links'),
    'settings' => user_pref((int)$user['id'], 'reader'), 'books' => [], 'reading' => $date,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
<header class="bar b-bar">
  <a class="bar-back" href="<?= url($topic ? 't/' . $topic : '') ?>" aria-label="Back">‹</a>
  <div class="b-where b-fixed"><span class="b-ref">Reading · <?= h(date('D., j M', strtotime($date . ' 12:00 UTC'))) ?></span></div>
  <button class="b-version"><?= h($version) ?></button>
  <details class="menu">
    <summary aria-label="Reading settings">⋮</summary>
    <nav>
      <button type="button" data-set="font">Font: <span data-val="font">Serif</span></button>
      <div class="text-size"><span>Size</span><button type="button" data-size="-1" aria-label="Smaller">A−</button><button type="button" data-size="1" aria-label="Larger">A+</button></div>
      <button type="button" data-set="theme">Background: <span data-val="theme">App</span></button>
      <button type="button" data-set="red">Red letters: <span data-val="red">Off</span></button>
      <button type="button" data-set="plain">Reading view: <span data-val="plain">Off</span></button>
      <a href="<?= url('reading/' . gmdate('Y-m-d', strtotime($date . ' -1 day'))) ?>?v=<?= h($version) ?>">The day before</a>
      <a href="<?= url('reading/' . gmdate('Y-m-d', strtotime($date . ' +1 day'))) ?>?v=<?= h($version) ?>">The day after</a>
      <a href="<?= url('bible.php') ?>">The whole Bible</a>
      <button type="button" data-copy-link>Copy link to this page</button>
    </nav>
  </details>
</header>

<main class="b-page" id="b-page">
  <article class="b-chapter" data-notes="[]" data-xrefs="[]" data-slug="">
    <h1 class="b-title">Reading for <?= h($pretty) ?></h1>
    <?php if (!$passages): ?>
      <p class="b-none">Nothing is scheduled for this day.</p>
    <?php else: ?>
      <?php foreach ($passages as $p): ?>
        <h2 class="b-ms"><?= h($p['ref']) ?></h2>
        <?= $p['html'] ?>
      <?php endforeach ?>
      <form method="post" class="b-finish">
        <?= csrf_field() ?>
        <?php if ($done): ?>
          <p class="b-doneline">✅ Finished<?= $topic ? ' · <a href="' . h(url('t/' . $topic)) . '">talk about it</a>' : '' ?></p>
        <?php else: ?>
          <button class="primary">I’ve finished today’s reading</button>
        <?php endif ?>
      </form>
    <?php endif ?>
  </article>
</main>
<script src="<?= asset('bible.js') ?>"></script>
</body>
</html>
