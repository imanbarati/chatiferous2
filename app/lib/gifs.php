<?php
// GIF search through KLIPY (https://klipy.com), with the strictest content filter.
// Results are cached 15 minutes; a chosen GIF is copied to our own server.

const GIF_CACHE_MINUTES = 15;
const GIF_MAX_BYTES = 8 * 1024 * 1024;

function klipy_get(string $path, array $params): ?array
{
    $url = 'https://api.klipy.com/api/v1/' . rawurlencode((string)config('klipy_key')) . $path . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $body = curl_exec($ch);
    $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    $json = $ok && is_string($body) ? json_decode($body, true) : null;
    return is_array($json) ? $json : null;
}

// Search (or trending when $q is empty). Returns [items, has_next].
function gif_search(int $user_id, string $q, int $page): array
{
    if (!config('klipy_key')) {
        fail('GIF search isn’t set up yet.');
    }
    $q = mb_substr(trim($q), 0, 60);
    $page = max(1, min(20, $page));
    $key = sha1(mb_strtolower($q) . '|' . $page);
    $cached = q('SELECT body FROM gif_cache WHERE cache_key = ? AND fetched_at > UTC_TIMESTAMP() - INTERVAL ' . GIF_CACHE_MINUTES . ' MINUTE', [$key])->fetchColumn();
    if ($cached) {
        return json_decode($cached, true);
    }
    // KLIPY asks for a per-user id; send an anonymous one, never a name.
    $customer = substr(hash_hmac('sha256', (string)$user_id, (string)config('vapid_private')), 0, 20);
    $params = ['page' => $page, 'per_page' => 24, 'customer_id' => $customer, 'content_filter' => 'high', 'locale' => 'en'];
    $r = $q === '' ? klipy_get('/gifs/trending', $params) : klipy_get('/gifs/search', $params + ['q' => $q]);
    if (!$r || empty($r['result'])) {
        fail('GIF search didn’t respond. Please try again in a moment.');
    }
    $items = [];
    foreach ($r['data']['data'] ?? [] as $g) {
        if (($g['type'] ?? '') !== 'gif') {
            continue;   // never show ads or anything else
        }
        $f = $g['file'] ?? [];
        $thumb = $f['sm']['webp'] ?? $f['xs']['webp'] ?? $f['sm']['gif'] ?? null;
        $send = $f['md']['webp'] ?? $f['sm']['webp'] ?? $f['md']['gif'] ?? null;
        if (!$thumb || !$send) {
            continue;
        }
        $items[] = ['id' => (string)$g['id'], 'title' => $g['title'] ?? '', 'thumb' => $thumb['url'],
            'w' => (int)$thumb['width'], 'h' => (int)$thumb['height'], 'url' => $send['url']];
    }
    $out = [$items, !empty($r['data']['has_next'])];
    q('REPLACE INTO gif_cache (cache_key, body, fetched_at) VALUES (?, ?, UTC_TIMESTAMP())', [$key, json_encode($out, JSON_UNESCAPED_SLASHES)]);
    return $out;
}

// Downloads a chosen GIF (only from KLIPY's file server) and stores it as an
// attachment of the sender. Returns the attachment id.
function store_gif(array $user, string $url): int
{
    if (!preg_match('~^https://static\.klipy\.com/[A-Za-z0-9/_.-]+\.(webp|gif)$~', $url)) {
        fail('That GIF can’t be used.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_MAXFILESIZE => GIF_MAX_BYTES]);
    $data = curl_exec($ch);
    $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    if (!$ok || !is_string($data) || strlen($data) > GIF_MAX_BYTES) {
        fail('That GIF couldn’t be downloaded. Please try another.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
    if (!in_array($mime, ['image/webp', 'image/gif'], true)) {
        fail('That GIF can’t be used.');
    }
    [$w, $h] = getimagesizefromstring($data) ?: [null, null];
    $dir = gmdate('Y/m');
    $root = config('uploads_dir');
    if (!is_dir("$root/$dir")) {
        mkdir("$root/$dir", 0700, true);
    }
    $rel = "$dir/" . bin2hex(random_bytes(12)) . ($mime === 'image/webp' ? '.webp' : '.gif');
    file_put_contents("$root/$rel", $data);
    chmod("$root/$rel", 0600);
    q("INSERT INTO attachments (message_id, uploader_id, kind, path, name, mime, size, width, height) VALUES (NULL, ?, 'animation', ?, '', ?, ?, ?, ?)",
        [$user['id'], $rel, $mime, strlen($data), $w, $h]);
    return (int)db()->lastInsertId();
}
