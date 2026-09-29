<?php
// Makes the key pair for push notifications. Paste the two lines into config.php.
// Run once: php cli/make_vapid_keys.php  (after composer install)
require __DIR__ . '/../vendor/autoload.php';

$k = Minishlink\WebPush\VAPID::createVapidKeys();
echo "'vapid_public'  => '{$k['publicKey']}',\n";
echo "'vapid_private' => '{$k['privateKey']}',\n";
