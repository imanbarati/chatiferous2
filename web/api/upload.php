<?php
// POST multipart "file" (sealed=1: already encrypted for a DM): stores it; returns an attachment id for send.php.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/api.php';
require APP_DIR . '/lib/uploads.php';

$user = api_post();
api_run(fn() => store_upload($user, $_FILES['file'] ?? [], !empty($_POST['sealed'])));
