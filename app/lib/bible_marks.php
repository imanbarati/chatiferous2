<?php
// A member's own marks in the Bible: highlights, bookmarks and private notes.
//
// These are ordinary rows, not encrypted: whoever runs the server can read them. The settings
// screen says so. They belong to the account rather than to a device, and to the reference rather
// than to a version, so the same verse is marked in whichever version it's read in.

require_once APP_DIR . '/lib/bible_canon.php';

const BIBLE_MARK_COLOURS = ['yellow', 'green', 'blue', 'pink', 'orange'];
const BIBLE_NOTE_MAX = 4000;

// A passage as plain words: no verse numbers, no note markers, nothing to trip up a reader or a
// text file.
function bible_mark_text(string $version, array $m): string
{
    $html = bible_passage_html($version, $m['book'], $m['chapter'], $m['verse'], $m['chapter'], $m['end_verse']);
    $html = preg_replace('/<sup\b[^>]*>.*?<\/sup>/us', '', $html);
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
}

// Every mark, or just one chapter's. Newest first within a verse, so the reader shows the last
// colour chosen.
function bible_marks(int $user_id, ?string $book = null, ?int $chapter = null): array
{
    $where = 'user_id = ?';
    $args = [$user_id];
    if ($book !== null) {
        $where .= ' AND book = ?';
        $args[] = $book;
        if ($chapter !== null) {
            $where .= ' AND chapter = ?';
            $args[] = $chapter;
        }
    }
    $rows = q("SELECT id, kind, book, chapter, verse, end_verse, colour, body, version,
                      UNIX_TIMESTAMP(created_at) AS created_at, UNIX_TIMESTAMP(updated_at) AS updated_at
               FROM bible_marks WHERE $where ORDER BY id", $args)->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['chapter'] = (int)$r['chapter'];
        $r['verse'] = (int)$r['verse'];
        $r['end_verse'] = (int)$r['end_verse'];
        $r['created_at'] = (int)$r['created_at'];
        $r['updated_at'] = (int)$r['updated_at'];
        $r['name'] = BIBLE_CANON[$r['book']][0] ?? $r['book'];
        $r['ref'] = $r['name'] . ' ' . $r['chapter'] . ':' . $r['verse']
            . ($r['end_verse'] > $r['verse'] ? '-' . $r['end_verse'] : '');
    }
    return $rows;
}

// In book order, for the My marks screen and for export.
function bible_marks_ordered(int $user_id, string $kind = ''): array
{
    $marks = bible_marks($user_id);
    if ($kind !== '') {
        $marks = array_values(array_filter($marks, fn($m) => $m['kind'] === $kind));
    }
    $order = array_flip(array_keys(BIBLE_CANON));
    usort($marks, fn($a, $b) => [$order[$a['book']] ?? 99, $a['chapter'], $a['verse']]
        <=> [$order[$b['book']] ?? 99, $b['chapter'], $b['verse']]);
    return $marks;
}

// Checks one mark as it comes in from the page. Returns it tidied, or null if it makes no sense.
function bible_mark_clean(array $in): ?array
{
    $kind = (string)($in['kind'] ?? '');
    $book = strtoupper((string)($in['book'] ?? ''));
    if (!in_array($kind, ['highlight', 'bookmark', 'note'], true) || !isset(BIBLE_CANON[$book])) {
        return null;
    }
    [, $chapters] = BIBLE_CANON[$book];
    $chapter = (int)($in['chapter'] ?? 0);
    $verse = (int)($in['verse'] ?? 0);
    $end = max($verse, (int)($in['end_verse'] ?? $verse));
    if ($chapter < 1 || $chapter > $chapters || $verse < 1 || $verse > 200 || $end > 200) {
        return null;
    }
    $colour = (string)($in['colour'] ?? '');
    if ($kind === 'highlight' && !in_array($colour, BIBLE_MARK_COLOURS, true)) {
        $colour = BIBLE_MARK_COLOURS[0];
    }
    $body = trim((string)($in['body'] ?? ''));
    if ($kind === 'note' && $body === '') {
        return null;
    }
    return [
        'kind' => $kind, 'book' => $book, 'chapter' => $chapter, 'verse' => $verse,
        'end_verse' => $end, 'colour' => $kind === 'highlight' ? $colour : '',
        'body' => mb_substr($body, 0, BIBLE_NOTE_MAX),
        'version' => preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($in['version'] ?? ''))),
    ];
}

