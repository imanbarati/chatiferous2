<?php
// Serves CSS/JS from assets/. The host's proxy caches static files by path and
// ignores query strings, so updated files would go stale; PHP responses aren't
// cached by it. Each URL carries the file's version (see asset()), so browsers
// may keep a given version forever.
$types = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'svg' => 'image/svg+xml'];
$name = basename((string)($_GET['f'] ?? ''));

// chat.js is kept as a folder of readable parts (assets/chat/) and served as one script, in file
// name order, inside a single wrapper. The app is one closure, so the parts share their state and
// functions exactly as they did when it was one file. See asset() for the version.
if ($name === 'chat.js' && is_dir(__DIR__ . '/assets/chat')) {
    header('Content-Type: ' . $types['js']);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('X-Content-Type-Options: nosniff');
    echo "// Chatiferous client: topic list and topic view. Behavior follows Telegram's;\n";
    echo "// all code here is original. Built from assets/chat/*.js (see web/a.php).\n";
    echo "(() => {\n";
    foreach (glob(__DIR__ . '/assets/chat/[0-9]*.js') as $part) {
        echo "\n// ===== ", basename($part), " =====\n", file_get_contents($part);
    }
    echo "})();\n";
    exit;
}
$ext = pathinfo($name, PATHINFO_EXTENSION);
$file = __DIR__ . '/assets/' . $name;
if (!isset($types[$ext]) || !is_file($file)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $types[$ext]);
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($file));
readfile($file);
