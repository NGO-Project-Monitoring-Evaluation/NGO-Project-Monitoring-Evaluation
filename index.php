<?php
require __DIR__ . '/includes/bootstrap.php';
if (current_platform_user()) redirect('platform/index.php');
redirect(current_user() ? 'dashboard.php' : 'login.php');
