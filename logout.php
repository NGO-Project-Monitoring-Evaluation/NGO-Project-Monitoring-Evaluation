<?php
require __DIR__ . '/includes/bootstrap.php';
if (is_post()) {
    csrf_verify();
    if (current_user()) {
        audit('Logout', 'users', (int) current_user()['user_id'], 'Signed out');
    }
    do_logout();
    session_start();
    flash('success', 'You have been logged out.');
}
redirect('login.php');
