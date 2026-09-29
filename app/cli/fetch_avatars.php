<?php
// Fetches Telegram profile photos (through the bot) for members who don't have
// a photo yet. Photos members uploaded themselves are never replaced.
// Run: php cli/fetch_avatars.php
require __DIR__ . '/../lib/bootstrap.php';
require APP_DIR . '/lib/avatars.php';

$users = q("SELECT id, telegram_id, display_name FROM users
            WHERE telegram_id IS NOT NULL AND avatar_path IS NULL AND status IN ('active', 'unclaimed') AND role <> 'system'")->fetchAll();
$got = 0;
foreach ($users as $u) {
    if (avatar_from_bot((int)$u['id'], (int)$u['telegram_id'])) {
        $got++;
    }
    usleep(120000); // stay well under Telegram's rate limit
}
echo "Photos found for $got of " . count($users) . " members.\n";
