<?php
// The Bible reader's server side: the books, a chapter with its notes, and where each member was
// reading. The texts are imported by cli/import_bible.php; nothing here writes to them.
// The feature as a whole is switched on by config('bible_reader').

require_once APP_DIR . '/lib/bootstrap.php';
require_once APP_DIR . '/lib/bible_canon.php';

// Which version to open for someone: the last one they read in, or the first one configured
// (the site's default) if they haven't read yet. Their choice is remembered because every place
// saved carries its version.
function bible_version_for(int $user_id, string $asked = ''): string
{
    if ($asked !== '' && in_array($asked, bible_versions(), true)) {
        return $asked;
    }
    $last = q('SELECT version FROM bible_state WHERE user_id = ? ORDER BY updated_at DESC LIMIT 1',
        [$user_id])->fetchColumn();
    return $last && in_array($last, bible_versions(), true) ? $last : bible_default_version();
}

// The versions that are actually imported, in the order they should be offered.
require_once __DIR__ . '/api_bible.php';

// Some translations are ours and some are fetched from API.Bible a chapter at a time. Anything
// that works off the flat verse table — search, the text under a cross-reference — has no rows for
// a fetched one, so it reads the default translation instead and says so.
function bible_text_version(string $version): string
{
    return api_bible_is($version) ? bible_default_version() : $version;
}

function bible_versions(): array
{
    static $v = null;
    if ($v === null) {
        $want = (array)(config('bible_versions') ?: ['WEB', 'BSB', 'KJV']);
        $have = q('SELECT DISTINCT version FROM bible_books')->fetchAll(PDO::FETCH_COLUMN);
        $v = array_values(array_intersect($want, $have));
    }
    return $v;
}

// Some translations we hold are freely licensed rather than public domain, and their licence asks
// to be named. The fetched ones carry their publisher's own line; these carry ours.
function bible_notice(string $version): string
{
    return [
        'LSV' => 'Literal Standard Version © 2020 Covenant Press, used under CC BY-SA 4.0.',
    ][$version] ?? '';
}

// The line a translation asks to be named by. Ours that are freely licensed say so themselves;
// the fetched ones carry their publisher's words, taken from a chapter already in hand rather than
// from a request of its own.
function bible_version_notice(string $version): string
{
    if (!api_bible_is($version)) {
        return bible_notice($version);
    }
    return (string)q('SELECT copyright FROM bible_remote_chapters WHERE version = ? AND copyright <> "" LIMIT 1',
        [$version])->fetchColumn();
}

function bible_default_version(): string
{
    return bible_versions()[0] ?? '';
}

function bible_version_ok(string $version): string
{
    return in_array($version, bible_versions(), true) ? $version : bible_default_version();
}

