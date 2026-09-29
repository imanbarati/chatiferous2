<?php
// Holds push notifications for a while (used by tests/run_browser_tests.sh while it posts on the
// live site), or releases them. Run: php cli/push_pause.php <seconds> | off
require __DIR__ . '/../lib/bootstrap.php';
require APP_DIR . '/lib/reading.php';   // setting()

$arg = $argv[1] ?? '';
if ($arg === 'off') {
    set_setting('push_paused_until', '');
} elseif (ctype_digit($arg) && (int)$arg <= 3600) {
    set_setting('push_paused_until', gmdate('Y-m-d H:i:s', time() + (int)$arg));
} else {
    exit("Usage: php cli/push_pause.php <seconds, up to 3600> | off\n");
}
