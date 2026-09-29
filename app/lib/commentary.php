<?php
// The commentaries: thirty-odd public-domain works, verse by verse.
//
// A chapter of one work is kept as one packed row (gzip of a JSON map from verse range to HTML),
// which is how a whole Bible of them fits in a few hundred megabytes and why opening a verse
// costs one query per work chosen rather than a search through a very large table.

require_once APP_DIR . '/lib/bible_canon.php';

// Every work in the catalogue, in reading order.
function commentary_works(): array
{
    static $works = null;
    if ($works === null) {
        $works = q('SELECT * FROM bible_works ORDER BY sort, name')->fetchAll();
        foreach ($works as &$w) {
            $w['chapters'] = (int)$w['chapters'];
            $w['sort'] = (int)$w['sort'];
        }
    }
    return $works;
}

function commentary_work(string $code): ?array
{
    foreach (commentary_works() as $w) {
        if ($w['code'] === $code) {
            return $w;
        }
    }
    return null;
}

// What someone sees when they've never chosen: the three that suit a general reader, and are
// there for nearly every chapter of the Bible.
const COMMENTARY_DEFAULT = ['mhcw', 'barnes', 'jfb'];

// The works a member has chosen, kept with their other reading settings.
function commentary_chosen(int $user_id): array
{
    $picked = user_pref($user_id, 'commentaries');
    $codes = array_column(commentary_works(), 'code');
    if (!is_array($picked)) {
        return array_values(array_intersect(COMMENTARY_DEFAULT, $codes));
    }
    return array_values(array_intersect(array_map('strval', $picked), $codes));
}

function commentary_set_chosen(int $user_id, array $codes): array
{
    $ok = array_values(array_intersect(array_map('strval', $codes), array_column(commentary_works(), 'code')));
    set_user_pref($user_id, 'commentaries', array_slice($ok, 0, 40));
    return $ok;
}

// One chapter of one work, as verse range => HTML.
function commentary_chapter(string $work, string $book, int $chapter): array
{
    $blob = q('SELECT entries FROM bible_commentary WHERE work = ? AND book = ? AND chapter = ?',
        [$work, $book, $chapter])->fetchColumn();
    if ($blob === false) {
        return [];
    }
    $json = @gzdecode($blob);
    $rows = $json === false ? null : json_decode($json, true);
    return is_array($rows) ? $rows : [];
}

// Which verses a stored entry covers: the key is "7" or "4-9".
function commentary_range(string $key): array
{
    $parts = explode('-', $key);
    $from = (int)$parts[0];
    return [$from, isset($parts[1]) ? max($from, (int)$parts[1]) : $from];
}

// What the chosen works say about one verse. Entries covering a range that takes the verse in
// count, so a comment on "Verses 4-9" shows when verse 5 is tapped.
function commentary_for(string $book, int $chapter, int $verse, array $works): array
{
    $out = [];
    foreach ($works as $code) {
        $work = commentary_work($code);
        if (!$work) {
            continue;
        }
        foreach (commentary_chapter($code, $book, $chapter) as $key => $html) {
            [$from, $to] = commentary_range((string)$key);
            if ($verse < $from || $verse > $to) {
                continue;
            }
            $out[] = [
                'work'    => $code,
                'name'    => $work['name'],
                'edition' => $work['edition'],
                'years'   => $work['years'],
                'verses'  => $from === $to ? (string)$from : "{$from}–{$to}",
                'html'    => $html,
            ];
        }
    }
    return $out;
}

// Which works have anything at all for a chapter, so the list can say so before you open them.
function commentary_have(string $book, int $chapter): array
{
    return array_map('strval', q('SELECT work FROM bible_commentary WHERE book = ? AND chapter = ?',
        [$book, $chapter])->fetchAll(PDO::FETCH_COLUMN));
}

// ---------- importing ----------

// Stores one chapter of one work. $entries is verse range => HTML.
function commentary_store(string $work, string $book, int $chapter, array $entries): int
{
    $entries = array_filter($entries, fn($html) => trim((string)$html) !== '');
    if (!$entries) {
        return 0;
    }
    $json = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $words = str_word_count(strip_tags(implode(' ', $entries)));
    q('INSERT INTO bible_commentary (work, book, chapter, entries, words) VALUES (?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE entries = VALUES(entries), words = VALUES(words), fetched_at = NOW()',
        [$work, $book, $chapter, gzencode($json, 6), $words]);
    return $words;
}

function commentary_count_chapters(string $work): int
{
    $n = (int)q('SELECT COUNT(*) FROM bible_commentary WHERE work = ?', [$work])->fetchColumn();
    q('UPDATE bible_works SET chapters = ? WHERE code = ?', [$n, $work]);
    return $n;
}

