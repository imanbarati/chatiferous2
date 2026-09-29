<?php
// Searching messages (and poll questions), optionally within one topic.

const SEARCH_PAGE = 30;

// Turns what someone typed into a MariaDB boolean full-text query: every word
// must appear; the last word also matches as a prefix ("Romans 8" -> +Romans* ...).
function search_terms(string $q): array
{
    preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($q), $m);
    return array_slice(array_unique($m[0]), 0, 8);
}

function search_messages(int $me, string $q, int $topic_id, int $before_id): array
{
    $terms = search_terms($q);
    if (!$terms) {
        return ['results' => [], 'more' => false];
    }
    // Inside a DM: only the members' own devices can read it, so the app searches it itself.
    $t = $topic_id ? q('SELECT * FROM topics WHERE id = ?', [$topic_id])->fetch() : null;
    if ($t && $t['kind'] === 'dm') {
        return ['results' => [], 'more' => false, 'on_device' => true];
    }
    // InnoDB full-text ignores words under 3 letters, so those use LIKE instead.
    $ft = implode(' ', array_map(fn($t) => '+' . $t . '*', array_filter($terms, fn($t) => mb_strlen($t) >= 3)));
    $where = ['m.deleted_at IS NULL', "m.kind <> 'service'", "t.kind = 'topic'"];   // never anyone's DMs
    $params = [];
    if ($ft !== '') {
        $where[] = '(MATCH(m.text) AGAINST (? IN BOOLEAN MODE) OR MATCH(p.question) AGAINST (? IN BOOLEAN MODE))';
        array_push($params, $ft, $ft);
    }
    foreach ($terms as $t) {
        if (mb_strlen($t) < 3) {
            $where[] = '(m.text LIKE ? OR p.question LIKE ?)';
            array_push($params, "%$t%", "%$t%");
        }
    }
    if ($topic_id) {
        $where[] = 'm.topic_id = ?';
        $params[] = $topic_id;
    }
    if ($before_id) {
        $where[] = 'm.id < ?';
        $params[] = $before_id;
    }
    $rows = q('SELECT m.id, m.topic_id, m.user_id, m.text, m.created_at, p.question, t.title, u.display_name, u.color_index
               FROM messages m LEFT JOIN polls p ON p.message_id = m.id JOIN topics t ON t.id = m.topic_id JOIN users u ON u.id = m.user_id
               WHERE ' . implode(' AND ', $where) . ' ORDER BY m.id DESC LIMIT ' . (SEARCH_PAGE + 1), $params)->fetchAll();
    $more = count($rows) > SEARCH_PAGE;
    $rows = array_slice($rows, 0, SEARCH_PAGE);
    return ['results' => array_map(fn($r) => [
        'id'      => (int)$r['id'],
        'topic'   => (int)$r['topic_id'],
        'title'   => $r['title'],
        'name'    => $r['display_name'],
        'color'   => (int)$r['color_index'],
        'at'      => iso($r['created_at']),
        'snippet' => search_snippet($r['question'] !== null ? '📊 ' . $r['question'] : $r['text'], $terms),
    ], $rows), 'more' => $more, 'terms' => $terms];
}

// ~160 characters around the first matching word, with an ellipsis where cut.
function search_snippet(string $text, array $terms): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text));
    $pos = null;
    foreach ($terms as $t) {
        $p = mb_stripos($text, $t);
        if ($p !== false && ($pos === null || $p < $pos)) {
            $pos = $p;
        }
    }
    $start = max(0, (int)$pos - 50);
    $snip = mb_substr($text, $start, 160);
    return ($start > 0 ? '…' : '') . $snip . ($start + 160 < mb_strlen($text) ? '…' : '');
}
