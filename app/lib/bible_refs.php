<?php
// Finding Bible references in ordinary writing: "John 3:16", "1 Jn 2:1-3", "Rom 8:28, 38",
// "Psalm 23", "Matt 5:1—7:29". Used for links in chat messages, for the daily reading's schedule,
// and for the search box.
//
// It refuses anything that isn't plainly a reference: a bare "3:16", or "1 Peter Street", or a
// time of day. A missed link is better than a wrong one in the middle of someone's sentence.

require_once APP_DIR . '/lib/bible_canon.php';

// Every spelling we accept, mapped to a book code. Built once from the canon, plus the usual
// short forms. Longest first, so "Song of Solomon" is matched before "Song".
function bible_ref_names(): array
{
    static $names = null;
    if ($names !== null) {
        return $names;
    }
    $extra = [
        'GEN' => ['gen', 'ge', 'gn'], 'EXO' => ['exo', 'exod', 'ex'], 'LEV' => ['lev', 'lv'],
        'NUM' => ['num', 'nu', 'nm'], 'DEU' => ['deut', 'deu', 'dt'], 'JOS' => ['josh', 'jos'],
        'JDG' => ['judg', 'jdg', 'jg'], 'RUT' => ['ruth', 'rut', 'ru'],
        '1SA' => ['1 samuel', '1samuel', '1 sam', '1sam', '1 sa', '1sa', 'i samuel'],
        '2SA' => ['2 samuel', '2samuel', '2 sam', '2sam', '2 sa', '2sa', 'ii samuel'],
        '1KI' => ['1 kings', '1kings', '1 kgs', '1kgs', '1 ki', '1ki'],
        '2KI' => ['2 kings', '2kings', '2 kgs', '2kgs', '2 ki', '2ki'],
        '1CH' => ['1 chronicles', '1chronicles', '1 chron', '1 chr', '1chr', '1 ch', '1ch'],
        '2CH' => ['2 chronicles', '2chronicles', '2 chron', '2 chr', '2chr', '2 ch', '2ch'],
        'EZR' => ['ezra', 'ezr'], 'NEH' => ['neh', 'ne'], 'EST' => ['esth', 'est'],
        'JOB' => ['job'], 'PSA' => ['psalms', 'psalm', 'pss', 'psa', 'ps'],
        'PRO' => ['proverbs', 'prov', 'pro', 'prv'], 'ECC' => ['ecclesiastes', 'eccles', 'eccl', 'ecc'],
        'SNG' => ['song of solomon', 'song of songs', 'songs', 'song', 'sos', 'sng'],
        'ISA' => ['isaiah', 'isa', 'is'], 'JER' => ['jeremiah', 'jer', 'jr'],
        'LAM' => ['lamentations', 'lam'], 'EZK' => ['ezekiel', 'ezek', 'ezk', 'eze'],
        'DAN' => ['daniel', 'dan', 'dn'], 'HOS' => ['hosea', 'hos'], 'JOL' => ['joel', 'joe', 'jol'],
        'AMO' => ['amos', 'amo', 'am'], 'OBA' => ['obadiah', 'obad', 'oba'], 'JON' => ['jonah', 'jon'],
        'MIC' => ['micah', 'mic'], 'NAM' => ['nahum', 'nah', 'nam'], 'HAB' => ['habakkuk', 'hab'],
        'ZEP' => ['zephaniah', 'zeph', 'zep'], 'HAG' => ['haggai', 'hag'],
        'ZEC' => ['zechariah', 'zech', 'zec'], 'MAL' => ['malachi', 'mal'],
        'MAT' => ['matthew', 'matt', 'mat', 'mt'], 'MRK' => ['mark', 'mrk', 'mk'],
        'LUK' => ['luke', 'luk', 'lk'], 'JHN' => ['john', 'jhn', 'jn'], 'ACT' => ['acts', 'act'],
        'ROM' => ['romans', 'rom', 'ro'],
        '1CO' => ['1 corinthians', '1corinthians', '1 cor', '1cor', '1 co', '1co'],
        '2CO' => ['2 corinthians', '2corinthians', '2 cor', '2cor', '2 co', '2co'],
        'GAL' => ['galatians', 'gal'], 'EPH' => ['ephesians', 'eph'],
        'PHP' => ['philippians', 'phil', 'php'], 'COL' => ['colossians', 'col'],
        '1TH' => ['1 thessalonians', '1thessalonians', '1 thess', '1thess', '1 th', '1th'],
        '2TH' => ['2 thessalonians', '2thessalonians', '2 thess', '2thess', '2 th', '2th'],
        '1TI' => ['1 timothy', '1timothy', '1 tim', '1tim', '1 ti', '1ti'],
        '2TI' => ['2 timothy', '2timothy', '2 tim', '2tim', '2 ti', '2ti'],
        'TIT' => ['titus', 'tit'], 'PHM' => ['philemon', 'philem', 'phm'],
        'HEB' => ['hebrews', 'heb'], 'JAS' => ['james', 'jas'],
        '1PE' => ['1 peter', '1peter', '1 pet', '1pet', '1 pe', '1pe'],
        '2PE' => ['2 peter', '2peter', '2 pet', '2pet', '2 pe', '2pe'],
        '1JN' => ['1 john', '1john', '1 jn', '1jn', 'i john'],
        '2JN' => ['2 john', '2john', '2 jn', '2jn'], '3JN' => ['3 john', '3john', '3 jn', '3jn'],
        'JUD' => ['jude'], 'REV' => ['revelation', 'revelations', 'rev'],
    ];
    $names = [];
    foreach (BIBLE_CANON as $code => [$name]) {
        $names[strtolower($name)] = $code;
        foreach ($extra[$code] ?? [] as $alias) {
            $names[$alias] = $code;
        }
    }
    uksort($names, fn($a, $b) => strlen($b) <=> strlen($a));   // longest name wins
    return $names;
}

