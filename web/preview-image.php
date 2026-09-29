<?php
// A link preview's image (copied from the linked site), for signed-in members only.
require __DIR__ . '/boot.php';

$user = current_user();
if (!$user || $user['password_hash'] === null) {
    http_response_code(403);
    exit;
}
session_write_close();
$hash = (string)($_GET['h'] ?? '');
$path = preg_match('/^[0-9a-f]{64}$/', $hash) ? q('SELECT image_path FROM link_previews WHERE url_hash = ?', [$hash])->fetchColumn() : null;
$file = $path ? config('uploads_dir') . '/' . $path : '';
if (!$path || !is_file($file)) {
    http_response_code(404);
    exit;
}
header_remove('Pragma');
header('Cache-Control: private, max-age=604800');
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
readfile($file);
