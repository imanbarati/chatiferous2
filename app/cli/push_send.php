<?php
// Cron job (every minute): sends notifications for new messages.
//   * * * * * /opt/cpanel/ea-php83/root/usr/bin/php ~/chatiferous/cli/push_send.php >> ~/chatiferous/data/push.log 2>&1
require __DIR__ . '/../lib/bootstrap.php';
require APP_DIR . '/lib/push.php';

// Only one copy at a time, even if a run is slow.
$lock = fopen(APP_DIR . '/data/push.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit;
}
// Held while the browser tests run on the live site (tests/run_browser_tests.sh), so nothing a test
// posts, even by mistake, is announced; afterwards the backlog goes out minus the deleted test posts.
if (gmdate('Y-m-d H:i:s') < (string)setting('push_paused_until', '')) {
    exit;
}
set_setting('push_last_run', gmdate('Y-m-d H:i:s'));
$status = push_send_pending();
if ($status !== 'nothing new') {
    echo '[' . gmdate('Y-m-d H:i:s') . " UTC] $status\n";
}
