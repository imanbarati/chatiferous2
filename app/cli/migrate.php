<?php
// Applies any sql/NNN_*.sql files not yet applied. Run: php cli/migrate.php
require __DIR__ . '/../lib/bootstrap.php';

db()->exec('CREATE TABLE IF NOT EXISTS migrations (
  name VARCHAR(100) PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$done = q('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(APP_DIR . '/sql/[0-9][0-9][0-9]_*.sql');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $done, true)) {
        continue;
    }
    echo "Applying $name ... ";
    db()->exec(file_get_contents($file));
    q('INSERT INTO migrations (name) VALUES (?)', [$name]);
    echo "done\n";
}
// A stamp of this deployment, which is what scripts and styles are versioned by. It is written
// here because deploy.sh runs this straight after copying the files up; asset() reads its
// contents rather than asking for a file's modification time, which PHP's realpath cache can
// report stale for a couple of minutes after a deploy (so a browser would be sent to the old
// script and the new one wouldn't take effect until later).
@file_put_contents(APP_DIR . '/data/build', (string)time());

echo "Up to date.\n";
