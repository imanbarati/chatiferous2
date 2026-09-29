<?php

function page_start(string $title, array $opts = []): void
{
    $user = current_user();
    $group = config('group_name');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#ffffff">
<script src="<?= asset('theme.js') ?>"></script>
<script src="<?= asset('pw-handoff.js') ?>"></script>
<script src="<?= asset('logout.js') ?>"></script>
<link rel="manifest" href="<?= url('manifest.php') ?>">
<link rel="apple-touch-icon" href="<?= url(config('app_icons') . 'apple-touch-icon.png') ?>">
<link rel="icon" type="image/png" href="<?= url(config('app_icons') . 'favicon-32.png') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h(config('short_name')) ?>">
<title><?= h($title === $group ? $group : "$title · $group") ?></title>
<link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body class="<?= h($opts['body_class'] ?? '') ?>">
<?php if (empty($opts['bare'])): ?>
<header class="bar">
  <?php if (array_key_exists('back', $opts)): ?>
    <a class="bar-back" href="<?= url($opts['back']) ?>" aria-label="Back">‹</a>
  <?php endif ?>
  <div class="bar-title">
    <div class="bar-name"><?= h($opts['heading'] ?? $group) ?></div>
    <?php if (!empty($opts['sub'])): ?><div class="bar-sub"><?= h($opts['sub']) ?></div><?php endif ?>
  </div>
  <?php if ($user && $user['password_hash'] !== null): ?>
  <details class="menu">
    <summary aria-label="Menu">⋮</summary>
    <nav>
      <a href="<?= url('account.php') ?>">My account</a>
      <a href="<?= url('notifications.php') ?>">Notifications</a>
      <a href="<?= url('invite.php') ?>">Invite someone</a>
      <?php if (config('bible_reader')): ?><a href="<?= url('bible.php') ?>">Read the Bible</a><?php endif ?>
      <a href="<?= url('install.php') ?>">Install on your phone</a>
      <button type="button" data-theme-toggle>Appearance: <span data-theme-label>Auto</span></button>
      <button type="button" data-copy-link>Copy link to this page</button>
      <button type="button" data-refresh>Refresh</button>
      <div class="text-size"><span>Text size</span><button type="button" data-text-size="-1" aria-label="Smaller text">A−</button><button type="button" data-text-size="1" aria-label="Larger text">A+</button></div>
      <?php if (is_admin($user)): ?><a href="<?= url('admin/members.php') ?>">Members</a><?php if (config('daily_reading')): ?><a href="<?= url('admin/reading.php') ?>">Daily reading</a><?php endif ?><?php endif ?>
      <form method="post" action="<?= url('logout.php') ?>"><?= csrf_field() ?><button>Log out</button></form>
    </nav>
  </details>
  <?php endif ?>
</header>
<?php endif ?>
<main class="<?= h($opts['main_class'] ?? 'page') ?>">
<?php
}

function page_end(): void
{
    echo "</main>\n</body>\n</html>\n";
}

function notice(?string $msg, string $kind = 'ok'): void
{
    if ($msg) {
        echo '<p class="notice ' . h($kind) . '" role="status">' . h($msg) . '</p>';
    }
}

function avatar_url(array $user): ?string
{
    return $user['avatar_path'] ? url('avatar.php?u=' . (int)$user['id'] . '&v=' . (int)$user['avatar_version']) : null;
}

// Round avatar: the member's photo, or initials in their Telegram-style color.
function avatar(string $name, int $color_index, string $size = '', ?string $photo = null): string
{
    if ($photo) {
        return '<img class="avatar ' . h($size) . '" src="' . h($photo) . '" alt="">';
    }
    $words = array_values(array_filter(array_map(fn($w) => preg_replace('/^[^\p{L}\p{N}]+/u', '', $w), preg_split('/\s+/u', trim($name)) ?: []), 'strlen'));
    $initials = '';
    foreach (array_slice($words, 0, 2) as $w) {
        $initials .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    return '<span class="avatar c' . ($color_index % 7) . ' ' . h($size) . '" aria-hidden="true">'
        . h($initials ?: '?') . '</span>';
}
