<?php
require __DIR__ . '/includes/bootstrap.php';
if (is_post()) {
    csrf_verify();
    if (current_user()) audit('Logout', 'users', (int) current_user()['user_id'], 'Signed out');
    unset($_SESSION['uid'], $_SESSION['org_id'], $_SESSION['org_code'], $_SESSION['client_org_code'], $_SESSION['client_project_id'], $_SESSION['schema_checked_v6']);
    session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32));
    flash('success', 'You have been logged out.');
}
redirect('login.php');
