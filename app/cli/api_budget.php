<?php
// What this month has cost of API.Bible's allowance, and what is being kept from them.
//   php cli/api_budget.php
require __DIR__ . '/../lib/bootstrap.php';
require_once APP_DIR . '/lib/bible.php';

$b = api_bible_budget();
printf("%s: %d of %d requests used, %d left\n", $b['month'], $b['used'], $b['limit'], $b['left']);
foreach (q('SELECT month, calls FROM api_bible_usage ORDER BY month DESC LIMIT 6')->fetchAll() as $r) {
    printf("  %s  %s\n", $r['month'], $r['calls']);
}
printf("cached: %d chapters, %d single verses\n",
    (int)q('SELECT COUNT(*) FROM bible_remote_chapters')->fetchColumn(),
    (int)q('SELECT COUNT(*) FROM bible_remote_verses')->fetchColumn());
