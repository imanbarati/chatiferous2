<?php
// The web app manifest: name, icons and start page for "Add to Home Screen" / "Install app".
// Served by PHP rather than as a static file because the host's proxy caches static files.
require __DIR__ . '/boot.php';
header('Content-Type: application/manifest+json');
header('Cache-Control: no-cache');
$icon = fn(string $f, int $s, string $purpose = 'any') => ['src' => url(config('app_icons') . $f), 'sizes' => "{$s}x{$s}", 'type' => 'image/png', 'purpose' => $purpose];
echo json_encode([
    'id'               => url(),
    'name'             => config('group_name'),
    'short_name'       => config('short_name'),
    'start_url'        => url(),
    'scope'            => url(),
    'display'          => 'standalone',
    'background_color' => '#ffffff',
    'theme_color'      => '#ffffff',
    'icons'            => [
        $icon('icon-192.png', 192), $icon('icon-512.png', 512),
        $icon('icon-192.png', 192, 'maskable'), $icon('icon-512.png', 512, 'maskable'),
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
