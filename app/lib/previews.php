<?php
// Link previews (site, title, description, image), as Telegram shows under a message with a link.
// The server fetches each page once and keeps the result, and copies the image so members'
// browsers never contact the linked site. Fetching is guarded against reaching private
// addresses (SSRF): http(s) on ports 80/443 only, every address the name resolves to must be
// public, the connection is pinned to the checked address, and each redirect is checked again.

const PREVIEW_MAX_PAGE = 1024 * 1024;        // bytes of a page read (the <head> is near the top)
const PREVIEW_MAX_IMAGE = 5 * 1024 * 1024;
const PREVIEW_NEW_PER_HOUR = 60;             // uncached links one member can have fetched
const PREVIEW_IMAGE_SIZE = 600;              // stored image: longest side, pixels

function preview_normalize(string $url): ?string
{
    $url = trim($url);
    $url = preg_replace('/#.*$/s', '', $url);
    if (strlen($url) > 2048 || !preg_match('~^https?://[^\s/?#]+~i', $url)) {
        return null;
    }
    return $url;
}

// The preview for a URL, fetching it if it's new. Null: none (or the member's hourly limit is used up).
function preview_for(array $user, string $url): ?array
{
    $url = preview_normalize($url);
    if ($url === null) {
        return null;
    }
    $hash = hash('sha256', $url);
    $row = q('SELECT * FROM link_previews WHERE url_hash = ?', [$hash])->fetch();
    if ($row && ($row['status'] === 'ok' || strtotime($row['fetched_at'] . ' UTC') > time() - 86400)) {
        return preview_json($row);
    }
    $recent = (int)q('SELECT COUNT(*) FROM link_previews WHERE fetched_by = ? AND fetched_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR',
        [$user['id']])->fetchColumn();
    if ($recent >= PREVIEW_NEW_PER_HOUR) {
        return null;
    }
    session_write_close();   // a slow site mustn't hold up this member's other requests
    $p = preview_fetch($url);
    $img = $p && $p['image'] ? preview_store_image($p['image'], $hash) : null;
    $data = [
        'url_hash' => $hash, 'url' => $url, 'status' => $p && ($p['title'] || $p['description']) ? 'ok' : 'none',
        'site' => $p['site'] ?? null, 'title' => $p['title'] ?? null, 'description' => $p['description'] ?? null,
        'image_path' => $img[0] ?? null, 'image_w' => $img[1] ?? null, 'image_h' => $img[2] ?? null,
        'fetched_by' => (int)$user['id'],
    ];
    q('REPLACE INTO link_previews (url_hash, url, status, site, title, description, image_path, image_w, image_h, fetched_by, fetched_at)
       VALUES (:url_hash, :url, :status, :site, :title, :description, :image_path, :image_w, :image_h, :fetched_by, UTC_TIMESTAMP())', $data);
    return preview_json($data);
}

function preview_json(array $r): ?array
{
    if ($r['status'] !== 'ok') {
        return null;
    }
    return [
        'url' => $r['url'], 'site' => $r['site'], 'title' => $r['title'], 'description' => $r['description'],
        'image' => $r['image_path'] ? url('preview-image.php?h=' . $r['url_hash']) : null,
        'w' => $r['image_w'] ? (int)$r['image_w'] : null, 'h' => $r['image_h'] ? (int)$r['image_h'] : null,
    ];
}

// True if every address $host resolves to is a public one; returns the first to connect to.
function preview_public_ip(string $host): ?string
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        if (!preg_match('/^[a-z0-9.-]+$/i', $host) || !str_contains($host, '.')) {
            return null;
        }
        $ips = gethostbynamel($host) ?: [];
    }
    if (!$ips) {
        return null;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
        // Shared address space (carrier NAT) and benchmarking ranges aren't covered by the flags.
        $n = ip2long($ip);
        if (($n & 0xFFC00000) === ip2long('100.64.0.0') || ($n & 0xFFFE0000) === ip2long('198.18.0.0')) {
            return null;
        }
    }
    return $ips[0];
}

