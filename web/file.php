<?php
// Serves an attachment to signed-in members. Files live outside the web folder.
require __DIR__ . '/boot.php';
require_once APP_DIR . '/lib/dm.php';

$user = current_user();
if (!$user || $user['password_hash'] === null) {
    http_response_code(403);
    exit;
}
session_write_close();

$a = q('SELECT a.*, t.kind AS topic_kind, t.dm_a, t.dm_b FROM attachments a JOIN messages m ON m.id = a.message_id JOIN topics t ON t.id = m.topic_id
        WHERE a.id = ? AND m.deleted_at IS NULL', [(int)($_GET['id'] ?? 0)])->fetch();
if ($a && !topic_visible((int)$user['id'], ['kind' => $a['topic_kind'], 'dm_a' => $a['dm_a'], 'dm_b' => $a['dm_b']])) {
    $a = false;   // someone else's direct message: as if it didn't exist
}
$root = realpath(config('uploads_dir'));
$path = $a && $a['path'] ? realpath($root . '/' . $a['path']) : false;
if (!$path || !str_starts_with($path, $root . '/') || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

$inline = in_array($a['kind'], ['photo', 'animation'], true) && in_array($a['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
    || in_array($a['kind'], ['video', 'animation'], true) && in_array($a['mime'], ['video/mp4', 'video/webm', 'video/quicktime'], true);
$name = $a['name'] !== '' ? $a['name'] : basename($path);
$size = filesize($path);   // (a DM's files are stored sealed; the app opens them)

// Byte ranges: video players (iPhones insist) fetch a video in pieces and seek within it.
$start = 0;
$end = $size - 1;
header('Accept-Ranges: bytes');
if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE'] ?? ''), $r) && ($r[1] !== '' || $r[2] !== '')) {
    if ($r[1] === '') {                       // "bytes=-500": the last 500 bytes
        $start = max(0, $size - (int)$r[2]);
    } else {
        $start = (int)$r[1];
        if ($r[2] !== '') {
            $end = min((int)$r[2], $size - 1);
        }
    }
    if ($start > $end) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header_remove('Pragma');
header('Cache-Control: private, max-age=604800, immutable');
// Anything not shown inline is a plain download: never served under its own type, so (with nosniff)
// an uploaded file can't be loaded by the page as a script or a stylesheet.
header('Content-Type: ' . ($inline ? $a['mime'] : 'application/octet-stream'));
header('Content-Length: ' . ($end - $start + 1));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name));
set_time_limit(0);
$fp = fopen($path, 'rb');
fseek($fp, $start);
for ($left = $end - $start + 1; $left > 0 && !feof($fp); $left -= 262144) {
    echo fread($fp, (int)min(262144, $left));
    flush();
}
fclose($fp);
