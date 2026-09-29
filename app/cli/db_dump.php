<?php
// Writes a gzipped SQL dump of the live database to stdout (used by tools/backup.sh).
// The password goes into a private temporary option file, never onto the command line.
require __DIR__ . '/../lib/bootstrap.php';

$cnf = tempnam(sys_get_temp_dir(), 'dump');
chmod($cnf, 0600);
file_put_contents($cnf, "[client]\nuser=" . config('db_user') . "\npassword=" . config('db_pass') . "\nhost=" . config('db_host') . "\n");
// The commentary text is left out: it's hundreds of megabytes and every word of it can be
// imported again (cli/import_commentary.php), so backups stay small enough to keep many of.
$skip = '--ignore-table=' . escapeshellarg(config('db_name') . '.bible_commentary');
passthru('mysqldump --defaults-extra-file=' . escapeshellarg($cnf) . ' --single-transaction --quick --no-tablespaces '
    . $skip . ' ' . escapeshellarg(config('db_name')) . ' | gzip -9', $status);
unlink($cnf);
exit($status);
