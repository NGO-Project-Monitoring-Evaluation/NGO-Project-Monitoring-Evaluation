<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$admin = require_perm('manage_users');
if (!is_post()) { redirect('admin/users.php'); }
csrf_verify();
$id = int_or_null(post('id'));
$t = $id ? fetch_one('SELECT * FROM users WHERE user_id = ?', [$id]) : null;
if (!$t) { flash('danger', 'The account could not be found.'); redirect('admin/users.php'); }
if ((int) $t['user_id'] === (int) $admin['user_id']) { flash('danger', 'You cannot deactivate your own account.'); redirect('admin/users.php'); }
$new = $t['is_active'] ? 0 : 1;
if (!$new && $t['role_id'] == 1 && (int) fetch_value('SELECT COUNT(*) FROM users WHERE role_id = 1 AND is_active = 1') <= 1) {
    flash('danger', 'At least one active administrator is required.'); redirect('admin/users.php');
}
q('UPDATE users SET is_active = ? WHERE user_id = ?', [$new, $id]);
audit('Update', 'users', $id, ($new ? 'Activated' : 'Deactivated') . ' account ' . $t['email']);
flash('success', 'The account was ' . ($new ? 'activated.' : 'deactivated.'));
redirect('admin/users.php');
