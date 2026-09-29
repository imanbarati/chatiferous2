<?php
// GET url: the link preview for that address ({preview: null} if there isn't one).
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';
require APP_DIR . '/lib/previews.php';

$user = api_user();
api_run(fn() => ['preview' => preview_for($user, (string)($_GET['url'] ?? ''))]);
