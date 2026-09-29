<?php
// Profile photos: from Telegram (at sign-in, or fetched by the bot), or uploaded
// by the member. Always re-encoded here as a 256px square JPEG and served from
// our own server, so Telegram never sees who is looking.

const AVATAR_SIZE = 256;

// Crops $src (any image GD reads) to a centered square and stores it as the user's photo.
function save_avatar(int $user_id, string $src, string $source): bool
{
    ini_set('memory_limit', '512M');
    $info = @getimagesize($src);
    if (!$info) {
        return false;
    }
    $img = @imagecreatefromstring(file_get_contents($src));
    if (!$img) {
        return false;
    }
    if (($info['mime'] ?? '') === 'image/jpeg' && function_exists('exif_read_data')) {
        $angle = [3 => 180, 6 => -90, 8 => 90][(int)(@exif_read_data($src)['Orientation'] ?? 1)] ?? 0;
        if ($angle) {
            $turned = imagerotate($img, $angle, 0);
            imagedestroy($img);
            $img = $turned;
        }
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $side = min($w, $h);
    $out = imagecreatetruecolor(AVATAR_SIZE, AVATAR_SIZE);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), AVATAR_SIZE, AVATAR_SIZE, $side, $side);
    imagedestroy($img);

    $dir = config('uploads_dir') . '/avatars';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $rel = 'avatars/' . $user_id . '-' . bin2hex(random_bytes(6)) . '.jpg';
    imagejpeg($out, config('uploads_dir') . '/' . $rel, 86);
    imagedestroy($out);
    chmod(config('uploads_dir') . '/' . $rel, 0600);

    $old = q('SELECT avatar_path FROM users WHERE id = ?', [$user_id])->fetchColumn();
    q('UPDATE users SET avatar_path = ?, avatar_version = ?, avatar_source = ? WHERE id = ?', [$rel, time(), $source, $user_id]);
    if ($old) {
        @unlink(config('uploads_dir') . '/' . $old);
    }
    return true;
}

function remove_avatar(int $user_id): void
{
    $old = q('SELECT avatar_path FROM users WHERE id = ?', [$user_id])->fetchColumn();
    q('UPDATE users SET avatar_path = NULL, avatar_version = NULL, avatar_source = NULL WHERE id = ?', [$user_id]);
    if ($old) {
        @unlink(config('uploads_dir') . '/' . $old);
    }
}

function download_to_temp(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXFILESIZE => 5 * 1024 * 1024]);
    $data = curl_exec($ch);
    $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    if (!$ok || !is_string($data) || $data === '') {
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'av');
    file_put_contents($tmp, $data);
    return $tmp;
}

// The photo link Telegram's login hands us (only t.me / telegram.org addresses).
function avatar_from_login_url(int $user_id, string $url): bool
{
    if (!preg_match('~^https://(t\.me|[a-z0-9-]+\.telegram\.org|telegram\.org)/~', $url)) {
        return false;
    }
    $tmp = download_to_temp($url);
    if (!$tmp) {
        return false;
    }
    $ok = save_avatar($user_id, $tmp, 'telegram');
    unlink($tmp);
    return $ok;
}

// Asks the bot for a member's current profile photo. Returns false if there is
// none (or their privacy settings hide it).
function avatar_from_bot(int $user_id, int $telegram_id): bool
{
    $r = telegram_api('getUserProfilePhotos', ['user_id' => $telegram_id, 'limit' => 1]);
    $sizes = $r['result']['photos'][0] ?? null;
    if (!$sizes) {
        return false;
    }
    $best = null;
    foreach ($sizes as $s) {
        if ($s['width'] <= 640 && (!$best || $s['width'] > $best['width'])) {
            $best = $s;
        }
    }
    $best ??= $sizes[0];
    $f = telegram_api('getFile', ['file_id' => $best['file_id']]);
    if (empty($f['result']['file_path'])) {
        return false;
    }
    $tmp = download_to_temp('https://api.telegram.org/file/bot' . config('telegram_bot_token') . '/' . $f['result']['file_path']);
    if (!$tmp) {
        return false;
    }
    $ok = save_avatar($user_id, $tmp, 'telegram');
    unlink($tmp);
    return $ok;
}
