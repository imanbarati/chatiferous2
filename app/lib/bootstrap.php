<?php
declare(strict_types=1);

define('APP_DIR', dirname(__DIR__));
date_default_timezone_set('UTC');

require APP_DIR . '/lib/db.php';
require APP_DIR . '/lib/auth.php';
require APP_DIR . '/lib/telegram.php';
require APP_DIR . '/lib/layout.php';

function config(string $key)
{
    static $cfg = null;
    // Tests point this at config.test.php (a separate database).
    $cfg ??= require (getenv('CHATIFEROUS_CONFIG') ?: APP_DIR . '/config.php');
    return $cfg[$key] ?? config_default($key, $cfg);
}

// Settings a config.php may leave out. Optional features are off unless switched on.
function config_default(string $key, array $cfg)
{
    return match ($key) {
        'group_name'    => 'Chatiferous',
        'short_name'    => $cfg['group_name'] ?? 'Chatiferous',   // under the home-screen icon
        'group_emoji'   => '💬',                                  // the group's picture
        'app_icons'     => 'assets/icons/',                       // favicon and home-screen icons
        'session_name'  => 'chatiferous_sid',
        'wallpaper'     => false,                                 // the doodle pattern behind messages
        'daily_reading' => false,                                 // scheduled daily posts and a poll
        default         => null,
    };
}

// The group's picture: the app's own icon, so the site looks like itself wherever it appears.
// An installation with no icons of its own falls back to the emoji in its config.
function group_avatar(string $class = ''): string
{
    $file = APP_WEB_DIR . '/' . config('app_icons') . 'icon-192.png';
    $classes = trim('group-avatar ' . $class);
    if (!is_file($file)) {
        return '<div class="' . h($classes) . '" aria-hidden="true">' . h(config('group_emoji')) . '</div>';
    }
    return '<img class="' . h($classes) . '" src="' . h(url(config('app_icons') . 'icon-192.png'))
        . '?v=' . app_version() . '" alt="" aria-hidden="true">';
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim(config('base_url'), '/') . '/' . ltrim($path, '/');
}

// CSS/JS go through a.php with their version in the URL: the host's proxy caches
// static files by path (ignoring query strings), so plain file URLs go stale.
function asset(string $name): string
{
    return url('a.php?f=' . rawurlencode($name) . '&v=' . app_version());
}

// Changes whenever the app is deployed, so an open copy of the app can tell that it's out of
// date and offer to refresh, and so every script and style is fetched afresh.
//
// It comes from a stamp cli/migrate.php writes at the end of each deploy. A file's modification
// time would be the obvious thing to use, but PHP's realpath cache can report an old one for a
// couple of minutes after the files change, which sends browsers to the script that has just
// been replaced; the contents of a file are never cached that way.
function app_version(): int
{
    static $v = null;
    if ($v === null) {
        $v = (int)@file_get_contents(APP_DIR . '/data/build');
        if (!$v) {   // no stamp yet (a fresh copy, or one deployed some other way)
            foreach (glob(APP_WEB_DIR . '/assets/*.{js,css}', GLOB_BRACE) ?: [] as $f) {
                $v = max($v, (int)filemtime($f));
            }
        }
    }
    return $v;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    // Behind Cloudflare the visitor's address arrives in a header, which is only believed when the
    // request really comes from Cloudflare (anyone can send the header straight to the server).
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && ip_in_ranges($remote, CLOUDFLARE_RANGES)) {
        return $cf;
    }
    return substr($remote, 0, 45);
}

// https://www.cloudflare.com/ips/ (checked 2026-09-19; they change rarely).
const CLOUDFLARE_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
    '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
    '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
    '2a06:98c0::/29', '2c0f:f248::/32',
];

function ip_in_ranges(string $ip, array $ranges): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($ranges as $range) {
        [$net, $bits] = explode('/', $range);
        $n = inet_pton($net);
        if (strlen($n) !== strlen($bin)) {
            continue;
        }
        $bytes = intdiv((int)$bits, 8);
        $rest = (int)$bits % 8;
        if (substr($bin, 0, $bytes) !== substr($n, 0, $bytes)) {
            continue;
        }
        if ($rest === 0 || ((ord($bin[$bytes]) ^ ord($n[$bytes])) & (0xff << (8 - $rest)) & 0xff) === 0) {
            return true;
        }
    }
    return false;
}

if (PHP_SAPI !== 'cli') {
    // Nothing here may be cached by Cloudflare or browsers, or indexed.
    header('Cache-Control: no-store, private');
    // The WordPress site uses Cloudflare APO, which caches HTML regardless of
    // Cache-Control. These two tell Cloudflare's edge not to cache.
    header('Cloudflare-CDN-Cache-Control: no-store');
    header('cf-edge-cache: no-cache');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://telegram.org; "
        . "frame-src https://oauth.telegram.org; img-src 'self' data: blob: https://t.me https://telegram.org https://static.klipy.com; "
        . "style-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
    start_session();
}
