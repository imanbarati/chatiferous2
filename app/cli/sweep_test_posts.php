<?php
// Deletes the browser tests' clearly-marked posts ("[Automated test…", by the owner, in the last
// hour) if a test run crashed before cleaning up after itself. Run by tests/run_browser_tests.sh.
require __DIR__ . '/../lib/bootstrap.php';
require_once APP_DIR . '/lib/actions.php';

$owner = q("SELECT * FROM users WHERE role = 'owner'")->fetch();
$ids = q("SELECT m.id FROM messages m LEFT JOIN polls p ON p.message_id = m.id
          WHERE m.deleted_at IS NULL AND m.user_id = ? AND m.created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR
            AND (m.text LIKE '[Automated test%' OR p.question LIKE '[Automated test%')", [$owner['id']])->fetchAll(PDO::FETCH_COLUMN);
foreach ($ids as $id) {
    delete_message($owner, (int)$id);
}
if ($ids) {
    echo 'Swept ' . count($ids) . " leftover test posts.\n";
}
