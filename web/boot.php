<?php
define('APP_WEB_DIR', __DIR__);
// Finds the private app folder, a folder named chatiferous next to the web folder or its parent
// (web in ~/public_html/chat or ~/public_html: app in ~/chatiferous), or ../app as in the repo.
foreach ([dirname(__DIR__, 2) . '/chatiferous', dirname(__DIR__) . '/chatiferous', dirname(__DIR__) . '/app'] as $dir) {
    if (is_file($dir . '/lib/bootstrap.php')) {
        require $dir . '/lib/bootstrap.php';
        return;
    }
}
http_response_code(500);
exit('App folder not found.');
