<?php
// Imports a commentary from a MyBible module: one SQLite file whose `commentaries` table is
// already keyed by book, chapter and verse range — which is the shape this app stores, so nothing
// has to be scraped or guessed at.
//
// Usage:
//   php cli/import_mybible.php <file.SQLite3> --work barnes [--force] [--dry]
//
// --dry reads the file and reports what it holds without writing anything, including a count of
// the scripture references in the text: some modules have had their references stripped out by
// whoever prepared them, which is worth knowing before a work goes in.
//
// --sections is for the works written in sections rather than verse by verse (Matthew Henry,
// MacLaren, Keil & Delitzsch, the Expositor's Bible). Those modules file a whole section against
// its first verse, so without this a comment on "verses 1-21" would only be found by tapping
// verse 1. Each comment is then stretched to the verse before the next one begins. Don't use it
// for a work that comments on particular verses and passes over others (Vincent, Wesley): their
// silence is real, and filling it would put a note against a verse it says nothing about.
require __DIR__ . '/../lib/bootstrap.php';
require APP_DIR . '/lib/bible.php';
require APP_DIR . '/lib/commentary.php';
require APP_DIR . '/lib/commentary_works.php';

$args = array_slice($argv, 1);
$opt = function (string $name, $default = null) use ($args) {
    $i = array_search('--' . $name, $args, true);
    return $i === false ? $default : ($args[$i + 1] ?? true);
};
$file = $args[0] ?? '';
$work = (string)$opt('work', '');
$dry = in_array('--dry', $args, true);
$force = in_array('--force', $args, true);
$sections = in_array('--sections', $args, true);

if (!is_file($file) || $work === '') {
    fwrite(STDERR, "Usage: php cli/import_mybible.php <file.SQLite3> --work <code> [--force] [--dry]\n");
    exit(1);
}
if (!$dry && !commentary_work($work)) {
    fwrite(STDERR, "No work called “{$work}” in the catalog (run import_commentary.php --works).\n");
    exit(1);
}

// MyBible numbers the books in tens, with gaps where the deuterocanon would be.
const MYBIBLE_BOOKS = [
    10 => 'GEN', 20 => 'EXO', 30 => 'LEV', 40 => 'NUM', 50 => 'DEU', 60 => 'JOS', 70 => 'JDG',
    80 => 'RUT', 90 => '1SA', 100 => '2SA', 110 => '1KI', 120 => '2KI', 130 => '1CH', 140 => '2CH',
    150 => 'EZR', 160 => 'NEH', 190 => 'EST', 220 => 'JOB', 230 => 'PSA', 240 => 'PRO',
    250 => 'ECC', 260 => 'SNG', 290 => 'ISA', 300 => 'JER', 310 => 'LAM', 330 => 'EZK',
    340 => 'DAN', 350 => 'HOS', 360 => 'JOL', 370 => 'AMO', 380 => 'OBA', 390 => 'JON',
    400 => 'MIC', 410 => 'NAM', 420 => 'HAB', 430 => 'ZEP', 440 => 'HAG', 450 => 'ZEC',
    460 => 'MAL', 470 => 'MAT', 480 => 'MRK', 490 => 'LUK', 500 => 'JHN', 510 => 'ACT',
    520 => 'ROM', 530 => '1CO', 540 => '2CO', 550 => 'GAL', 560 => 'EPH', 570 => 'PHP',
    580 => 'COL', 590 => '1TH', 600 => '2TH', 610 => '1TI', 620 => '2TI', 630 => 'TIT',
    640 => 'PHM', 650 => 'HEB', 660 => 'JAS', 670 => '1PE', 680 => '2PE', 690 => '1JN',
    700 => '2JN', 710 => '3JN', 720 => 'JUD', 730 => 'REV',
];

// The opening words of every verse, for spotting where a module reprints the passage before
// commenting on it (Matthew Henry does this for whole sections). The reader has the text on
// screen already, so those paragraphs are dropped — but a note that opens by quoting the phrase
// it expounds, as Gill's and Clarke's do, is kept: that needs two verses to match, not one.
$openings = [];
$verses = [];
foreach (q('SELECT book, chapter, verse, text FROM bible_verses WHERE version = ?',
    [bible_default_version()])->fetchAll() as $r) {
    $plain = mb_strtolower(trim(preg_replace('/\s+/u', ' ',
        preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $r['text']))));
    $words = explode(' ', $plain);
    $openings[$r['book']][(int)$r['chapter']][(int)$r['verse']] = implode(' ', array_slice($words, 0, 6));
    $verses[$r['book']][(int)$r['chapter']][(int)$r['verse']] = $plain;
}


// Where each chapter ends, so a comment that runs to the end of one says so in real verses.
$lastVerse = [];
foreach (q('SELECT book, chapter, MAX(verse) AS last FROM bible_verses WHERE version = ? GROUP BY book, chapter',
    [bible_default_version()])->fetchAll() as $r) {
    $lastVerse[$r['book']][(int)$r['chapter']] = (int)$r['last'];
}
$endOf = fn(string $book, int $chapter) => $lastVerse[$book][$chapter] ?? 999;

