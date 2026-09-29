<?php
// The app: topic list, and the open topic (URLs <base>/ and <base>/t/<topic>[/<message>]).
require __DIR__ . '/boot.php';

$user = require_login();
$members = (int)q("SELECT COUNT(*) FROM users WHERE role <> 'system' AND status IN ('active','unclaimed')")->fetchColumn();
$group = config('group_name');
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
<title><?= h($group) ?></title>
<link rel="stylesheet" href="<?= asset('app.css') ?>">
<link rel="stylesheet" href="<?= asset('chat.css') ?>">
</head>
<body class="chat-app<?= config('wallpaper') ? ' wallpaper' : '' ?>" data-app="<?= h(json_encode([
    'base'  => url(),
    'csrf'  => csrf_token(),
    'me'    => (int)$user['id'],
    'admin' => is_admin($user),
    'membersCreateTopics' => false,
    'group' => $group,
    'short' => config('short_name'),
    'gifs'  => (bool)config('klipy_key'),
    'telegram' => telegram_login_enabled(),
    'signinNote' => (string)config('signin_note'),
    'bible' => (bool)config('bible_reader'),
    'icons' => asset('topic-icons.svg'),
    'version' => app_version(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
<svg width="0" height="0" class="defs" aria-hidden="true">
  <defs>
    <?php foreach ([['#4bb7ff', '#015ec1'], ['#ffdb5c', '#ea5800'], ['#e57aff', '#a438bb'], ['#97e334', '#11b411'], ['#ff7999', '#e4215a'], ['#ff714c', '#c61505']] as $i => [$a, $b]): ?>
    <linearGradient id="tc<?= $i ?>" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="<?= $a ?>"/><stop offset="1" stop-color="<?= $b ?>"/></linearGradient>
    <?php endforeach ?>
  </defs>
</svg>
<div class="app">
  <section class="pane-list" aria-label="Topics">
    <header class="bar">
      <div class="group-avatar sm" aria-hidden="true"><?= h(config('group_emoji')) ?></div>
      <div class="bar-title">
        <div class="bar-name"><?= h($group) ?></div>
        <div class="bar-sub"><?= $members ?> members</div>
      </div>
      <?php if (config('bible_reader')): ?>
      <a class="bar-icon" href="<?= url('bible.php') ?>" aria-label="Read the Bible" title="Read the Bible">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 6.4C10.6 5.2 8.7 4.6 6.2 4.6c-.9 0-1.7.1-2.4.2v12.6c.7-.1 1.5-.2 2.4-.2 2.5 0 4.4.6 5.8 1.8 1.4-1.2 3.3-1.8 5.8-1.8.9 0 1.7.1 2.4.2V4.8c-.7-.1-1.5-.2-2.4-.2-2.5 0-4.4.6-5.8 1.8z"/>
          <path d="M12 6.4v12.6"/>
        </svg>
      </a>
      <?php endif ?>
      <button class="search-open" aria-label="Search messages">🔍</button>
      <details class="menu">
        <summary aria-label="Menu">⋮</summary>
        <nav>
          <a href="<?= url('account.php') ?>">My account</a>
          <a href="<?= url('notifications.php') ?>">Notifications</a>
          <a href="<?= url('invite.php') ?>">Invite someone</a>
          <?php if (config('bible_reader') && config('daily_reading')): ?><a href="<?= url('reading') ?>">Today’s reading</a><?php endif ?>
          <a href="<?= url('install.php') ?>">Install on your phone</a>
          <button type="button" data-theme-toggle>Appearance: <span data-theme-label>Auto</span></button>
          <button type="button" data-sound-toggle>Sounds: <span data-sound-label>On</span></button>
          <button type="button" data-new-code>Message recovery code</button>
          <button type="button" data-copy-link>Copy link to this page</button>
          <button type="button" data-refresh>Refresh</button>
          <div class="text-size"><span>Text size</span><button type="button" data-text-size="-1" aria-label="Smaller text">A−</button><button type="button" data-text-size="1" aria-label="Larger text">A+</button></div>
          <?php if (is_admin($user)): ?><a href="<?= url('admin/members.php') ?>">Members</a><?php if (config('daily_reading')): ?><a href="<?= url('admin/reading.php') ?>">Daily reading</a><?php endif ?><?php endif ?>
          <form method="post" action="<?= url('logout.php') ?>"><?= csrf_field() ?><button>Log out</button></form>
        </nav>
      </details>
    </header>
    <div id="topics" class="topics"><p class="loading">Loading…</p></div>
  </section>
  <section class="pane-chat" id="chat" aria-live="polite">
    <div class="chat-empty"><span>Select a topic</span></div>
  </section>
</div>
<div id="viewer" class="viewer" hidden><button class="viewer-close" aria-label="Close">✕</button><img alt=""></div>
<script src="<?= asset('logout.js') ?>"></script>
<script src="<?= asset('e2e.js') ?>"></script>
<script src="<?= asset('compose.js') ?>"></script>
<script src="<?= asset('chat.js') ?>"></script>
</body>
</html>
