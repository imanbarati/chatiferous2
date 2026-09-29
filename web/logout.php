<?php
require __DIR__ . '/boot.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    log_out();
}
redirect('login.php');
