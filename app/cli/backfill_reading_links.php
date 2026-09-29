<?php
// Puts the "📖 Read" link on daily readings posted before the reader existed to link to.
//   php cli/backfill_reading_links.php [--dry] [--from=YYYY-MM-DD]
//
// The link lives in the entities column, the way Telegram sends one: an offset and a length into
// the text, counted in UTF-16 code units rather than characters or bytes — which matters here,
// because 👉 and 📖 each count as two. The offsets of any entities already on the message are left
// alone, since the line is added at the end.
require __DIR__ . '/../lib/bootstrap.php';

$dry = in_array('--dry', $argv, true);
$from = '2026-09-11';
foreach ($argv as $a) {
    if (str_starts_with($a, '--from=')) {
        $from = substr($a, 7);
    }
}

// Telegram counts an entity's offset in UTF-16 code units.
function utf16_len(string $s): int
{
    return strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) >> 1;
}

$rows = q('SELECT id, text, entities, created_at FROM messages
           WHERE deleted_at IS NULL AND kind = "text" AND created_at >= ?
             AND text LIKE "👉%" ORDER BY id', [$from])->fetchAll();

$done = 0;
$skipped = 0;
foreach ($rows as $m) {
    $ents = json_decode((string)$m['entities'], true) ?: [];
    foreach ($ents as $e) {
        if (str_contains((string)($e['url'] ?? ''), '/reading/')) {
            $skipped++;
            continue 2;                      // it already links
        }
    }
    // The date the reading is for, as the message itself states it ("👉 Fri., Sep 11, 2026:").
    if (!preg_match('/^👉\s*[A-Za-z]{3}\.?,\s*([A-Za-z]{3}\.?\s+\d{1,2},\s*\d{4})/u', $m['text'], $mm)) {
        fwrite(STDERR, "  id {$m['id']}: can't read the date from the text, left alone\n");
        $skipped++;
        continue;
    }
    $date = date('Y-m-d', strtotime(str_replace('.', '', $mm[1]) . ' 12:00 UTC'));

    $text = rtrim($m['text']) . "\n📖 Read";
    $ents[] = [
        'type' => 'text_link',
        'offset' => utf16_len(rtrim($m['text']) . "\n📖 "),
        'length' => 4,                        // "Read"
        // The path alone, as the links already on the later readings have it.
        'url' => (string)parse_url(url('reading/' . $date), PHP_URL_PATH),
    ];
    printf("  id %-6s %s → %s\n", $m['id'], $date, $ents[count($ents) - 1]['url']);
    if (!$dry) {
        q('UPDATE messages SET text = ?, entities = ? WHERE id = ?',
            [$text, json_encode($ents, JSON_UNESCAPED_UNICODE), $m['id']]);
    }
    $done++;
}
printf("%s%d linked, %d already had one\n", $dry ? '(dry run) ' : '', $done, $skipped);
