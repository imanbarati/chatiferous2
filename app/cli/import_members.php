<?php
// Creates an unclaimed account for every member found in the export.
// Input: a members.json made by import/prepare_history.py (private, never committed).
// Bots that posted in the group become system accounts (they can post, e.g. the built-in
// daily reading, but can't sign in or be messaged). --bot-name renames them, e.g. "Daily Reading".
// Run: php cli/import_members.php data/members.json --owner=<your telegram id> [--bot-name=<name>]
// Safe to re-run: existing accounts are left alone (names of unclaimed ones are refreshed).
require __DIR__ . '/../lib/bootstrap.php';

$file = $argv[1] ?? '';
$owner = 0;
$bot_name = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--owner=')) {
        $owner = (int)substr($arg, 8);
    } elseif (str_starts_with($arg, '--bot-name=')) {
        $bot_name = mb_substr(trim(substr($arg, 11)), 0, 128) ?: null;
    }
}
if (!is_file($file)) {
    exit("Usage: php cli/import_members.php members.json --owner=<your telegram id> [--bot-name=<name>]\n");
}

$members = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$added = $refreshed = 0;

foreach ($members as $m) {
    $tg = (int)$m['telegram_id'];
    $deleted = !empty($m['deleted']);
    $bot = !empty($m['bot']);
    $name = $bot && $bot_name !== null ? $bot_name : ($deleted ? 'Deleted Account' : mb_substr(trim($m['name']), 0, 128));
    $existing = q('SELECT id, status FROM users WHERE telegram_id = ?', [$tg])->fetch();
    if ($existing) {
        if ($existing['status'] === 'unclaimed' && !$bot) {
            q('UPDATE users SET display_name = ? WHERE id = ?', [$name, $existing['id']]);
            $refreshed++;
        }
        continue;
    }
    q('INSERT INTO users (telegram_id, display_name, role, status, color_index) VALUES (?, ?, ?, ?, ?)', [
        $tg, $name, $bot ? 'system' : ($tg === $owner ? 'owner' : 'member'),
        $bot ? 'active' : ($deleted ? 'deleted' : 'unclaimed'), $tg % 7,
    ]);
    $added++;
}

if ($owner) {
    q("UPDATE users SET role = 'owner' WHERE telegram_id = ?", [$owner]);
}
echo "Added $added, refreshed $refreshed, total " . count($members) . " in file.\n";
