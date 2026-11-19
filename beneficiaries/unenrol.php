<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_perm('manage_beneficiaries');
if (!is_post()) { redirect('beneficiaries/index.php'); }
csrf_verify();
$id = int_or_null(post('id'));
$row = $id ? fetch_one('SELECT beneficiary_id, project_id FROM project_beneficiaries WHERE id = ?', [$id]) : null;
if (!$row) { flash('danger', 'That enrolment could not be found.'); redirect('beneficiaries/index.php'); }
q('DELETE FROM project_beneficiaries WHERE id = ?', [$id]);
audit('Delete', 'project_beneficiaries', $id, "Removed beneficiary {$row['beneficiary_id']} from project {$row['project_id']}");
flash('success', 'The beneficiary was removed from the project.');
redirect('beneficiaries/view.php?id=' . $row['beneficiary_id']);
