<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
if (is_post()) csrf_verify();
unset($_SESSION['platform_uid']);
session_regenerate_id(true);
$_SESSION['csrf'] = bin2hex(random_bytes(32));
redirect('platform/login.php');