// Several modules print the passage before commenting on it, which the reader already has on the
// screen behind the sheet. $openings is the first few words of each verse the comment covers and
// $whole each verse in full, both lowercased and stripped of punctuation.
//
// A block that repeats the opening of two or more of those verses is the passage reprinted; so is
// one that reproduces a whole verse. A note that opens by quoting the phrase it expounds, as
// Gill's and Clarke's do, matches one verse and is much shorter than it, so it stays.
function drop_reprinted_passage(string $html, array $openings, array $whole): string
{
    // A line break can separate the passage from the comment as surely as a paragraph does
    // (Jamieson-Fausset-Brown sets the verse, a <br>, then the note), so break on those too.
    $html = preg_replace('#<br\s*/?>#i', '</p><p>', $html);
    if (!preg_match_all('#<p\b[^>]*>(.*?)</p>#us', $html, $ms, PREG_SET_ORDER)) {
        return $html;
    }
    $out = '';
    foreach ($ms as $i => $m) {
        $plain = trim(mb_strtolower(preg_replace('/\s+/u', ' ',
            preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', strip_tags($m[1])))));
        // A passage reprinted before the comment: the opening words of two or more of its verses.
        $found = 0;
        foreach ($openings as $opening) {
            if ($opening !== '' && str_contains($plain, $opening)) {
                $found++;
            }
        }
        // Or the verse itself, set out in full. A note that opens by quoting the phrase it
        // expounds is much shorter than the verse, and stays.
        $isVerse = false;
        if ($found < 2) {
            foreach ($whole as $text) {
                $len = mb_strlen($text);
                if ($len > 40 && mb_strlen($plain) <= $len * 1.3 && str_contains($plain, $text)) {
                    $isVerse = true;
                    break;
                }
            }
        }
        if ($found < 2 && !$isVerse) {
            $out .= $m[0];
        }
    }
    return trim($out) !== '' ? $out : $html;      // never leave an entry with nothing in it
}

// The corpus files are Markdown: a title line, an edition line in italics, then sections headed
// "### Verse 3" or "### Verses 4–9". This turns one file into verse range => HTML.
function commentary_parse_markdown(string $text): array
{
    $text = str_replace("\r\n", "\n", $text);
    // The headings are found first and the text between them taken as each one's body. (Splitting
    // on the heading won't do: a heading with no second number, "### Verse 1", leaves the optional
    // group out of the pieces altogether and everything after it lines up wrong.)
    if (!preg_match_all('/^###[ \t]+Verses?[ \t]+([0-9]+)(?:[ \t]*[-–—][ \t]*([0-9]+))?[ \t]*$/mu',
            $text, $heads, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        return [];
    }
    $entries = [];
    foreach ($heads as $n => $h) {
        $from = (int)$h[1][0];
        $to = isset($h[2]) && $h[2][0] !== '' ? max($from, (int)$h[2][0]) : $from;
        $start = $h[0][1] + strlen($h[0][0]);
        $end = isset($heads[$n + 1]) ? $heads[$n + 1][0][1] : strlen($text);
        $html = commentary_html(trim(substr($text, $start, $end - $start)));
        if ($html === '') {
            continue;
        }
        $key = $from === $to ? (string)$from : $from . '-' . $to;
        $entries[$key] = ($entries[$key] ?? '') . $html;
    }
    return $entries;
}

// HTML that came from somebody else's file, reduced to the handful of tags the sheet needs. Every
// attribute goes (there is nothing in a commentary that needs one), along with anything that could
// carry behaviour; the text itself is kept.
const COMMENTARY_TAGS = ['p', 'br', 'i', 'em', 'b', 'strong', 'sup', 'sub', 'small',
    'blockquote', 'ul', 'ol', 'li', 'h3', 'h4', 'h5', 'h6', 'span', 'div', 'a', 'font'];
const COMMENTARY_UNWRAP = ['span', 'div', 'a', 'font'];   // kept for their text, not themselves

function commentary_clean_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?><div id="c">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    foreach (iterator_to_array($xp->query('//script|//style|//iframe|//object|//embed')) as $bad) {
        $bad->parentNode->removeChild($bad);
    }
    foreach (iterator_to_array($xp->query('//*')) as $el) {
        if ($el->getAttribute('id') === 'c') {
            continue;
        }
        $name = strtolower($el->nodeName);
        if (!in_array($name, COMMENTARY_TAGS, true) || in_array($name, COMMENTARY_UNWRAP, true)) {
            // Keep what it says, drop the tag itself.
            while ($el->firstChild) {
                $el->parentNode->insertBefore($el->firstChild, $el);
            }
            $el->parentNode->removeChild($el);
            continue;
        }
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $el->removeAttribute($attr->nodeName);
        }
    }
    $out = '';
    foreach ($xp->query('//*[@id="c"]')->item(0)->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    $out = trim(preg_replace('/\s+/u', ' ', $out));
    // Words the scan set in small capitals come through a letter at a time ("G E N E S I S").
    $out = preg_replace_callback('/\b(?:[A-Z] ){2,}[A-Z]\b/u',
        fn($m) => str_replace(' ', '', $m[0]), $out);
    // Text that arrived with no paragraphs of its own still needs one.
    return $out !== '' && !preg_match('/^<(p|blockquote|ul|ol|h[3-6])\b/', $out) ? '<p>' . $out . '</p>' : $out;
}

// The small amount of Markdown these files use, as safe HTML: paragraphs, bold and italic.
// Everything else is escaped, since the text comes from files rather than from the app.
function commentary_html(string $md): string
{
    $out = '';
    $first = true;
    foreach (preg_split('/\n{2,}/u', $md) as $para) {
        $para = trim($para);
        if ($para === '') {
            continue;
        }
        // Several of the works repeat the verse range as their opening line; the sheet's own
        // heading says it, so that line goes.
        if ($first && preg_match('/^Verses?\s+[0-9]+(?:\s*[-–—]\s*[0-9]+)?\.?$/u', $para)) {
            $first = false;
            continue;
        }
        $first = false;
        $html = htmlspecialchars($para, ENT_QUOTES, 'UTF-8');
        $html = preg_replace('/\*\*(.+?)\*\*/us', '<strong>$1</strong>', $html);
        $html = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/us', '<em>$1</em>', $html);
        $html = str_replace("\n", ' ', $html);
        $out .= '<p>' . $html . '</p>';
    }
    return $out;
}
