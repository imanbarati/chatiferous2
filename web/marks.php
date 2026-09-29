<?php
// My marks: everything a member has highlighted, bookmarked or written a note on, in book order,
// with a way to take the lot away as a file and to put it back again.
//   /bible/marks  ·  ?kind=note  ·  ?export=md|json
require __DIR__ . '/boot.php';
require APP_DIR . '/lib/bible.php';
require APP_DIR . '/lib/bible_marks.php';

$user = require_login();
if (!config('bible_reader') || !bible_versions()) {
    redirect('');
}
$version = bible_version_for((int)$user['id'], (string)($_GET['v'] ?? ''));

// Putting an exported file back.
$added = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $json = '';
    if (isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $json = (string)file_get_contents($_FILES['file']['tmp_name'], false, null, 0, 4 << 20);
    }
    redirect('marks?added=' . bible_marks_import((int)$user['id'], $json));
}

// Taking it away: Markdown to read, JSON to move.
$export = (string)($_GET['export'] ?? '');
if ($export === 'md' || $export === 'json') {
    $body = $export === 'md' ? bible_marks_markdown((int)$user['id'], $version) : bible_marks_json((int)$user['id']);
    header('Content-Type: ' . ($export === 'md' ? 'text/markdown' : 'application/json') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="my-bible-marks-' . gmdate('Y-m-d') . '.' . $export . '"');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

$kind = (string)($_GET['kind'] ?? '');
$marks = bible_marks_ordered((int)$user['id'], in_array($kind, ['highlight', 'bookmark', 'note'], true) ? $kind : '');
$counts = ['highlight' => 0, 'bookmark' => 0, 'note' => 0];
foreach (bible_marks((int)$user['id']) as $m) {
    $counts[$m['kind']]++;
}
$tab = fn($k, $label) => '<a class="b-tab' . ($kind === $k ? ' on' : '') . '" href="' . h(url('marks')) . ($k ? '?kind=' . $k : '') . '">' . h($label) . '</a>';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<script src="<?= asset('theme.js') ?>"></script>
<link rel="apple-touch-icon" href="<?= url(config('app_icons') . 'apple-touch-icon.png') ?>">
<link rel="icon" type="image/png" href="<?= url(config('app_icons') . 'favicon-32.png') ?>">
<title>My marks</title>
<link rel="stylesheet" href="<?= asset('app.css') ?>">
<link rel="stylesheet" href="<?= asset('bible.css') ?>">
</head>
<body class="bible-app b-marks-page">
<header class="bar b-bar">
  <a class="bar-back" href="<?= url('bible.php') ?>" aria-label="Back to the reader">‹</a>
  <div class="b-where b-fixed"><span class="b-ref">My marks</span></div>
  <details class="menu">
    <summary aria-label="More">⋮</summary>
    <nav>
      <a href="<?= url('marks') ?>?export=md&amp;v=<?= h($version) ?>" download>Export as Markdown</a>
      <a href="<?= url('marks') ?>?export=json" download>Export as JSON</a>
      <button type="button" class="b-import-open">Import a file…</button>
      <a href="<?= url('bible.php') ?>">Back to the reader</a>
    </nav>
  </details>
</header>

<main class="b-page b-marks">
  <nav class="b-tabs" aria-label="Which marks">
    <?= $tab('', 'All') ?>
    <?= $tab('highlight', 'Highlights (' . $counts['highlight'] . ')') ?>
    <?= $tab('bookmark', 'Bookmarks (' . $counts['bookmark'] . ')') ?>
    <?= $tab('note', 'Notes (' . $counts['note'] . ')') ?>
  </nav>

  <?php if (isset($_GET['added'])): ?>
    <p class="b-imported"><?= (int)$_GET['added'] ?> mark<?= (int)$_GET['added'] === 1 ? '' : 's' ?> added.</p>
  <?php endif ?>

  <form class="b-import" method="post" enctype="multipart/form-data" hidden>
    <?= csrf_field() ?>
    <label>Choose an exported <code>.json</code> file<input type="file" name="file" accept="application/json,.json"></label>
    <button class="primary">Import</button>
  </form>

  <?php if (!$marks): ?>
    <p class="b-none">Nothing marked yet. Hold a verse while you’re reading to highlight it, bookmark
      it, or write a note on it — they’re kept in your account and only you can see them.</p>
  <?php else: ?>
    <?php $book = ''; foreach ($marks as $m):
        if ($m['name'] !== $book) {
            $book = $m['name'];
            echo '<h2 class="b-ms">' . h($book) . '</h2>';
        }
        $text = bible_mark_text($version, $m);
    ?>
      <article class="b-mark b-kind-<?= h($m['kind']) ?><?= $m['colour'] ? ' b-c-' . h($m['colour']) : '' ?>">
        <a class="b-mark-ref" href="<?= url('read/' . $m['book'] . '/' . $m['chapter']) ?>?v=<?= h($version) ?>#v<?= (int)$m['verse'] ?>">
          <?= h($m['ref']) ?>
        </a>
        <?php if ($text !== ''): ?><p class="b-mark-text"><?= h($text) ?></p><?php endif ?>
        <?php if ($m['body'] !== ''): ?><p class="b-mark-note"><?= nl2br(h($m['body'])) ?></p><?php endif ?>
        <p class="b-mark-when"><?= h(['highlight' => 'Highlight', 'bookmark' => 'Bookmark', 'note' => 'Note'][$m['kind']]) ?>
          · <?= h(gmdate('j M Y', $m['created_at'])) ?></p>
      </article>
    <?php endforeach ?>
    <p class="b-marks-note">Your marks are kept in your account, not on this device, and nobody else
      is shown them. They are ordinary rows in the database, so whoever runs this site could read
      them; they aren’t sealed the way direct messages are.</p>
  <?php endif ?>
</main>
<script src="<?= asset('marks.js') ?>"></script>
</body>
</html>
