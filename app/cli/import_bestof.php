<?php
// Imports the curated "best of" commentary — the one written for this group rather than fetched
// from anywhere — into the reader, so it sits beside Henry, Barnes and the rest.
//
//   php cli/import_bestof.php <dir> [--force]
//
// The directory holds one folder per book, named as the book is ("1_chronicles", "romans"), each
// with one Markdown file per chapter ("12.md"). That is what the curation itself produces, so
// nothing has to be prepared for this; new chapters simply appear and are picked up.
//
// A file looks like:
//
//   # Romans 12 — Curated Commentary
//   ---
//   *Verses 1–2. I beseech you therefore, brethren…*
//   **Each bodily member becomes a sacrifice…** —Chrysostom
//   How is the body to become a sacrifice? …
//   ---
//
// The verse text itself is left out: the reader already has the passage on screen above, and
// printing it twice is only in the way. What is kept is the capsule, who said it, and the comment.
//
// Nothing is deleted. A chapter that is re-curated replaces its own row and nothing else.

require __DIR__ . '/../lib/bootstrap.php';
require APP_DIR . '/lib/bible_canon.php';
require APP_DIR . '/lib/commentary.php';
require APP_DIR . '/lib/bible_refs.php';

const BESTOF_CODE = 'bestof';

$args = array_slice($argv, 1);
$dir = $args[0] ?? '';
$force = in_array('--force', $args, true);
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Usage: php cli/import_bestof.php <dir of curated markdown> [--force]\n");
    exit(1);
}

// The work itself, so it appears in the chooser. Sorted to the front: it is the one written for
// this group, and the reason the others are there at all is to have been read for it.
q('INSERT INTO bible_works (code, name, edition, author, years, scope, source, licence, sort)
   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
   ON DUPLICATE KEY UPDATE name = VALUES(name), edition = VALUES(edition), author = VALUES(author),
     years = VALUES(years), scope = VALUES(scope), licence = VALUES(licence), sort = VALUES(sort)',
    [BESTOF_CODE, 'Curated Best-Of', 'the best of ~30 commentators, verse by verse', '', '', 'some', '', 'Curated for this group', 1]);

// Turns a folder name into a book code: "1_chronicles" is 1 Chronicles, "romans" is Romans.
$names = bible_ref_names();
function book_code(string $slug, array $names): ?string
{
    $plain = strtolower(str_replace(['_', '-'], ' ', $slug));
    return $names[$plain] ?? $names[str_replace(' ', '', $plain)] ?? null;
}

// Markdown's emphasis, and nothing else: the curation writes prose, not documents.
function md_inline(string $s): string
{
    $s = htmlspecialchars(trim($s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $s = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/su', '<em>$1</em>', $s);
    return $s;
}

// One chapter file to {verse range => html}.
function parse_chapter(string $text): array
{
    $entries = [];
    $range = '';
    $html = [];
    $flush = function () use (&$entries, &$range, &$html) {
        if ($range !== '' && $html) {
            $entries[$range] = ($entries[$range] ?? '') . implode("\n", $html);
        }
        $html = [];
    };
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line === '---' || str_starts_with($line, '# ')) {
            continue;
        }
        // "*Verse 3. …*" or "*Verses 1–2. …*" begins a new entry, and carries the passage text,
        // which the reader already shows.
        if (preg_match('/^\*Verses?\s+(\d+)(?:\s*[-–—]\s*(\d+))?\s*[.:]/u', $line, $m)) {
            $flush();
            $range = $m[1] . (isset($m[2]) && $m[2] !== '' && $m[2] !== $m[1] ? '-' . $m[2] : '');
            continue;
        }
        if ($range === '') {
            continue;                       // anything before the first verse heading is a preamble
        }
        // "**The capsule.** —Chrysostom": the point in one sentence, and who made it. The dash
        // before the name is the last one on the line; capsules use them freely themselves.
        if (str_starts_with($line, '**')) {
            $attr = '';
            $cut = mb_strrpos($line, '—');
            if ($cut !== false && mb_strpos($line, '**', $cut) === false) {
                $attr = trim(mb_substr($line, $cut + 1));
                $line = trim(mb_substr($line, 0, $cut));
            }
            $html[] = '<p class="c-cap">' . md_inline($line)
                . ($attr !== '' ? ' <span class="c-attr">—' . htmlspecialchars($attr, ENT_QUOTES, 'UTF-8') . '</span>' : '')
                . '</p>';
            continue;
        }
        $html[] = '<p>' . md_inline($line) . '</p>';
    }
    $flush();
    return $entries;
}

$seen = ['chapters' => 0, 'words' => 0, 'skipped' => 0, 'unknown' => []];
foreach (glob(rtrim($dir, '/') . '/*', GLOB_ONLYDIR) as $folder) {
    $slug = basename($folder);
    $book = book_code($slug, $names);
    if (!$book) {
        $seen['unknown'][] = $slug;
        continue;
    }
    foreach (glob($folder . '/*.md') as $file) {
        $chapter = (int)pathinfo($file, PATHINFO_FILENAME);
        if ($chapter < 1) {
            continue;
        }
        $entries = parse_chapter((string)file_get_contents($file));
        if (!$entries) {
            $seen['skipped']++;
            fwrite(STDERR, "  nothing parsed from $slug/$chapter.md\n");
            continue;
        }
        // Unchanged chapters are left alone, so a daily run is quiet and costs nothing. Compared
        // against what is actually stored, packed the same way, so the answer is exact.
        $blob = q('SELECT entries FROM bible_commentary WHERE work = ? AND book = ? AND chapter = ?',
            [BESTOF_CODE, $book, $chapter])->fetchColumn();
        $now = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!$force && $blob && @gzdecode($blob) === $now) {
            $seen['skipped']++;
            continue;
        }
        $words = commentary_store(BESTOF_CODE, $book, $chapter, $entries);
        $seen['chapters']++;
        $seen['words'] += $words;
        echo "  $book $chapter: ", count($entries), " entries, ", number_format($words), " words\n";
    }
}

q('UPDATE bible_works SET chapters = ? WHERE code = ?', [commentary_count_chapters(BESTOF_CODE), BESTOF_CODE]);

echo "Imported ", $seen['chapters'], " chapter(s), ", number_format($seen['words']), " words";
echo $seen['skipped'] ? "; " . $seen['skipped'] . " already current" : '';
echo ".\n";
if ($seen['unknown']) {
    echo "Not a book I know: ", implode(', ', $seen['unknown']), "\n";
}
