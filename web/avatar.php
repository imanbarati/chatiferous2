<?php
// A member's profile photo, for signed-in members only.
require __DIR__ . '/boot.php';

$user = current_user();
if (!$user || $user['password_hash'] === null) {
    http_response_code(403);
    exit;
}
session_write_close();
$path = q('SELECT avatar_path FROM users WHERE id = ?', [(int)($_GET['u'] ?? 0)])->fetchColumn();
$file = $path ? config('uploads_dir') . '/' . $path : '';
if (!$path || !is_file($file)) {
    http_response_code(404);
    exit;
}
header_remove('Pragma');
header('Cache-Control: private, max-age=31536000, immutable');   // the URL changes when the photo does
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
readfile($file);
