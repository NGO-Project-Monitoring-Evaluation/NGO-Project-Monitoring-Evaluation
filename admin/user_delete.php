<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$admin = require_perm('manage_users');
if (!is_post()) { redirect('admin/users.php'); }
csrf_verify();
$id = int_or_null(post('id'));
$t = $id ? fetch_one('SELECT * FROM users WHERE user_id = ? AND role_id <> 1', [$id]) : null;
if (!$t) { flash('danger', 'The account could not be found.'); redirect('admin/users.php'); }
if ((int) $t['user_id'] === (int) $admin['user_id']) { flash('danger', 'You cannot delete your own account.'); redirect('admin/users.php'); }
if ($t['role_id'] == 10 && $t['is_active'] && (int) fetch_value('SELECT COUNT(*) FROM users WHERE role_id = 10 AND is_active = 1') <= 1) {
    flash('danger', 'At least one active administrator is required.'); redirect('admin/users.php');
}
$refs = (int) fetch_value('SELECT (SELECT COUNT(*) FROM projects WHERE manager_id = ? OR created_by = ?) + (SELECT COUNT(*) FROM activities WHERE assigned_to = ?)
        + (SELECT COUNT(*) FROM beneficiaries WHERE registered_by = ?) + (SELECT COUNT(*) FROM indicator_values WHERE recorded_by = ?) + (SELECT COUNT(*) FROM audit_log WHERE user_id = ?)',
        [$id, $id, $id, $id, $id, $id]);
if ($refs) {
    flash('danger', 'This account cannot be deleted because records and audit entries refer to it. Deactivate it instead, so that the history keeps its author.');
    redirect('admin/users.php');
}
q('DELETE FROM users WHERE user_id = ?', [$id]);
audit('Delete', 'users', $id, 'Deleted account ' . $t['email']);
flash('success', 'The account was deleted.');
redirect('admin/users.php');
