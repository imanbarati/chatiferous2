<?php
// Cron job (every 15 minutes): posts today's reading and poll once it's past the
// posting time. Uses the UTC date. Safe to run as often as you like.
//   */15 * * * * /opt/cpanel/ea-php83/root/usr/bin/php ~/chatiferous/cli/daily_reading.php >> ~/chatiferous/data/daily_reading.log 2>&1
require __DIR__ . '/../lib/bootstrap.php';
require APP_DIR . '/lib/reading.php';
if (!config('daily_reading')) {
    exit;   // the feature is off in config.php
}

set_setting('daily_reading_last_check', gmdate('Y-m-d H:i:s'));   // shown on the admin page: proof the timer runs
$status = post_daily_reading(gmdate('Y-m-d'));
if (!str_starts_with($status, 'not yet') && !str_starts_with($status, 'already')) {
    echo '[' . gmdate('Y-m-d H:i:s') . " UTC] $status\n";
}
