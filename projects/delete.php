<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_perm('manage_projects');
if (!is_post()) { redirect('projects/index.php'); }
csrf_verify();

$id = int_or_null(post('id'));
$p = $id ? fetch_one('SELECT title FROM projects WHERE project_id = ?', [$id]) : null;
if (!$p) { flash('danger', 'The project could not be found.'); redirect('projects/index.php'); }

$acts = (int) fetch_value('SELECT COUNT(*) FROM activities WHERE project_id = ?', [$id]);
$inds = (int) fetch_value('SELECT COUNT(*) FROM indicators WHERE project_id = ?', [$id]);
$enr  = (int) fetch_value('SELECT COUNT(*) FROM project_beneficiaries WHERE project_id = ?', [$id]);
$bud = (int) fetch_value('SELECT COUNT(*) FROM budget_lines WHERE project_id = ?', [$id]) + (int) fetch_value('SELECT COUNT(*) FROM grants WHERE project_id = ?', [$id]);
if ($acts || $inds || $enr || $bud) {
    flash('danger', "This project cannot be deleted because other records depend on it ($acts activities, $inds indicators, $enr enrolments, $bud budget lines or grants). Delete or move those records first, or set the project status to Completed or On hold.");
    redirect('projects/view.php?id=' . $id);
}
q('DELETE FROM projects WHERE project_id = ?', [$id]);
audit('Delete', 'projects', $id, 'Deleted project: ' . $p['title']);
flash('success', 'The project was deleted.');
redirect('projects/index.php');
