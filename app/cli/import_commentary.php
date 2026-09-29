<?php
// Imports the commentaries from a corpus folder of Markdown files, the shape the fetching script
// writes:
//
//     <corpus>/<book>/<chapter>/commentaries/<work>.md
//
// where <book> is a plain name ("romans", "1_samuel"), and each file has sections headed
// "### Verse 3" or "### Verses 4–9".
//
// Usage:
//   php cli/import_commentary.php --works                 set up the catalog (do this first)
//   php cli/import_commentary.php <corpus dir>            import everything under it
//   php cli/import_commentary.php <corpus dir> --book romans --chapter 11
//   php cli/import_commentary.php <corpus dir> --only poole   just this work
//   php cli/import_commentary.php <corpus dir> --force    replace chapters already imported
//
// It is safe to run again: a chapter already imported is skipped unless --force is given.
require __DIR__ . '/../lib/bootstrap.php';
require APP_DIR . '/lib/bible.php';
require APP_DIR . '/lib/commentary.php';
require APP_DIR . '/lib/commentary_works.php';
require APP_DIR . '/lib/bible_refs.php';

$args = array_slice($argv, 1);
$opt = fn($name, $default = null) => ($i = array_search('--' . $name, $args, true)) !== false
    ? ($args[$i + 1] ?? true) : $default;

// ---------- the catalog ----------
if (in_array('--works', $args, true)) {
    foreach (commentary_catalog() as $w) {
        q('INSERT INTO bible_works (code, name, edition, author, years, scope, source, license, sort)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE name = VALUES(name), edition = VALUES(edition), author = VALUES(author),
             years = VALUES(years), scope = VALUES(scope), license = VALUES(license), sort = VALUES(sort)',
            [$w['code'], $w['name'], $w['edition'], $w['author'], $w['years'], $w['scope'], '', $w['license'], $w['sort']]);
    }
    echo count(commentary_catalog()), " works in the catalog.\n";
    echo "Not imported until their standing is settled: ", implode(', ', commentary_unchecked()), "\n";
    exit(0);
}

$dir = $args[0] ?? '';
if ($dir === '' || str_starts_with($dir, '--') || !is_dir($dir)) {
    fwrite(STDERR, "Usage: php cli/import_commentary.php <corpus dir> [--book X] [--chapter N] [--force]\n");
    exit(1);
}
$force = in_array('--force', $args, true);
$onlyBook = $opt('book');
$onlyWork = (string)$opt('only', '');
$onlyChapter = (int)$opt('chapter', 0);

if (!q('SELECT COUNT(*) FROM bible_works')->fetchColumn()) {
    fwrite(STDERR, "The catalog is empty: run with --works first.\n");
    exit(1);
}
$known = array_column(commentary_works(), 'code');
$skip = commentary_unchecked();
$names = bible_ref_names();

$done = ['chapters' => 0, 'files' => 0, 'words' => 0, 'skipped' => 0, 'unknown' => []];

foreach (glob(rtrim($dir, '/') . '/*', GLOB_ONLYDIR) as $bookDir) {
    $plain = strtolower(str_replace('_', ' ', basename($bookDir)));
    $code = $names[$plain] ?? null;
    if (!$code || ($onlyBook && strcasecmp(basename($bookDir), $onlyBook) !== 0)) {
        continue;
    }
    foreach (glob($bookDir . '/*', GLOB_ONLYDIR) as $chapterDir) {
        $chapter = (int)basename($chapterDir);
        if ($chapter < 1 || ($onlyChapter && $chapter !== $onlyChapter)) {
            continue;
        }
        $files = glob($chapterDir . '/commentaries/*.md');
        if (!$files) {
            continue;
        }
        $done['chapters']++;
        foreach ($files as $file) {
            // Files are named either "barnes.md" or "james_3_barnes.md"; no work's code has an
            // underscore in it, so the last part is the work either way.
            $parts = explode('_', basename($file, '.md'));
            $work = end($parts);
            if ($onlyWork !== '' && $work !== $onlyWork) {
                continue;
            }
            // The corpus's own patristic file comes from a compilation that quotes translations
            // made in our own lifetimes; the app carries Newman's 1842 catena instead.
            if (in_array($work, $skip, true) || $work === 'catena') {
                $done['skipped']++;
                continue;
            }
            if (!in_array($work, $known, true)) {
                $done['unknown'][$work] = ($done['unknown'][$work] ?? 0) + 1;
                continue;
            }
            if (!$force && q('SELECT 1 FROM bible_commentary WHERE work = ? AND book = ? AND chapter = ?',
                    [$work, $code, $chapter])->fetchColumn()) {
                continue;
            }
            $entries = commentary_parse_markdown((string)file_get_contents($file));
            if (!$entries) {
                continue;
            }
            $done['words'] += commentary_store($work, $code, $chapter, $entries);
            $done['files']++;
        }
        echo "$code $chapter: ", count($files), " works\n";
    }
}

foreach ($known as $work) {
    commentary_count_chapters($work);
}

echo "\nImported {$done['files']} files over {$done['chapters']} chapters, ",
     number_format($done['words']), " words.\n";
if ($done['skipped']) {
    echo "{$done['skipped']} files left out (copyright not settled): ", implode(', ', $skip), "\n";
}
foreach ($done['unknown'] as $work => $n) {
    echo "Not in the catalog, so left out: $work ($n files)\n";
}
