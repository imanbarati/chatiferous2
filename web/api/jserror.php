<?php
// A place for the app to report a script error it hit in someone's browser, so a "nothing happens
// when I tap" report can be looked into instead of guessed at. Kept in a log file, not the
// database, and capped: a member's browser can send at most one a minute.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';

$user = api_post();
$dir = APP_DIR . '/data';
$file = $dir . '/jserror.log';
if (is_file($file) && filesize($file) > 2 * 1024 * 1024) {
    @rename($file, $file . '.1');          // keep one older file, no more
}
$last = (int)@filemtime($dir . '/jserror.stamp.' . (int)$user['id']);
if (time() - $last < 60) {
    json_out(['ok' => true]);              // too soon: quietly ignored
}
@touch($dir . '/jserror.stamp.' . (int)$user['id']);

$line = json_encode([
    'at'    => gmdate('Y-m-d H:i:s'),
    'user'  => (int)$user['id'],
    'page'  => mb_substr((string)($_POST['page'] ?? ''), 0, 200),
    'msg'   => mb_substr((string)($_POST['msg'] ?? ''), 0, 300),
    'where' => mb_substr((string)($_POST['where'] ?? ''), 0, 300),
    'agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
json_out(['ok' => true]);
