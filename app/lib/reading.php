<?php
// The daily reading (optional, config 'daily_reading'): posts the day's entry from the schedule
// and a "Finished?" poll into the chosen topic, as the system account chosen on Admin → Daily reading.

require_once APP_DIR . '/lib/actions.php';

const READING_POLL_OPTIONS = ['☑️', '📖', '✅'];   // not started / started, not finished / finished

function setting(string $name, ?string $default = null): ?string
{
    $v = q('SELECT value FROM settings WHERE name = ?', [$name])->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting(string $name, string $value): void
{
    q('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
}

// Reads the schedule CSV (columns date, readings_merged). Dates are parsed as real
// dates, so "2-May-25" works as well as "02-May-25"; rows sharing a date are all kept.
// Returns [rows, problems].
function parse_schedule_csv(string $path): array
{
    $fh = fopen($path, 'r');
    $header = fgetcsv($fh, null, ',', '"', '');
    $header = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h))), $header ?: []);
    $di = array_search('date', $header, true);
    $ti = array_search('readings_merged', $header, true);
    if ($di === false || $ti === false) {
        return [[], ['The file needs "date" and "readings_merged" columns.']];
    }
    $rows = [];
    $problems = [];
    $line = 1;
    while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
        $line++;
        $date = trim($r[$di] ?? '');
        $text = trim($r[$ti] ?? '');
        if ($date === '' && $text === '') {
            continue;
        }
        $d = null;
        foreach (['!d-M-y', '!j-M-y', '!Y-m-d', '!d-M-Y', '!j-M-Y'] as $fmt) {
            $try = DateTime::createFromFormat($fmt, $date, new DateTimeZone('UTC'));
            if ($try && $try->format(substr($fmt, 1)) === $date) {
                $d = $try;
                break;
            }
        }
        if (!$d) {
            $problems[] = "Line $line: can't read the date \"$date\".";
            continue;
        }
        if ($text === '') {
            $problems[] = "Line $line ($date): no reading.";
            continue;
        }
        // A range that ends before it starts — "1 Chronicles 6:50-48" — means the merging that
        // produced this column took one passage's first verse and another's last. The reading is
        // still shown, but it is said out loud rather than discovered months later by a reader.
        if (preg_match_all('/(\d+):(\d+)\s*-\s*(\d+)(?!\s*[:\d])/', $text, $bad, PREG_SET_ORDER)) {
            foreach ($bad as $b) {
                if ((int)$b[3] < (int)$b[2]) {
                    $problems[] = "Line $line ($date): \"{$b[0]}\" ends before it starts.";
                }
            }
        }
        $rows[] = [$d->format('Y-m-d'), mb_substr($text, 0, 500)];
    }
    fclose($fh);
    return [$rows, $problems];
}

function replace_schedule(array $rows): void
{
    db()->beginTransaction();
    q('DELETE FROM reading_schedule');
    $pos = [];
    foreach ($rows as [$date, $text]) {
        $pos[$date] = ($pos[$date] ?? -1) + 1;
        q('INSERT INTO reading_schedule (reading_date, position, text) VALUES (?, ?, ?)', [$date, $pos[$date], $text]);
    }
    db()->commit();
}

function readings_for(string $date): array
{
    return q('SELECT text FROM reading_schedule WHERE reading_date = ? ORDER BY position', [$date])->fetchAll(PDO::FETCH_COLUMN);
}

// "👉 Sat., Sep 19, 2026: ⏎ <readings>", the bot's exact format, with a link to the day's own
// page in the reader — the whole passage in one piece, with a button to say you've finished.
function reading_text(string $date, array $readings): string
{
    $text = '👉 ' . date('D., M j, Y', strtotime($date . ' 12:00 UTC')) . ": \n" . implode("\n", $readings);
    if (config('bible_reader')) {
        $text .= "\n📖 [Read](" . url('reading/' . $date) . ')';
    }
    return $text;
}

// Posts the reading and poll for $date unless already posted. Returns a status line.
function post_daily_reading(string $date, bool $force_time = false): string
{
    if (!config('daily_reading') || setting('daily_reading_enabled') !== '1') {
        return 'off';
    }
    $due = strtotime($date . ' ' . setting('daily_reading_time_utc', '05:00') . ' UTC');
    if (!$force_time && time() < $due) {
        return "not yet (due " . gmdate('Y-m-d H:i', $due) . " UTC)";
    }
    $readings = readings_for($date);
    if (!$readings) {
        return "no reading scheduled for $date";
    }
    // Claim the day first: the primary key means only one run can ever post it.
    if (q('INSERT IGNORE INTO reading_posts (reading_date) VALUES (?)', [$date])->rowCount() === 0) {
        return "already posted for $date";
    }
    $user = q('SELECT * FROM users WHERE id = ?', [(int)setting('daily_reading_user')])->fetch();
    $topic = (int)setting('daily_reading_topic');
    try {
        $rid = send_message($user, $topic, reading_text($date, $readings), [], 0, [], null);
        $pid = send_message($user, $topic, '', [], 0, [], [
            'question' => 'Finished?', 'options' => READING_POLL_OPTIONS, 'anonymous' => false, 'multiple' => false,
        ]);
    } catch (Throwable $e) {
        q('DELETE FROM reading_posts WHERE reading_date = ? AND reading_message_id IS NULL', [$date]);   // let a later run retry
        throw $e;
    }
    q('UPDATE reading_posts SET reading_message_id = ?, poll_message_id = ? WHERE reading_date = ?', [$rid, $pid, $date]);
    return "posted $date (messages $rid, $pid)";
}
