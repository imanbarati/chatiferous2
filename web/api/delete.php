<?php
// POST id
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';

$user = api_post();
api_run(fn() => delete_message($user, (int)($_POST['id'] ?? 0)));
