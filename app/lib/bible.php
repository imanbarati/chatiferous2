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
    $html = q('SELECT html FROM bible_chapters WHERE version = ? AND book = ? AND chapter = ?',
        [$version, $code, $chapter])->fetchColumn();
    if ($html === false) {
        return null;
    }
    $notes = q('SELECT verse, kind, marker, body, refs FROM bible_notes
                WHERE version = ? AND book = ? AND chapter = ? ORDER BY id', [$version, $code, $chapter])->fetchAll();
    return [
        'version' => $version,
        'book'    => $code,
        'name'    => $book['name'],
        'biblehub' => $book['biblehub'],
        'chapter' => $chapter,
        'chapters' => (int)$book['chapters'],
        'html'    => $html,
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
            [$version, $r['to_book'], $r['to_chapter'], $r['to_verse'], $r['to_end']])->fetchColumn();
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
        $out .= ($end_chapter > $chapter ? '<h3 class="b-s">' . h($ch['name'] . ' ' . $c) . '</h3>' : '')
            . bible_slice($ch['html'], $from, $to);
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
    $w = $doc->getElementById('w');
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
    if (!$book) {
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

// One box, three kinds of answer, in this order:
//   a reference ("1 Jn 2", "John 3:16")   → where to go
//   a phrase in quotation marks           → exact matches, in book order
//   words                                 → verses holding all of them, best first
// Scope is 'all', 'ot', 'nt' or a book code. Returns ['goto' => …, 'hits' => […], 'total' => n].
function bible_search(string $version, string $query, string $scope = 'all', int $limit = 60): array
{
    require_once APP_DIR . '/lib/bible_refs.php';
    $query = trim(preg_replace('/\s+/u', ' ', $query));
    if ($query === '') {
        return ['goto' => null, 'hits' => [], 'total' => 0];
    }

    // A reference on its own goes straight there.
    $goto = null;
    $refs = bible_find_refs($query);
    if ($refs && $refs[0]['length'] >= strlen($query) - 2 && bible_book($version, $refs[0]['book'])) {
        $r = $refs[0];
        $goto = ['book' => $r['book'], 'chapter' => $r['chapter'], 'verse' => $r['verse'], 'ref' => $r['ref']];
    }

    $phrase = preg_match('/^"(.+)"$/u', $query, $m) ? $m[1] : null;
    $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}\']+/u', $phrase ?? $query), fn($w) => mb_strlen($w) > 1));
    if (!$words) {
        return ['goto' => $goto, 'hits' => [], 'total' => 0];
    }

    [$where, $args] = bible_scope_sql($scope);
    array_unshift($args, $version);

    // Full-text first (fast, ranked), then the phrase is confirmed literally: the index doesn't
    // know about word order.
    $boolean = $phrase !== null
        ? '"' . str_replace('"', '', $phrase) . '"'
        : implode(' ', array_map(fn($w) => '+' . str_replace(['+', '-', '*', '(', ')', '~', '<', '>'], '', $w), $words));
    $sql = 'SELECT book, chapter, verse, text, MATCH(text) AGAINST (? IN BOOLEAN MODE) score
            FROM bible_verses WHERE version = ?' . $where . '
              AND MATCH(text) AGAINST (? IN BOOLEAN MODE)
            ORDER BY score DESC, book, chapter, verse LIMIT ?';
    $rows = q($sql, array_merge([$boolean], $args, [$boolean, $limit * 2]))->fetchAll();

    $names = array_column(bible_books($version), 'name', 'code');
    $hits = [];
    foreach ($rows as $r) {
        if ($phrase !== null && mb_stripos($r['text'], $phrase) === false) {
            continue;                      // the index matched the words, not the phrase
        }
        $hits[] = [
            'book' => $r['book'], 'chapter' => (int)$r['chapter'], 'verse' => (int)$r['verse'],
            'ref' => ($names[$r['book']] ?? $r['book']) . ' ' . $r['chapter'] . ':' . $r['verse'],
            'text' => $r['text'],
        ];
        if (count($hits) >= $limit) {
            break;
        }
    }
    // Word searches read better in book order; ranked order matters only for choosing which to show.
    usort($hits, fn($a, $b) => [bible_book_order($version, $a['book']), $a['chapter'], $a['verse']]
        <=> [bible_book_order($version, $b['book']), $b['chapter'], $b['verse']]);
    return ['goto' => $goto, 'hits' => $hits, 'total' => count($hits), 'words' => $phrase !== null ? [$phrase] : $words];
}

function bible_scope_sql(string $scope): array
{
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
