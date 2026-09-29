<?php
// Replaces the reading schedule from a CSV (date, readings_merged). Run: php cli/import_schedule.php file.csv
require __DIR__ . '/../lib/bootstrap.php';
require APP_DIR . '/lib/reading.php';

[$rows, $problems] = parse_schedule_csv($argv[1] ?? '');
foreach ($problems as $p) {
    echo "  $p\n";
}
if (!$rows) {
    exit("Nothing imported.\n");
}
replace_schedule($rows);
echo count($rows) . ' readings imported, ' . $rows[0][0] . ' to ' . end($rows)[0] . ".\n";