// GET with the guards above. Returns [body, content type, final URL] or null.
function preview_get(string $url, int $max, string $accept): ?array
{
    for ($hop = 0; $hop < 4; $hop++) {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            return null;
        }
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) {
            return null;
        }
        $host = strtolower($p['host']);
        $ip = preview_public_ip($host);
        if ($ip === null) {
            return null;
        }
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ["$host:$port:$ip"],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LinkPreview/1.0; +https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . config('base_url') . ')',
            CURLOPT_HTTPHEADER => ["Accept: $accept", 'Accept-Language: en'],
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $max) {
                $body .= $chunk;
                return strlen($body) > $max ? 0 : strlen($chunk);   // 0 stops the download
            },
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $next = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        if (in_array($code, [301, 302, 303, 307, 308], true) && $next !== '') {
            $url = $next;
            continue;
        }
        if ($code !== 200 || $body === '') {
            return null;
        }
        return [$body, $type, $url];
    }
    return null;
}

// Reads the page's Open Graph / Twitter / plain HTML tags. Null if the page couldn't be read.
function preview_fetch(string $url): ?array
{
    $got = preview_get($url, PREVIEW_MAX_PAGE, 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5');
    if (!$got) {
        return null;
    }
    [$html, $type, $final] = $got;
    if (!preg_match('~text/html|application/xhtml~i', $type)) {
        return null;
    }
    // Character set: the header, else the page's own <meta charset>, else UTF-8.
    $cs = preg_match('/charset=["\']?([\w-]+)/i', $type, $m) ? $m[1]
        : (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4096), $m) ? $m[1] : 'UTF-8');
    if (strcasecmp($cs, 'UTF-8') !== 0) {
        $html = @mb_convert_encoding($html, 'UTF-8', $cs) ?: $html;
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $meta = [];
    foreach ($doc->getElementsByTagName('meta') as $el) {
        $k = strtolower($el->getAttribute('property') ?: $el->getAttribute('name'));
        if ($k !== '' && !isset($meta[$k])) {
            $meta[$k] = trim($el->getAttribute('content'));
        }
    }
    $titleEl = $doc->getElementsByTagName('title')->item(0);
    $clean = fn($s, $n) => ($s = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8')))) === ''
        ? null : mb_substr($s, 0, $n);
    $image = $meta['og:image:secure_url'] ?? $meta['og:image'] ?? $meta['og:image:url'] ?? $meta['twitter:image'] ?? $meta['twitter:image:src'] ?? '';
    $host = preg_replace('/^www\./', '', strtolower((string)parse_url($final, PHP_URL_HOST)));
    return [
        'site' => $clean($meta['og:site_name'] ?? $host, 100),
        'title' => $clean($meta['og:title'] ?? $meta['twitter:title'] ?? ($titleEl ? $titleEl->textContent : ''), 300),
        'description' => $clean($meta['og:description'] ?? $meta['twitter:description'] ?? $meta['description'] ?? '', 500),
        'image' => $image !== '' ? preview_absolute($image, $final) : null,
    ];
}

function preview_absolute(string $ref, string $base): ?string
{
    if (preg_match('~^https?://~i', $ref)) {
        return $ref;
    }
    $b = parse_url($base);
    if (!$b || empty($b['host'])) {
        return null;
    }
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($ref, '//')) {
        return $b['scheme'] . ':' . $ref;
    }
    if (str_starts_with($ref, '/')) {
        return $origin . $ref;
    }
    $dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/');
    return $origin . $dir . $ref;
}

// Downloads, shrinks and saves the image as JPEG. Returns [path, width, height] or null.
function preview_store_image(?string $url, string $hash): ?array
{
    if (!$url) {
        return null;
    }
    $got = preview_get($url, PREVIEW_MAX_IMAGE, 'image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8');
    if (!$got) {
        return null;
    }
    $data = $got[0];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        return null;
    }
    ini_set('memory_limit', '512M');
    $src = @imagecreatefromstring($data);
    if (!$src) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 80 || $h < 80) {       // icons and tracking pixels aren't worth showing
        imagedestroy($src);
        return null;
    }
    $scale = min(1, PREVIEW_IMAGE_SIZE / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // transparent PNGs on white
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    $root = config('uploads_dir');
    if (!is_dir("$root/previews")) {
        mkdir("$root/previews", 0700, true);
    }
    $rel = "previews/$hash.jpg";
    imagejpeg($dst, "$root/$rel", 82);
    imagedestroy($dst);
    chmod("$root/$rel", 0600);
    return [$rel, $nw, $nh];
}
