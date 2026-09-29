<?php
// Translations we don't hold: NKJV, NASB and NIV are read from API.Bible (American Bible Society),
// which licenses them on our behalf. They are not part of the corpus — they are fetched a chapter
// at a time and kept only in a cache, because that is the whole of what the licence allows.
//
// The cache is what makes this work at all. Without it every reader would spend a request of the
// monthly allowance on a chapter someone else had already read; with it, a chapter costs one
// request however many people read it.
//
// What API.Bible asks of a cache, in their words: "You can cache data, but we request that you
// limit it to fewer than 500 consecutive verses", and they recommend clearing it "every 14 days or
// less" so their corrections reach readers. Nothing requires deleting it. A chapter is 20-50
// verses, so caching chapter by chapter as people read stays well inside that; holding the whole
// book, or the whole Bible, would not.

const API_BIBLE_ROOT = 'https://rest.api.bible/v1';
const API_BIBLE_TTL_DAYS = 14;

// version code => API.Bible id, from config. Empty when no key is set, which turns the whole
// feature off: bible_versions() then offers only what's in the corpus.
function api_bible_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = config('api_bible_key') ? (array)(config('api_bible_ids') ?: []) : [];
    }
    return $map;
}

function api_bible_is(string $version): bool
{
    return isset(api_bible_map()[$version]);
}

// The versions that are fetched rather than held. Offline download and the flat-text features
// (search, cross-reference previews) ask this before assuming a version is ours to read locally.
function api_bible_versions(): array
{
    return array_keys(api_bible_map());
}

// What the month has cost so far, against what it is allowed. The allowance is API.Bible's, not
// ours to change: the free plan is 5,000 requests a month.
function api_bible_budget(): array
{
    $month = gmdate('Y-m');
    $used = (int)q('SELECT calls FROM api_bible_usage WHERE month = ?', [$month])->fetchColumn();
    $limit = (int)(config('api_bible_monthly') ?: 5000);
    return ['month' => $month, 'used' => $used, 'limit' => $limit, 'left' => max(0, $limit - $used)];
}

