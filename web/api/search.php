<?php
// GET q, topic (0 = all), before (message id, for more results)
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/chat.php';
require APP_DIR . '/lib/search.php';

$user = api_user();
json_out(search_messages((int)$user['id'], (string)($_GET['q'] ?? ''), (int)($_GET['topic'] ?? 0), (int)($_GET['before'] ?? 0)));
