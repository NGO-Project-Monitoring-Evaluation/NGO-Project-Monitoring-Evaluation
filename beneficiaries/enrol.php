<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_perm('manage_beneficiaries');
if (!is_post()) { redirect('beneficiaries/index.php'); }
csrf_verify();
$bid = int_or_null(post('beneficiary_id'));
$pid = int_or_null(post('project_id'));
if (!$bid || !$pid || !fetch_value('SELECT 1 FROM beneficiaries WHERE beneficiary_id = ?', [$bid]) || !fetch_value('SELECT 1 FROM projects WHERE project_id = ?', [$pid])) {
    flash('danger', 'Choose a project to enrol the beneficiary in.');
    redirect($bid ? 'beneficiaries/view.php?id=' . $bid : 'beneficiaries/index.php');
}
if (!can_view_project($pid)) { deny_access(); }
if (fetch_value('SELECT 1 FROM project_beneficiaries WHERE project_id = ? AND beneficiary_id = ?', [$pid, $bid])) {
    flash('warning', 'This beneficiary is already enrolled in that project.');
} else {
    q('INSERT INTO project_beneficiaries (project_id, beneficiary_id, enrolled_on) VALUES (?,?,?)', [$pid, $bid, today()]);
    audit('Create', 'project_beneficiaries', (int) db()->lastInsertId(), "Enrolled beneficiary $bid in project $pid");
    flash('success', 'The beneficiary was enrolled in the project.');
}
redirect('beneficiaries/view.php?id=' . $bid);
