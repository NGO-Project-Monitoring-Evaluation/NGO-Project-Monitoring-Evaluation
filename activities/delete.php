<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_perm('manage_activities');
if (!is_post()) { redirect('activities/index.php'); }
csrf_verify();
$id = int_or_null(post('id'));
$a = $id ? fetch_one('SELECT title, project_id FROM activities WHERE activity_id = ?', [$id]) : null;
if (!$a) { flash('danger', 'The activity could not be found.'); redirect('activities/index.php'); }
if (!can_view_project((int)$a['project_id'])) { deny_access(); }
if ((int) fetch_value('SELECT COUNT(*) FROM budget_lines WHERE activity_id = ?', [$id])) {
    flash('danger', 'This activity cannot be deleted because budget lines are linked to it. Unlink or delete those budget lines first.');
    redirect('projects/view.php?id=' . $a['project_id']);
}
q('DELETE FROM activities WHERE activity_id = ?', [$id]);
audit('Delete', 'activities', $id, 'Deleted activity: ' . $a['title']);
flash('success', 'The activity was deleted.');
redirect('projects/view.php?id=' . $a['project_id']);
