<?php
// Imports a public-domain Bible text for the reader (config 'bible_reader').
//   php cli/import_bible.php WEB|BSB|KJV [--file=local.zip] [--keep]
// Downloads the USFM from eBible.org, parses it (lib/usfm.php) and fills the bible_* tables.
// Safe to re-run: a version is replaced, not added to. Verse counts are checked at the end, and
// a version whose books are short of the expected totals is refused.
require __DIR__ . '/../lib/bootstrap.php';
require APP_DIR . '/lib/usfm.php';
require APP_DIR . '/lib/bible_canon.php';

$sources = [
    'WEB' => ['url' => 'https://ebible.org/Scriptures/engwebp_usfm.zip', 'name' => 'World English Bible'],
    'BSB' => ['url' => 'https://ebible.org/Scriptures/engbsb_usfm.zip',  'name' => 'Berean Standard Bible'],
    'KJV' => ['url' => 'https://ebible.org/Scriptures/eng-kjv_usfm.zip', 'name' => 'King James Version'],
];

$version = strtoupper($argv[1] ?? '');
if (!isset($sources[$version])) {
    exit("Usage: php cli/import_bible.php " . implode('|', array_keys($sources)) . " [--file=local.zip] [--keep]\n");
}
$local = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--file=')) {
        $local = substr($a, 7);
    }
}
$keep = in_array('--keep', $argv, true);

// ---- Get the files ----
$dir = APP_DIR . '/data/bible-' . strtolower($version);
$zip_path = $local ?: $dir . '.zip';
if (!$local) {
    echo "Downloading {$sources[$version]['url']} … ";
    $ch = curl_init($sources[$version]['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 180,
        CURLOPT_USERAGENT => 'Chatiferous Bible import',
    ]);
    $zip_data = curl_exec($ch);
    curl_close($ch);
    if (!is_string($zip_data) || strlen($zip_data) < 100000) {
        exit("failed.\nDownload it yourself and pass --file=<path to zip>.\n");
    }
    file_put_contents($zip_path, $zip_data);
    echo strlen($zip_data) . " bytes\n";
}
$zip = new ZipArchive();
if ($zip->open($zip_path) !== true) {
    exit("Couldn't open $zip_path.\n");
}
@mkdir($dir, 0700, true);
$zip->extractTo($dir);
$zip->close();

// ---- Parse every book of the usual 66 ----
$books = [];
$total_verses = 0;
foreach (glob($dir . '/*.usfm') as $file) {
    $parsed = usfm_parse_book(file_get_contents($file));
    $code = $parsed['code'];
    if (!isset(BIBLE_CANON[$code])) {
        continue;          // front matter, glossaries, the Apocrypha
    }
    $books[$code] = $parsed;
}
if (count($books) !== count(BIBLE_CANON)) {
    $missing = array_diff(array_keys(BIBLE_CANON), array_keys($books));
    exit('Refusing to import: ' . count($books) . " books found, missing " . implode(', ', $missing) . ".\n");
}

// ---- Write it ----
db()->beginTransaction();
foreach (['bible_notes', 'bible_verses', 'bible_chapters', 'bible_books'] as $t) {
    q("DELETE FROM $t WHERE version = ?", [$version]);
}
$ord = 0;
$problems = [];
foreach (BIBLE_CANON as $code => $canon) {
    [$name_default, $chapters_expected, $verses_expected, $testament, $slug] = $canon;
    $b = $books[$code];
    $ord++;
    q('INSERT INTO bible_books (version, ord, code, name, abbrev, chapters, testament, biblehub) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$version, $ord, $code, $b['name'] ?: $name_default, $b['abbrev'], count($b['chapters']), $testament, $slug]);
    if (count($b['chapters']) !== $chapters_expected) {
        $problems[] = "$code has " . count($b['chapters']) . " chapters, expected $chapters_expected";
    }
    $book_verses = 0;
    foreach ($b['chapters'] as $n => $ch) {
        q('INSERT INTO bible_chapters (version, book, chapter, html, verses) VALUES (?, ?, ?, ?, ?)',
            [$version, $code, $n, $ch['html'], count($ch['verses'])]);
        foreach ($ch['verses'] as $v => $text) {
            q('INSERT INTO bible_verses (version, book, chapter, verse, text) VALUES (?, ?, ?, ?, ?)',
                [$version, $code, $n, $v, $text]);
            $book_verses++;
            $total_verses++;
        }
        foreach ($ch['notes'] as $note) {
            q('INSERT INTO bible_notes (version, book, chapter, verse, kind, marker, body, refs) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$version, $code, $n, $note['verse'], $note['kind'], $note['marker'], $note['body'], $note['refs']]);
        }
    }
    // A few percent either way is normal (editions differ over verse divisions); a big gap is not.
    if ($verses_expected && abs($book_verses - $verses_expected) > max(3, $verses_expected * 0.02)) {
        $problems[] = "$code has $book_verses verses, expected about $verses_expected";
    }
}
if ($problems) {
    db()->rollBack();
    exit("Refusing to import:\n  " . implode("\n  ", $problems) . "\n");
}
db()->commit();

if (!$keep) {
    array_map('unlink', glob($dir . '/*') ?: []);
    @rmdir($dir);
    if (!$local) {
        @unlink($zip_path);
    }
}
$notes = (int)q('SELECT COUNT(*) FROM bible_notes WHERE version = ?', [$version])->fetchColumn();
echo "$version: " . count($books) . " books, "
    . (int)q('SELECT COUNT(*) FROM bible_chapters WHERE version = ?', [$version])->fetchColumn() . " chapters, "
    . "$total_verses verses, $notes notes.\n";