// Marking a verse. A second highlight or bookmark on the same verses replaces the first (so
// choosing another colour changes the colour rather than stacking), and so does a second note.
function bible_mark_set(int $user_id, array $in): ?array
{
    $m = bible_mark_clean($in);
    if (!$m) {
        return null;
    }
    bible_mark_clear($user_id, $m['kind'], $m['book'], $m['chapter'], $m['verse'], $m['end_verse']);
    q('INSERT INTO bible_marks (user_id, kind, book, chapter, verse, end_verse, colour, body, version)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$user_id, $m['kind'], $m['book'], $m['chapter'], $m['verse'], $m['end_verse'],
         $m['colour'], $m['body'], $m['version']]);
    $id = (int)db()->lastInsertId();
    foreach (bible_marks($user_id, $m['book'], $m['chapter']) as $row) {
        if ($row['id'] === $id) {
            return $row;
        }
    }
    return null;
}

// Removes the marks of one kind that touch these verses (how a highlight is taken off again).
function bible_mark_clear(int $user_id, string $kind, string $book, int $chapter, int $verse, int $end): int
{
    $st = q('DELETE FROM bible_marks WHERE user_id = ? AND kind = ? AND book = ? AND chapter = ?
             AND verse <= ? AND end_verse >= ?', [$user_id, $kind, $book, $chapter, $end, $verse]);
    return $st->rowCount();
}

function bible_mark_delete(int $user_id, int $id): bool
{
    return q('DELETE FROM bible_marks WHERE user_id = ? AND id = ?', [$user_id, $id])->rowCount() > 0;
}

// ---------- taking it all with you ----------
// Larry's complaint about the commercial apps is that what you write in them stays in them, so
// everything here comes out in a form that can be read on its own and put back again.

function bible_marks_markdown(int $user_id, string $version): string
{
    $out = "# My marks\n\nExported " . gmdate('j F Y') . ".\n";
    $book = '';
    foreach (bible_marks_ordered($user_id) as $m) {
        if ($m['name'] !== $book) {
            $book = $m['name'];
            $out .= "\n## $book\n";
        }
        $text = bible_mark_text($version, $m);
        $label = ['highlight' => 'Highlight', 'bookmark' => 'Bookmark', 'note' => 'Note'][$m['kind']];
        $out .= "\n### {$m['ref']}" . ($m['colour'] ? " · {$m['colour']}" : '') . "\n\n";
        $out .= "*$label* — " . gmdate('j M Y', $m['created_at']) . "\n\n";
        if ($text !== '') {
            $out .= '> ' . $text . "\n\n";
        }
        if ($m['body'] !== '') {
            $out .= $m['body'] . "\n\n";
        }
    }
    return $out;
}

function bible_marks_json(int $user_id): string
{
    return json_encode([
        'app' => 'chatiferous-bible-marks', 'v' => 1, 'exported' => gmdate('c'),
        'marks' => array_map(fn($m) => [
            'kind' => $m['kind'], 'book' => $m['book'], 'chapter' => $m['chapter'],
            'verse' => $m['verse'], 'end_verse' => $m['end_verse'], 'colour' => $m['colour'],
            'body' => $m['body'], 'version' => $m['version'], 'created' => gmdate('c', $m['created_at']),
        ], bible_marks_ordered($user_id)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Puts an exported file back. Marks already there are left alone, so importing twice doesn't
// double anything up. Returns how many were added.
function bible_marks_import(int $user_id, string $json): int
{
    $data = json_decode($json, true);
    $rows = is_array($data['marks'] ?? null) ? $data['marks'] : (is_array($data) ? $data : []);
    $added = 0;
    foreach (array_slice($rows, 0, 5000) as $row) {
        if (!is_array($row) || !($m = bible_mark_clean($row))) {
            continue;
        }
        $there = q('SELECT id FROM bible_marks WHERE user_id = ? AND kind = ? AND book = ? AND chapter = ?
                    AND verse = ? AND end_verse = ?',
            [$user_id, $m['kind'], $m['book'], $m['chapter'], $m['verse'], $m['end_verse']])->fetchColumn();
        if ($there !== false) {
            continue;
        }
        q('INSERT INTO bible_marks (user_id, kind, book, chapter, verse, end_verse, colour, body, version)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$user_id, $m['kind'], $m['book'], $m['chapter'], $m['verse'], $m['end_verse'],
             $m['colour'], $m['body'], $m['version']]);
        $added++;
    }
    return $added;
}
