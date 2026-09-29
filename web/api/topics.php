<?php
// GET: the topic list with unread counts and last-message previews.
require __DIR__ . '/../boot.php';
require APP_DIR . '/lib/chat.php';

$user = api_user();
json_out(['topics' => topic_list((int)$user['id']), 'cursor' => change_cursor()]);
