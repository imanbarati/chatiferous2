<?php
// Daily cron job: tidy up.
//   - uploads never attached to a message (abandoned before sending), after a day
//   - the live-update change log, after 30 days (far-behind clients simply reload)
//   - used Telegram sign-in links, after a day
//   - sign-in sessions unused for 90 days (shared hosting often never runs PHP's own cleanup)
require __DIR__ . '/../lib/bootstrap.php';

$root = config('uploads_dir');
$orphans = q('SELECT id, path FROM attachments WHERE message_id IS NULL AND created_at < UTC_TIMESTAMP() - INTERVAL 1 DAY')->fetchAll();
foreach ($orphans as $a) {
    if ($a['path'] && is_file("$root/{$a['path']}")) {
        unlink("$root/{$a['path']}");
    }
    q('DELETE FROM attachments WHERE id = ?', [$a['id']]);
}
$changes = q('DELETE FROM changes WHERE created_at < UTC_TIMESTAMP() - INTERVAL 30 DAY')->rowCount();
q('DELETE FROM telegram_logins_used WHERE used_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');   // long expired anyway

$sessions = 0;
foreach (glob(config('session_dir') . '/sess_*') ?: [] as $f) {
    if (filemtime($f) < time() - 90 * 86400) {
        unlink($f);
        $sessions++;
    }
}
echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] housekeeping: ' . count($orphans) . " unsent uploads, $changes change-log rows, $sessions old sessions removed\n";
