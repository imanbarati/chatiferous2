<?php
// Receiving a photo or file. Photos are re-encoded (which strips location data
// and anything hidden in the file), turned upright and shrunk to 2560px at most.

const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;
const MAX_PHOTO_SIDE = 2560;
const UPLOADS_PER_10_MIN = 40;

// $sealed: a file for a DM, already encrypted by the sender's browser. It's stored exactly as it
// came (the server can't read or resize it); its real name and type travel inside the sealed message.
function store_upload(array $user, array $file, bool $sealed = false): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        fail(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'That file is too big.' : 'The upload didn’t arrive. Please try again.');
    }
    if ($file['size'] > MAX_UPLOAD_BYTES + ($sealed ? 64 : 0)) {
        fail('Files can be up to 25 MB.');
    }
    $recent = (int)q('SELECT COUNT(*) FROM attachments WHERE uploader_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 10 MINUTE',
        [$user['id']])->fetchColumn();
    if ($recent >= UPLOADS_PER_10_MIN) {
        fail('That’s a lot of uploads at once. Please wait a few minutes.');
    }

    if ($sealed) {
        $dir = date('Y/m');
        $root = config('uploads_dir');
        if (!is_dir("$root/$dir")) {
            mkdir("$root/$dir", 0700, true);
        }
        $rel = "$dir/" . bin2hex(random_bytes(12)) . '.sealed';
        move_uploaded_file($file['tmp_name'], "$root/$rel") ?: fail('The file couldn’t be saved.');
        chmod("$root/$rel", 0600);
        q("INSERT INTO attachments (message_id, uploader_id, kind, path, name, mime, size, encrypted) VALUES (NULL, ?, 'file', ?, '', 'application/octet-stream', ?, 1)",
            [$user['id'], $rel, filesize("$root/$rel")]);
        return ['id' => (int)db()->lastInsertId(), 'sealed' => true];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
    $name = mb_substr(preg_replace('/[\x00-\x1f\/\\\\]/u', '_', (string)$file['name']), 0, 200) ?: 'file';
    $dir = date('Y/m');
    $root = config('uploads_dir');
    if (!is_dir("$root/$dir")) {
        mkdir("$root/$dir", 0700, true);
    }
    $base = bin2hex(random_bytes(12));
    $kind = 'file';
    $w = $h = null;

    if (in_array($mime, ['image/heic', 'image/heif'], true)) {
        fail('That photo is in Apple’s HEIC format, which can’t be shown here. Please use 📎 → Photo, which converts it automatically.');
    }
    if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/bmp'], true)) {
        [$path, $mime, $w, $h] = reencode_photo($file['tmp_name'], $mime, "$root/$dir/$base");
        $kind = 'photo';
        $rel = "$dir/" . basename($path);
    } else {
        if ($mime === 'image/gif') {
            $kind = 'photo';
            [$w, $h] = getimagesize($file['tmp_name']) ?: [null, null];
        } elseif (str_starts_with($mime, 'video/')) {
            $kind = 'video';
        } elseif (str_starts_with($mime, 'audio/')) {
            $kind = 'voice';
        }
        $ext = strtolower(preg_replace('/[^A-Za-z0-9]/', '', pathinfo($name, PATHINFO_EXTENSION)));
        $rel = "$dir/$base" . ($ext !== '' ? ".$ext" : '');
        move_uploaded_file($file['tmp_name'], "$root/$rel") ?: fail('The file couldn’t be saved.');
    }
    chmod("$root/$rel", 0600);
    $size = filesize("$root/$rel");

    q('INSERT INTO attachments (message_id, uploader_id, kind, path, name, mime, size, width, height) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$user['id'], $kind, $rel, $kind === 'photo' ? '' : $name, $mime, $size, $w, $h]);
    return ['id' => (int)db()->lastInsertId(), 'kind' => $kind, 'name' => $name, 'size' => $size, 'w' => $w, 'h' => $h];
}

function reencode_photo(string $src, string $mime, string $dest_base): array
{
    // A 12-megapixel phone photo needs ~50 MB decoded, and rotating makes a
    // second copy; the site's default PHP limit is 64 MB.
    ini_set('memory_limit', '512M');
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($src),
        'image/png'  => @imagecreatefrompng($src),
        'image/webp' => @imagecreatefromwebp($src),
        'image/avif' => @imagecreatefromavif($src),
        'image/bmp'  => @imagecreatefrombmp($src),
    };
    if (!$img) {
        fail('That image couldn’t be read.');
    }
    // Phones store photos sideways plus an "orientation" note; apply it.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($src)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle) {
            $turned = imagerotate($img, $angle, 0);
            imagedestroy($img);
            $img = $turned;
        }
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, MAX_PHOTO_SIDE / max($w, $h));
    if ($scale < 1) {
        $scaled = imagescale($img, (int)round($w * $scale), (int)round($h * $scale), IMG_BICUBIC);
        imagedestroy($img);
        $img = $scaled;
        $w = imagesx($img);
        $h = imagesy($img);
    }
    // Keep PNGs (screenshots, transparency) as PNG; everything else becomes JPEG.
    if ($mime === 'image/png') {
        imagesavealpha($img, true);
        imagepng($img, "$dest_base.png", 6);
        imagedestroy($img);
        return ["$dest_base.png", 'image/png', $w, $h];
    }
    imagejpeg($img, "$dest_base.jpg", 85);
    imagedestroy($img);
    return ["$dest_base.jpg", 'image/jpeg', $w, $h];
}