// One GET against API.Bible. Returns the decoded body, or null if anything at all went wrong —
// a caller that can't show a chapter says so; it doesn't raise.
//
// Every request is counted, and when the month's allowance is gone none are made: a stale chapter
// or a missing cross-reference preview is a better failure than every reader meeting an error
// because something spent the budget on a Tuesday.
function api_bible_get(string $path, array $query = []): ?array
{
    $key = (string)config('api_bible_key');
    if ($key === '') {
        return null;
    }
    $budget = api_bible_budget();
    if ($budget['left'] < 1) {
        error_log('api.bible: the month\'s allowance is spent (' . $budget['used'] . '/' . $budget['limit'] . ')');
        return null;
    }
    q('INSERT INTO api_bible_usage (month, calls) VALUES (?, 1)
       ON DUPLICATE KEY UPDATE calls = calls + 1', [$budget['month']]);
    $url = API_BIBLE_ROOT . $path . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['api-key: ' . $key, 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($body)) {
        error_log("api.bible $path: HTTP $code");
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

// API.Bible returns USFM class names (s, p, q1, wj); ours are the same names behind a b- prefix,
// with the verse's own text wrapped so a tap can find it. This turns one into the other.
//
// A verse can run past the end of a paragraph, so each paragraph reopens the verse it is still in;
// only the paragraph where a verse starts carries its number.
function api_bible_html(string $content): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="r">' . $content . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    $root = $doc->getElementById('r');
    if (!$root) {
        return ['html' => '', 'verses' => 0];
    }

    $out = '';
    $verse = 0;            // the verse the text is currently in
    $seen = [];            // which verses this chapter turned out to have

    foreach (iterator_to_array($root->childNodes) as $node) {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }
        $cls = trim((string)$node->getAttribute('class'));
        $first = strtok($cls, ' ') ?: 'p';

        if ($first === 'b') {                       // a blank line between paragraphs
            $out .= '<div class="b-b"></div>';
            continue;
        }
        if ($first === 's' || $first === 's1' || $first === 's2') {
            $out .= '<h3 class="b-s">' . api_bible_inner($node, $verse, $seen, false) . '</h3>';
            continue;
        }
        if ($first === 'r' || $first === 'mr' || $first === 'sp' || $first === 'd') {
            $out .= '<p class="b-' . $first . '">' . api_bible_inner($node, $verse, $seen, false) . '</p>';
            continue;
        }
        $body = api_bible_inner($node, $verse, $seen, true);
        if (trim(strip_tags($body)) !== '') {
            $out .= '<p class="b-' . $first . '">' . $body . '</p>';
        }
    }
    return ['html' => $out, 'verses' => $seen ? max(array_keys($seen)) : 0];
}

// One paragraph's worth. $verse carries across paragraphs, so a verse split over two of them is
// still one verse to the reader. $wrap is false for headings, which belong to no verse.
function api_bible_inner(DOMElement $p, int &$verse, array &$seen, bool $wrap): string
{
    $doc = $p->ownerDocument;
    $out = '';
    $open = false;
    $start = function (int $n, bool $withNumber) use (&$out, &$open) {
        if ($open) {
            $out .= '</span>';
        }
        $out .= '<span class="v" data-v="' . $n . '">';
        if ($withNumber) {
            $out .= '<sup class="vn" data-v="' . $n . '" aria-hidden="true">' . $n . '</sup>';
        }
        $open = true;
    };

    // The verse the previous paragraph ended on is reopened only if this one actually continues it.
    // A paragraph that begins on a new verse would otherwise start with an empty span.
    $carry = $wrap && $verse > 0;

    foreach (iterator_to_array($p->childNodes) as $node) {
        if ($node->nodeType === XML_ELEMENT_NODE && $node->getAttribute('class') === 'v') {
            $n = (int)$node->getAttribute('data-number');
            $carry = false;
            if ($n > 0) {
                $verse = $n;
                $seen[$n] = true;
                if ($wrap) {
                    $start($n, true);
                    continue;
                }
            }
            continue;                    // a verse number in a heading is not a thing we show
        }
        // Notes are asked for as off, but a stray one shouldn't land in the text.
        if ($node->nodeType === XML_ELEMENT_NODE && in_array($node->getAttribute('class'), ['note', 'f', 'x'], true)) {
            continue;
        }
        $html = $doc->saveHTML($node);
        if ($node->nodeType === XML_TEXT_NODE) {
            // Two words run together mid-sentence, as their NKJV has at 2 Samuel 23:2
            // ("TheSpirit of the Lord"). Nothing in scripture is spelled this way on purpose.
            $html = preg_replace('/([a-z])([A-Z][a-z])/u', '$1 $2', $html);
        }
        if ($carry) {
            if (trim(strip_tags($html)) === '') {
                continue;                // whitespace before the first verse of the paragraph
            }
            $start($verse, false);
            $carry = false;
        }
        $out .= $html;
    }
    if ($open) {
        $out .= '</span>';
    }
    return $out;
}

// A chapter of a fetched translation, in the shape bible_chapter() returns. Served from the cache
// when it's fresh; otherwise fetched, cached and served. If the fetch fails and stale text is in
// hand, the stale text is used — a chapter that is a fortnight old beats an error page.
function api_bible_chapter(string $version, string $code, int $chapter): ?array
{
    $id = api_bible_map()[$version] ?? '';
    if ($id === '') {
        return null;
    }
    $row = q('SELECT html, verses, copyright, fetched_at FROM bible_remote_chapters
              WHERE version = ? AND book = ? AND chapter = ?', [$version, $code, $chapter])->fetch();
    if ($row && strtotime((string)$row['fetched_at']) > time() - API_BIBLE_TTL_DAYS * 86400) {
        return ['html' => $row['html'], 'verses' => (int)$row['verses'], 'copyright' => $row['copyright']];
    }

    $data = api_bible_get("/bibles/$id/chapters/" . rawurlencode("$code.$chapter"), [
        'content-type' => 'html',
        'include-verse-numbers' => 'true',
        'include-verse-spans' => 'false',
        'include-titles' => 'true',
        'include-chapter-numbers' => 'false',
        'include-notes' => 'false',
    ]);
    if (!$data || empty($data['data']['content'])) {
        // Nothing new to be had: stale beats nothing.
        return $row ? ['html' => $row['html'], 'verses' => (int)$row['verses'], 'copyright' => $row['copyright']] : null;
    }
    $made = api_bible_html((string)$data['data']['content']);
    if ($made['html'] === '') {
        return $row ? ['html' => $row['html'], 'verses' => (int)$row['verses'], 'copyright' => $row['copyright']] : null;
    }
    $copyright = trim((string)($data['data']['copyright'] ?? ''));
    q('INSERT INTO bible_remote_chapters (version, book, chapter, html, verses, copyright, fetched_at)
       VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE html = VALUES(html), verses = VALUES(verses),
         copyright = VALUES(copyright), fetched_at = VALUES(fetched_at)',
        [$version, $code, $chapter, $made['html'], $made['verses'], $copyright]);

    return ['html' => $made['html'], 'verses' => $made['verses'], 'copyright' => $copyright];
}

// Searching a fetched translation happens at API.Bible, so the hits are worded as that
// translation words them rather than as the one we happen to hold.
//
// Their quoted-phrase search is not literal — "good shepherd" comes back with verses that have
// neither word beside the other — so a quoted search is confirmed here, exactly as the local
// search confirms its own index.
function api_bible_search(string $version, array $words, string $scope, ?string $phrase, int $limit): array
{
    $id = api_bible_map()[$version] ?? '';
    if ($id === '' || !$words) {
        return [];
    }
    // Their search indexes stems, and its wildcard matches those stems rather than the words as
    // written — "charity*" finds nothing while "charit*" finds all three. It also has no boolean
    // syntax: two wildcards together, or a wildcard beside a word, match nothing at all.
    //
    // So the words are asked for as typed, which is right whenever they are whole words; only if
    // that finds nothing, and only for a single word, is it retried as a prefix. That is the case
    // where somebody has typed part of a word — "descen" — and meant the words it begins.
    $clean = fn($w) => str_replace(['*', '"', '~'], '', $w);
    $query = $phrase !== null
        ? '"' . str_replace('"', '', $phrase) . '"'
        : implode(' ', array_map($clean, $words));
    // fuzziness=0, or their search answers "charity" with "chariot" — 154 hits where 3 are real.
    // It is not a literal match: "shepherd" still finds "shepherds", which is what anyone wants.
    $params = ['query' => $query, 'limit' => max($limit * 2, 20), 'sort' => 'canonical', 'fuzziness' => '0'];
    if ($scope === 'ot') {
        $params['range'] = 'GEN-MAL';
    } elseif ($scope === 'nt') {
        $params['range'] = 'MAT-REV';
    } elseif (preg_match('/^[A-Z0-9]{3}$/', $scope)) {
        $params['range'] = $scope;
    }
    $data = api_bible_get("/bibles/$id/search", $params);
    $verses = $data['data']['verses'] ?? [];
    if (!$verses && $phrase === null && count($words) === 1) {
        $params['query'] = $clean($words[0]) . '*';
        $data = api_bible_get("/bibles/$id/search", $params);
        $verses = $data['data']['verses'] ?? [];
    }

    $names = array_column(bible_books($version), 'name', 'code');
    $hits = [];
    foreach ($verses as $v) {
        // "JHN.10.11" — the one place their ids and ours line up exactly.
        $parts = explode('.', (string)($v['id'] ?? ''));
        if (count($parts) !== 3) {
            continue;
        }
        $text = trim(preg_replace('/\s+/u', ' ', (string)($v['text'] ?? '')));
        if ($phrase !== null && mb_stripos($text, $phrase) === false) {
            continue;                      // they matched the words, not the phrase
        }
        [$book, $chapter, $verse] = $parts;
        $hits[] = [
            'book' => $book, 'chapter' => (int)$chapter, 'verse' => (int)$verse,
            'ref' => ($names[$book] ?? $book) . ' ' . (int)$chapter . ':' . (int)$verse,
            'text' => $text,
        ];
        if (count($hits) >= $limit) {
            break;
        }
    }
    return $hits;
}

// How many verses one request may fetch. A cross-reference list can run to two dozen; fetching all
// of them on a tap would spend a morning's allowance on one curious moment.
const API_BIBLE_VERSE_CAP = 8;

// Single verses, for the text under a cross-reference. Cached without an expiry: unlike a chapter,
// which is read and re-read, these are looked at once in passing, and the words of a verse do not
// change. They are scattered singles — a cross-reference never points at a run.
function api_bible_verses(string $version, array $refs): array
{
    $id = api_bible_map()[$version] ?? '';
    if ($id === '' || !$refs) {
        return [];
    }
    $out = [];
    $want = [];
    foreach ($refs as $r) {
        if (!preg_match('/^([A-Z0-9]{3})\.(\d{1,3})\.(\d{1,3})$/', strtoupper(trim($r)), $m)) {
            continue;
        }
        $want[$m[0]] = [$m[1], (int)$m[2], (int)$m[3]];
    }
    if (!$want) {
        return [];
    }

    // Whatever is already in hand costs nothing.
    $keys = array_keys($want);
    $in = implode(',', array_fill(0, count($keys), '?'));
    $rows = q("SELECT book, chapter, verse, text FROM bible_remote_verses
               WHERE version = ? AND CONCAT(book, '.', chapter, '.', verse) IN ($in)",
        array_merge([$version], $keys))->fetchAll();
    foreach ($rows as $r) {
        $key = $r['book'] . '.' . (int)$r['chapter'] . '.' . (int)$r['verse'];
        $out[$key] = $r['text'];
        unset($want[$key]);
    }

    $fetched = 0;
    foreach ($want as $key => [$book, $chapter, $verse]) {
        if ($fetched >= API_BIBLE_VERSE_CAP) {
            break;
        }
        $fetched++;
        $data = api_bible_get("/bibles/$id/verses/" . rawurlencode($key), [
            'content-type' => 'text',
            'include-verse-numbers' => 'false',
            'include-verse-spans' => 'false',
            'include-notes' => 'false',
            'include-titles' => 'false',
            'include-chapter-numbers' => 'false',
        ]);
        $text = trim(preg_replace('/\s+/u', ' ', (string)($data['data']['content'] ?? '')));
        if ($text === '') {
            continue;
        }
        q('INSERT INTO bible_remote_verses (version, book, chapter, verse, text, fetched_at)
           VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
           ON DUPLICATE KEY UPDATE text = VALUES(text), fetched_at = VALUES(fetched_at)',
            [$version, $book, $chapter, $verse, $text]);
        $out[$key] = $text;
    }
    return $out;
}