$db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$info = [];
foreach ($db->query('SELECT name, value FROM info')->fetchAll(PDO::FETCH_NUM) as [$k, $v]) {
    $info[$k] = $v;
}
echo "Module: ", $info['description'] ?? basename($file), "\n";

$rows = $db->query('SELECT book_number, chapter_number_from, verse_number_from,
                           chapter_number_to, verse_number_to, text
                    FROM commentaries ORDER BY book_number, chapter_number_from, verse_number_from');

$chapters = [];           // book => chapter => [range => html]
$count = 0;
$unknown = [];
foreach ($rows as $r) {
    $code = MYBIBLE_BOOKS[(int)$r['book_number']] ?? null;
    if (!$code) {
        $unknown[(int)$r['book_number']] = true;
        continue;
    }
    $html = commentary_clean_html((string)$r['text']);
    if ($html === '') {
        continue;
    }
    $from = (int)$r['chapter_number_from'];
    $to = max($from, (int)$r['chapter_number_to']);
    $v1 = max(1, (int)$r['verse_number_from']);
    $v2 = max($v1, (int)$r['verse_number_to']);
    // An entry that runs across a chapter boundary is filed under each chapter it touches.
    for ($c = $from; $c <= $to; $c++) {
        $chapters[$code][$c][] = [
            'from' => $c === $from ? $v1 : 1,
            'to'   => $c === $to ? $v2 : $endOf($code, $c),
            'html' => $html,
        ];
    }
    $count++;
}

// A work written in sections: each comment reaches to the verse before the next one starts.
if ($sections) {
    foreach ($chapters as $code => $bookChapters) {
        foreach ($bookChapters as $c => $entries) {
            usort($entries, fn($a, $b) => $a['from'] <=> $b['from']);
            $end = $endOf($code, $c);
            foreach ($entries as $i => &$e) {
                $next = $entries[$i + 1]['from'] ?? $end + 1;
                $e['to'] = min($end, max($e['to'], $next - 1));
            }
            unset($e);
            $chapters[$code][$c] = $entries;
        }
    }
}

// Entries become a map from verse range to text, which is how a chapter is stored.
foreach ($chapters as $code => $bookChapters) {
    foreach ($bookChapters as $c => $entries) {
        $map = [];
        foreach ($entries as $e) {
            $key = $e['from'] === $e['to'] ? (string)$e['from'] : $e['from'] . '-' . $e['to'];
            $range = array_flip(range($e['from'], min($e['to'], $endOf($code, $c))));
            $map[$key] = ($map[$key] ?? '') . drop_reprinted_passage($e['html'],
                array_intersect_key($openings[$code][$c] ?? [], $range),
                array_intersect_key($verses[$code][$c] ?? [], $range));
        }
        $chapters[$code][$c] = $map;
    }
}

// Some modules have been prepared with the scripture references stripped out of the text, which
// leaves sentences dangling ("as we have seen, ,"). Say so plainly rather than importing quietly.
$sample = '';
foreach ($chapters as $bookChapters) {
    foreach ($bookChapters as $entries) {
        $sample .= implode(' ', $entries);
        if (strlen($sample) > 400000) {
            break 2;
        }
    }
}
$plain = strip_tags($sample);
// Both styles count: "Romans 8:28" and the older "Rom. viii. 28" that Haydock and Hodge use.
$books = 'Gen|Exod?|Lev|Num|Deut?|Josh|Judg|Sam|King?|Chron|Ps|Prov|Isa|Jer|Ezek|Dan|Matt?|Mark|Luke|John|Acts|Rom|Cor|Gal|Eph|Phil|Col|Thess|Tim|Tit|Heb|Jas|Pet|Jude|Rev';
$refs = preg_match_all("/\\b(?:$books)\\w*\\.?\\s+(?:\\d+[:.]\\d+|[ivxlc]+\\.\\s*\\d+)/u", $plain);
$dangling = preg_match_all('/(?:\b(?:in|see|compare|cf\.?)\s*[,.]|,\s*[,.]|\(\s*\))/u', $plain);

$books = count($chapters);
$chapterCount = array_sum(array_map('count', $chapters));
echo "$count entries · $books books · $chapterCount chapters\n";
echo "References in the text: $refs kept, $dangling looking dropped",
     $refs < $dangling ? "  ← this module has had its references stripped\n" : "\n";
if ($unknown) {
    echo "Book numbers not in the Protestant canon, left out: ", implode(', ', array_keys($unknown)), "\n";
}
if ($dry) {
    exit(0);
}

$words = 0;
foreach ($chapters as $code => $bookChapters) {
    foreach ($bookChapters as $c => $entries) {
        if (!$force && q('SELECT 1 FROM bible_commentary WHERE work = ? AND book = ? AND chapter = ?',
                [$work, $code, $c])->fetchColumn()) {
            continue;
        }
        $words += commentary_store($work, $code, $c, $entries);
    }
}
q('UPDATE bible_works SET source = ? WHERE code = ?',
    [substr($info['description'] ?? basename($file), 0, 160), $work]);
echo "Imported into “{$work}”: ", commentary_count_chapters($work), " chapters, ",
     number_format($words), " words.\n";