// All 66 books of a version: code, name, abbreviation, chapter count, testament, Bible Hub slug.
function bible_books(string $version): array
{
    static $cache = [];
    return $cache[$version] ??= q('SELECT code, name, abbrev, chapters, testament, biblehub FROM bible_books
                                   WHERE version = ? ORDER BY ord', [$version])->fetchAll();
}

function bible_book(string $version, string $code): ?array
{
    foreach (bible_books($version) as $b) {
        if ($b['code'] === $code) {
            return $b;
        }
    }
    return null;
}

// A chapter: its rendered HTML, its notes, and which chapter comes before and after (across book
// boundaries, so reading straight through works).
function bible_chapter(string $version, string $code, int $chapter): ?array
{
    $book = bible_book($version, $code);
    if (!$book || $chapter < 1 || $chapter > (int)$book['chapters']) {
        return null;
    }
    $notice = '';
    if (api_bible_is($version)) {
        $got = api_bible_chapter($version, $code, $chapter);
        if (!$got) {
            return null;
        }
        $html = $got['html'];
        $notice = $got['copyright'];
        $notes = [];                      // the fetched text is asked for without them
    } else {
        $html = q('SELECT html FROM bible_chapters WHERE version = ? AND book = ? AND chapter = ?',
            [$version, $code, $chapter])->fetchColumn();
        if ($html === false) {
            return null;
        }
        $notes = q('SELECT verse, kind, marker, body, refs FROM bible_notes
                    WHERE version = ? AND book = ? AND chapter = ? ORDER BY id', [$version, $code, $chapter])->fetchAll();
        $notice = bible_notice($version);
    }
    return [
        'version' => $version,
        'book'    => $code,
        'name'    => $book['name'],
        'biblehub' => $book['biblehub'],
        'chapter' => $chapter,
        'chapters' => (int)$book['chapters'],
        'html'    => $html,
        'notice'  => $notice,          // the publisher's copyright line, for a fetched translation
        'notes'   => $notes,
        'xrefs'   => bible_xref_verses($code, $chapter),
        'prev'    => bible_step($version, $code, $chapter, -1),
        'next'    => bible_step($version, $code, $chapter, 1),
    ];
}

// The chapter before or after this one, or null at the ends of the Bible.
function bible_step(string $version, string $code, int $chapter, int $dir): ?array
{
    $books = bible_books($version);
    $i = 0;
    foreach ($books as $n => $b) {
        if ($b['code'] === $code) {
            $i = $n;
            break;
        }
    }
    $c = $chapter + $dir;
    if ($c >= 1 && $c <= (int)$books[$i]['chapters']) {
        return ['book' => $code, 'chapter' => $c, 'name' => $books[$i]['name']];
    }
    $j = $i + $dir;
    if (!isset($books[$j])) {
        return null;
    }
    return [
        'book'    => $books[$j]['code'],
        'chapter' => $dir > 0 ? 1 : (int)$books[$j]['chapters'],
        'name'    => $books[$j]['name'],
    ];
}

// Which verses of a chapter to underline as having cross-references. Nearly every verse has some,
// so only those with a well-supported one are marked (openbible.info's votes); otherwise the page
// would be underlined from top to bottom. The dialog still shows everything for the verse tapped.
const BIBLE_XREF_VOTES = 10;

function bible_xref_verses(string $code, int $chapter): array
{
    return array_map('intval', q('SELECT DISTINCT verse FROM bible_xrefs WHERE book = ? AND chapter = ? AND votes >= ?
                                  ORDER BY verse', [$code, $chapter, BIBLE_XREF_VOTES])->fetchAll(PDO::FETCH_COLUMN));
}

// The cross-references for one verse, strongest first, each with the passage's own text in the
// version being read, so the list can be read without following anything.
function bible_xrefs(string $version, string $code, int $chapter, int $verse, int $limit = 24): array
{
    $rows = q('SELECT to_book, to_chapter, to_verse, to_end, votes FROM bible_xrefs
               WHERE book = ? AND chapter = ? AND verse = ? ORDER BY votes DESC, to_book, to_chapter, to_verse
               LIMIT ?', [$code, $chapter, $verse, $limit])->fetchAll();
    $names = [];
    foreach (bible_books($version) as $b) {
        $names[$b['code']] = $b['name'];
    }
    $out = [];
    foreach ($rows as $r) {
        $text = q('SELECT GROUP_CONCAT(text ORDER BY verse SEPARATOR " ") FROM bible_verses
                   WHERE version = ? AND book = ? AND chapter = ? AND verse BETWEEN ? AND ?',
            [bible_text_version($version), $r['to_book'], $r['to_chapter'], $r['to_verse'], $r['to_end']])->fetchColumn();
        if (!$text) {
            continue;
        }
        $ref = ($names[$r['to_book']] ?? $r['to_book']) . ' ' . $r['to_chapter'] . ':' . $r['to_verse']
            . ((int)$r['to_end'] > (int)$r['to_verse'] ? '-' . $r['to_end'] : '');
        $out[] = [
            'ref' => $ref, 'book' => $r['to_book'], 'chapter' => (int)$r['to_chapter'],
            'verse' => (int)$r['to_verse'], 'text' => $text,
        ];
    }
    return $out;
}

// Where someone was reading: a place per book (so every book keeps its own), per version.
function bible_place(int $user_id, string $version, ?string $code = null): ?array
{
    if ($code !== null) {
        $row = q('SELECT book, chapter, verse FROM bible_state WHERE user_id = ? AND version = ? AND book = ?',
            [$user_id, $version, $code])->fetch();
        return $row ?: null;
    }
    $row = q('SELECT book, chapter, verse FROM bible_state WHERE user_id = ? AND version = ?
              ORDER BY updated_at DESC LIMIT 1', [$user_id, $version])->fetch();
    return $row ?: null;
}

function bible_save_place(int $user_id, string $version, string $code, int $chapter, int $verse = 1): void
{
    q('INSERT INTO bible_state (user_id, version, book, chapter, verse) VALUES (?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE chapter = VALUES(chapter), verse = VALUES(verse), updated_at = UTC_TIMESTAMP()',
        [$user_id, $version, $code, $chapter, max(1, $verse)]);
    // …and remember that this chapter was open, for the Recent list.
    q('INSERT INTO bible_history (user_id, version, book, chapter) VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE seen_at = UTC_TIMESTAMP()', [$user_id, $version, $code, $chapter]);
}

// The chapters this member has had open lately, newest first, for the Recent list in the picker.
// Older rows are tidied away so the table can't grow without end.
function bible_recent(int $user_id, int $limit = 12): array
{
    $rows = q('SELECT version, book, chapter, seen_at FROM bible_history WHERE user_id = ?
               ORDER BY seen_at DESC LIMIT ?', [$user_id, $limit + 1])->fetchAll();
    if (count($rows) > $limit) {
        q('DELETE FROM bible_history WHERE user_id = ? AND seen_at < ?',
            [$user_id, $rows[$limit]['seen_at']]);
        $rows = array_slice($rows, 0, $limit);
    }
    $names = [];
    $out = [];
    foreach ($rows as $r) {
        $names[$r['version']] ??= array_column(bible_books($r['version']), 'name', 'code');
        $out[] = [
            'version' => $r['version'], 'book' => $r['book'], 'chapter' => (int)$r['chapter'],
            'name' => $names[$r['version']][$r['book']] ?? $r['book'],
        ];
    }
    return $out;
}

// ---------- a member's own settings ----------
// Kept with the account (as well as on the device), so a new phone reads the way the old one did.
function user_pref(int $user_id, string $name, $default = null)
{
    $raw = q('SELECT value FROM user_prefs WHERE user_id = ? AND name = ?', [$user_id, $name])->fetchColumn();
    if ($raw === false) {
        return $default;
    }
    $v = json_decode($raw, true);
    return $v === null ? $default : $v;
}

function set_user_pref(int $user_id, string $name, $value): void
{
    q('INSERT INTO user_prefs (user_id, name, value) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = UTC_TIMESTAMP()',
        [$user_id, $name, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

// ---------- passages (a range of verses, for today's reading) ----------

// A range of verses as HTML, taken out of the chapters' own rendering so paragraphs, poetry and
// headings survive. Verses outside the range are dropped, and so are paragraphs left empty.
function bible_passage_html(string $version, string $book, int $chapter, int $verse, int $end_chapter, int $end_verse): string
{
    $out = '';
    for ($c = $chapter; $c <= $end_chapter; $c++) {
        $ch = bible_chapter($version, $book, $c);
        if (!$ch) {
            continue;
        }
        $from = ($c === $chapter && $verse) ? $verse : 1;
        $to = ($c === $end_chapter && $end_verse) ? $end_verse : 9999;
        // Wrapped, and labelled with the book and chapter it came from. A day's reading is several
        // passages on one page, and without this everything on it — commentary, cross-references,
        // marks — falls back to whichever chapter happened to come first.
        $out .= '<section class="b-part" data-book="' . h($book) . '" data-chapter="' . $c . '">'
            . ($end_chapter > $chapter ? '<h3 class="b-s">' . h($ch['name'] . ' ' . $c) . '</h3>' : '')
            . bible_slice($ch['html'], $from, $to)
            . '</section>';
    }
    return $out;
}

// Keeps only the verses between $from and $to in a chapter's HTML.
function bible_slice(string $html, int $from, int $to): string
{
    if ($from <= 1 && $to >= 9999) {
        return $html;
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?><div id="w">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    foreach (iterator_to_array($xp->query('//span[@class="v"]')) as $span) {
        $v = (int)$span->getAttribute('data-v');
        if ($v < $from || $v > $to) {
            $span->parentNode->removeChild($span);
        }
    }
    // Headings and paragraphs left with nothing in them go too.
    foreach (iterator_to_array($xp->query('//p|//h2|//h3|//h4|//div')) as $p) {
        if ($p->getAttribute('id') !== 'w' && trim($p->textContent) === '') {
            $p->parentNode->removeChild($p);
        }
    }

    // A heading is never empty, so the sweep above leaves it even when every verse it introduced
    // has gone — 1 Chronicles 11:20-47 arrived under three headings belonging to verses 1 to 19.
    // A heading is kept only if some verse still follows it before the next one does.
    $w = $doc->getElementById('w');
    $block = [];                            // the heading being considered, and what trails it
    $drop = [];
    foreach (iterator_to_array($w->childNodes) as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }
        if ($xp->query('.//span[@class="v"]', $child)->length > 0) {
            $block = [];                    // a verse survived under it, so the heading stays
            continue;
        }
        if (in_array(strtolower($child->nodeName), ['h2', 'h3', 'h4'], true)) {
            // One heading straight after another means the first one's verses have all gone.
            $drop = array_merge($drop, $block);
            $block = [$child];
            continue;
        }
        $block[] = $child;                  // a reference line or a blank, belonging to the above
    }
    foreach (array_merge($drop, $block) as $orphan) {
        $orphan->parentNode->removeChild($orphan);
    }

    $out = '';
    foreach ($w->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

// A day's reading: the passages its schedule line names, each with its text.
function bible_reading_day(string $version, string $date): array
{
    require_once APP_DIR . '/lib/reading.php';
    require_once APP_DIR . '/lib/bible_refs.php';
    $out = [];
    foreach (readings_for($date) as $line) {
        foreach (bible_reading_refs($line) as $r) {
            $html = bible_passage_html($version, $r['book'], $r['chapter'], $r['verse'], $r['end_chapter'], $r['end_verse']);
            if ($html !== '') {
                $out[] = ['ref' => $r['ref'], 'book' => $r['book'], 'chapter' => $r['chapter'], 'html' => $html];
            }
        }
    }
    return $out;
}

// Marks today's reading finished for someone, and votes "finished" in the day's poll if there is
// one, so the topic's poll and the reader agree.
function bible_reading_finished(int $user_id, string $date): void
{
    q('INSERT INTO bible_reading_state (user_id, reading_date, book, chapter, verse, finished_at)
       VALUES (?, ?, "", 0, 0, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE finished_at = UTC_TIMESTAMP()', [$user_id, $date]);
    $poll = q('SELECT poll_message_id FROM reading_posts WHERE reading_date = ?', [$date])->fetchColumn();
    if (!$poll) {
        return;
    }
    $option = q('SELECT id FROM poll_options WHERE message_id = ? AND text LIKE ? ORDER BY position LIMIT 1',
        [$poll, '%✅%'])->fetchColumn();
    if ($option) {
        require_once APP_DIR . '/lib/actions.php';
        try {
            vote(q("SELECT * FROM users WHERE id = ?", [$user_id])->fetch(), (int)$poll, [(int)$option]);
        } catch (Throwable $e) {
            /* already voted, or the poll is closed: leave it */
        }
    }
}

// Where someone had got to in a day's reading.
function bible_reading_place(int $user_id, string $date): ?array
{
    return q('SELECT book, chapter, verse, finished_at FROM bible_reading_state WHERE user_id = ? AND reading_date = ?',
        [$user_id, $date])->fetch() ?: null;
}

// A whole book, for someone downloading a version to read with no signal. Chapters come as they
// are stored (already-rendered HTML) with their notes and the verses that carry cross-references.
function bible_book_bundle(string $version, string $code): ?array
{
    $book = bible_book($version, $code);
    if (!$book || api_bible_is($version)) {
        return null;
    }
    $chapters = [];
    foreach (q('SELECT chapter, html FROM bible_chapters WHERE version = ? AND book = ? ORDER BY chapter',
        [$version, $code])->fetchAll() as $row) {
        $n = (int)$row['chapter'];
        $chapters[] = [
            'chapter' => $n,
            'html'    => $row['html'],
            'notes'   => q('SELECT verse, kind, marker, body, refs FROM bible_notes
                            WHERE version = ? AND book = ? AND chapter = ? ORDER BY id', [$version, $code, $n])->fetchAll(),
            'xrefs'   => bible_xref_verses($code, $n),
        ];
    }
    return ['book' => $code, 'name' => $book['name'], 'biblehub' => $book['biblehub'], 'chapters' => $chapters];
}

// ---------- search ----------

// The search box. One query, over as many translations as are ticked, across whichever books are
// chosen. What it understands:
//
//   John 3:16            a reference on its own — where to go, rather than what to find
//   mercy                every verse with a word beginning "mercy": mercy, merciful, mercies
//   faith works          both words, in any order
//   faith OR works       either word
//   faith -works         faith, but not where works appears
//   "a still small voice"  those words in that order
//   loving*              the * may be written out; a bare word gets one anyway
//
// Only translations we hold are searched. The ones read from API.Bible are excluded here and in
// the panel that calls this: their text is theirs, fetched a chapter at a time as it is read.
function bible_searchable_versions(): array
{
    return array_values(array_diff(bible_versions(), api_bible_versions()));
}

// A query in the little language above, as one MySQL boolean-mode expression.
// Returns [expression, [phrases to confirm literally], [the words being looked for]].
function bible_boolean(string $query): array
{
    // Split into terms, keeping quoted phrases whole.
    preg_match_all('/(-|NOT\s+)?"([^"]+)"|(-|NOT\s+)?(\S+)/ui', $query, $m, PREG_SET_ORDER);

    $groups = [[]];              // terms, split into OR-groups
    $phrases = [];
    $terms = [];                 // what a verse is scored on: the words asked for, not the excluded
    foreach ($m as $t) {
        $raw = $t[2] !== '' ? $t[2] : ($t[4] ?? '');
        $neg = trim(($t[1] ?? '') . ($t[3] ?? '')) !== '';
        $quoted = $t[2] !== '';
        if (!$quoted && preg_match('/^(OR|\|\|)$/i', $raw)) {
            $groups[] = [];       // what follows is an alternative
            continue;
        }
        if (!$quoted && preg_match('/^AND$/i', $raw)) {
            continue;             // the default already
        }
        if ($quoted) {
            $clean = str_replace('"', '', $raw);
            if (trim($clean) === '') {
                continue;
            }
            if (!$neg) {
                $phrases[] = $clean;
                $terms[] = $clean;
            }
            $groups[count($groups) - 1][] = ($neg ? '-' : '+') . '"' . $clean . '"';
            continue;
        }
        // A bare word: strip what boolean mode would read as an operator, then make it a prefix,
        // so "descen" finds descend, descended and descendants without anyone typing a star.
        $word = str_replace(['+', '-', '(', ')', '~', '<', '>', '@', '"'], '', $raw);
        $word = rtrim($word, '*');
        if (mb_strlen($word) < 2) {
            continue;
        }
        if (!$neg) {
            $terms[] = $word;
        }
        $groups[count($groups) - 1][] = ($neg ? '-' : '+') . $word . '*';
    }

    $groups = array_values(array_filter($groups, fn($g) => (bool)$g));
    $terms = array_values(array_unique($terms));
    if (!$groups) {
        return ['', [], []];
    }
    if (count($groups) === 1) {
        return [implode(' ', $groups[0]), $phrases, $terms];
    }
    // Several alternatives: each becomes an optional group, and a row matching any of them counts.
    return [implode(' ', array_map(fn($g) => '(' . implode(' ', $g) . ')', $groups)), $phrases, $terms];
}

// The verses a reference names, as each translation has them, in the shape search results take.
// A reference with no verse ("John 3") means the whole chapter.
function bible_passage_hits(array $versions, array $ref, string $first, int $limit): array
{
    if (!$versions) {
        return [];
    }
    $code = $ref['book'];
    $c1 = (int)$ref['chapter'];
    $v1 = (int)$ref['verse'] ?: 1;
    $c2 = (int)($ref['end_chapter'] ?? $c1) ?: $c1;
    $v2 = (int)($ref['end_verse'] ?? 0) ?: ((int)$ref['verse'] ? (int)$ref['verse'] : 9999);
    if ($c2 > $c1 && !(int)($ref['end_verse'] ?? 0)) {
        $v2 = 9999;
    }

    $in = implode(',', array_fill(0, count($versions), '?'));
    $rows = q("SELECT version, chapter, verse, text FROM bible_verses
               WHERE version IN ($in) AND book = ?
                 AND (chapter > ? OR (chapter = ? AND verse >= ?))
                 AND (chapter < ? OR (chapter = ? AND verse <= ?))
               ORDER BY chapter, verse",
        array_merge($versions, [$code, $c1, $c1, $v1, $c2, $c2, $v2]))->fetchAll();
    if (!$rows) {
        return [];
    }

    $order = array_flip(bible_searchable_versions());
    $names = array_column(bible_books($first), 'name', 'code');
    $by = [];
    foreach ($rows as $r) {
        $by[(int)$r['chapter'] . '.' . (int)$r['verse']][$r['version']] = $r['text'];
    }
    $hits = [];
    foreach ($by as $key => $texts) {
        [$c, $v] = array_map('intval', explode('.', $key));
        uksort($texts, fn($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        $shown = array_key_first($texts);
        $hits[] = [
            'book' => $code, 'chapter' => $c, 'verse' => $v,
            'ref' => ($names[$code] ?? $code) . ' ' . $c . ':' . $v,
            'version' => $shown,
            'versions' => array_keys($texts),
            'text' => $texts[$shown],
            'texts' => $texts,
        ];
        if (count($hits) >= $limit) {
            break;
        }
    }
    return $hits;
}

function bible_term_count(string $text, array $terms): int
{
    $n = 0;
    foreach ($terms as $t) {
        $pattern = str_contains($t, ' ')
            ? '/' . preg_quote($t, '/') . '/iu'              // a phrase, as written
            : '/\b' . preg_quote($t, '/') . '\w*/iu';       // a word, and the words it begins
        $n += preg_match_all($pattern, $text);
    }
    return $n;
}

// $versions: one code or several. $scope: 'all', 'ot', 'nt', a book code, or a list of book codes.
// Returns ['goto' => …, 'hits' => […], 'total' => n, 'versions' => […], 'unsearchable' => […]].
function bible_search($versions, string $query, $scope = 'all', int $limit = 60): array
{
    require_once APP_DIR . '/lib/bible_refs.php';
    $asked = array_values(array_unique((array)$versions));
    $wanted = array_values(array_intersect($asked, bible_searchable_versions()));
    $unsearchable = array_values(array_intersect($asked, api_bible_versions()));
    $none = ['goto' => null, 'hits' => [], 'total' => 0, 'versions' => $wanted, 'unsearchable' => $unsearchable];

    $query = trim(preg_replace('/\s+/u', ' ', $query));
    if ($query === '' || !$wanted) {
        return $none;
    }
    $first = $wanted[0];

    // A reference on its own is a request for those words, not for a link to them.
    $refs = bible_find_refs($query);
    $ref = ($refs && $refs[0]['length'] >= strlen($query) - 2 && bible_book($first, $refs[0]['book'])) ? $refs[0] : null;
    $goto = $ref ? ['book' => $ref['book'], 'chapter' => $ref['chapter'], 'verse' => $ref['verse'], 'ref' => $ref['ref']] : null;

    // "Jn 3:16", "John 3:16-18", "John 3:16-4:2", or a whole chapter: the verses themselves, in
    // every translation that has them, each with its names to swap between.
    if ($ref) {
        $hits = bible_passage_hits($wanted, $ref, $first, $limit);
        if ($hits) {
            return ['goto' => null, 'hits' => $hits, 'total' => count($hits),
                    'versions' => $wanted, 'unsearchable' => $unsearchable, 'words' => []];
        }
    }

    [$boolean, $phrases, $terms] = bible_boolean($query);
    if ($boolean === '') {
        return ['goto' => $goto] + $none;
    }

    [$where, $args] = bible_scope_sql($scope);
    $vin = implode(',', array_fill(0, count($wanted), '?'));
    $sql = 'SELECT version, book, chapter, verse, text, MATCH(text) AGAINST (? IN BOOLEAN MODE) score
            FROM bible_verses WHERE version IN (' . $vin . ')' . $where . '
              AND MATCH(text) AGAINST (? IN BOOLEAN MODE)
            ORDER BY score DESC, book, chapter, verse LIMIT ?';
    // Room for the same verse from every translation asked for, since they collapse into one hit.
    $rows = q($sql, array_merge([$boolean], $wanted, $args, [$boolean, $limit * count($wanted) * 2]))->fetchAll();

    // One verse, one hit, however many translations it turned up in. The wording shown is the one
    // that uses the words most often; where two use them equally the KJV wins, and failing that the
    // order the translations are listed in. The rest are named beside it, to be read there instead.
    $names = array_column(bible_books($first), 'name', 'code');
    $order = array_flip(bible_searchable_versions());      // KJV, WEB, BSB — the tie-break
    $found = [];
    foreach ($rows as $r) {
        foreach ($phrases as $ph) {
            if (mb_stripos($r['text'], $ph) === false) {
                continue 2;                // the index matched the words, not their order
            }
        }
        $key = $r['book'] . '.' . (int)$r['chapter'] . '.' . (int)$r['verse'];
        $found[$key] ??= ['book' => $r['book'], 'chapter' => (int)$r['chapter'], 'verse' => (int)$r['verse'], 'in' => []];
        $found[$key]['in'][$r['version']] = ['text' => $r['text'], 'n' => bible_term_count($r['text'], $terms)];
    }

    $hits = [];
    foreach ($found as $f) {
        $in = $f['in'];
        uksort($in, fn($a, $b) => [-$in[$a]['n'], $order[$a] ?? 99] <=> [-$in[$b]['n'], $order[$b] ?? 99]);
        $shown = array_key_first($in);
        $hits[] = [
            'book' => $f['book'], 'chapter' => $f['chapter'], 'verse' => $f['verse'],
            'ref' => ($names[$f['book']] ?? $f['book']) . ' ' . $f['chapter'] . ':' . $f['verse'],
            'version' => $shown,                 // whose wording is shown to begin with
            'versions' => array_keys($in),       // every one it appears in, that one first
            'text' => $in[$shown]['text'],
            // Each one's wording, so tapping a name swaps the words underneath without asking again.
            'texts' => array_map(fn($x) => $x['text'], $in),
        ];
    }
    usort($hits, fn($a, $b) => [bible_book_order($first, $a['book']), $a['chapter'], $a['verse']]
        <=> [bible_book_order($first, $b['book']), $b['chapter'], $b['verse']]);
    $hits = array_slice($hits, 0, $limit);
    return ['goto' => $goto, 'hits' => $hits, 'total' => count($hits),
            'versions' => $wanted, 'unsearchable' => $unsearchable,
            'words' => $phrases ?: array_map(fn($t) => ltrim(rtrim($t, '*'), '+-()"'), explode(' ', $boolean))];
}

function bible_scope_sql($scope): array
{
    // A list of books chosen by hand.
    if (is_array($scope)) {
        $codes = array_values(array_filter($scope, fn($c) => isset(BIBLE_CANON[$c])));
        if (!$codes || count($codes) === count(BIBLE_CANON)) {
            return ['', []];
        }
        return [' AND book IN (' . implode(',', array_fill(0, count($codes), '?')) . ')', $codes];
    }
    if ($scope === 'ot' || $scope === 'nt') {
        $codes = array_keys(array_filter(BIBLE_CANON, fn($c) => $c[3] === $scope));
        return [' AND book IN (' . implode(',', array_fill(0, count($codes), '?')) . ')', $codes];
    }
    if (preg_match('/^[A-Z0-9]{3}$/', $scope) && isset(BIBLE_CANON[$scope])) {
        return [' AND book = ?', [$scope]];
    }
    return ['', []];
}

function bible_book_order(string $version, string $code): int
{
    static $order = [];
    if (!isset($order[$version])) {
        $order[$version] = array_flip(array_column(bible_books($version), 'code'));
    }
    return $order[$version][$code] ?? 99;
}