// Finds every reference in a piece of text. Returns, for each:
//   ['start', 'length', 'text', 'book', 'chapter', 'verse', 'end_chapter', 'end_verse', 'ref']
// where verse is 0 for a whole chapter.
function bible_find_refs(string $text, int $limit = 40): array
{
    $names = array_keys(bible_ref_names());
    $codes = bible_ref_names();
    $alt = implode('|', array_map(fn($n) => preg_quote($n, '/'), $names));
    // book, chapter, then optionally :verse, a range, and a list of further verses.
    // A verse may be written as half of one — "6:12b-19a" — which the schedule does where a
    // passage begins or ends mid-verse. The letter is taken and ignored, so the whole verse is
    // quoted: better a little more than the wrong thing, and without it "6:1-6:12a" was read as
    // 6:1-6 and "6:12b-19a" as the whole chapter.
    // Two further shapes the schedule uses. A range may name its book again at the far end
    // ("Genesis 27:41-Genesis 28:22"), which otherwise reads as two lone verses with everything
    // between them missing. And a run of whole chapters may be written without any verse at all
    // ("2 Chronicles 1-15"), which otherwise stops at the first chapter.
    $re = '/(?<![\p{L}\p{N}])(' . $alt . ')\.?\s*(\d{1,3})'
        . '(?:'
            . '\s*[:.]\s*(\d{1,3})[a-c]?'
            . '(?:\s*[-–—]\s*(?:(?:' . $alt . ')\.?\s*)?(?:(\d{1,3})\s*[:.]\s*)?(\d{1,3})[a-c]?)?'
        . '|'
            . '\s*[-–—]\s*(\d{1,3})(?![:.\d])'
        . ')?'
        . '(?![\p{L}\p{N}])/iu';
    if (!preg_match_all($re, $text, $ms, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        return [];
    }
    $out = [];
    foreach ($ms as $m) {
        if (count($out) >= $limit) {
            break;
        }
        $code = $codes[strtolower(preg_replace('/\s+/u', ' ', $m[1][0]))] ?? null;
        if (!$code) {
            continue;
        }
        [$name, $chapters] = BIBLE_CANON[$code];
        $chapter = (int)$m[2][0];
        $verse = isset($m[3]) && $m[3][0] !== '' ? (int)$m[3][0] : 0;
        $end_chapter = isset($m[4]) && $m[4][0] !== '' ? (int)$m[4][0] : $chapter;
        $end_verse = isset($m[5]) && $m[5][0] !== '' ? (int)$m[5][0] : $verse;
        // "2 Chronicles 1-15": whole chapters, no verses named at either end.
        if (isset($m[6]) && $m[6][0] !== '') {
            $end_chapter = (int)$m[6][0];
            $verse = 0;
            $end_verse = 0;
        }
        // A one-chapter book written as "Jude 5" means verse 5, not chapter 5 — and "Jude 5-7"
        // means those verses, not those chapters.
        if ($chapters === 1 && $verse === 0) {
            $verse = $chapter;
            $end_verse = $end_chapter > $chapter ? $end_chapter : $verse;
            $chapter = 1;
            $end_chapter = 1;
        }
        if ($chapter < 1 || $chapter > $chapters) {
            continue;
        }
        $out[] = [
            'start'  => $m[0][1],
            'length' => strlen($m[0][0]),
            'text'   => $m[0][0],
            'book'   => $code,
            'name'   => $name,
            'chapter' => $chapter,
            'verse'  => $verse,
            'end_chapter' => max($chapter, $end_chapter),
            'end_verse'   => $end_verse,
            // What the passage is called above its text. A range that crosses a chapter has to say
            // so — "Galatians 3:15-4:20", not "3:15-20", which names a fifth of what is shown.
            'ref'    => bible_ref_label($name, $chapter, $verse, max($chapter, $end_chapter), $end_verse),
        ];
    }
    return $out;
}

// "Psalm 23", "John 3:16", "1 John 2:1-3", "Galatians 3:15-4:20", "2 Chronicles 1-15".
function bible_ref_label(string $name, int $chapter, int $verse, int $end_chapter, int $end_verse): string
{
    if (!$verse) {
        return $name . ' ' . $chapter . ($end_chapter > $chapter ? '-' . $end_chapter : '');
    }
    if ($end_chapter > $chapter) {
        return $name . ' ' . $chapter . ':' . $verse . '-' . $end_chapter . ':' . ($end_verse ?: 1);
    }
    return $name . ' ' . $chapter . ':' . $verse . ($end_verse > $verse ? '-' . $end_verse : '');
}

// The passages a day's reading covers, from the schedule's own wording ("1 Chronicles 17; Ps 23").
function bible_reading_refs(string $line): array
{
    return bible_find_refs($line, 12);
}
