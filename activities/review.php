<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('manage_activities');
$id = int_or_null(get('id') ?: post('id'));
$a = $id ? fetch_one('SELECT a.*, p.title AS project_title FROM activities a JOIN projects p ON p.project_id=a.project_id WHERE a.activity_id=?', [$id]) : null;
if (!$a) { not_found('activity'); }
if (!can_view_project((int)$a['project_id'])) { deny_access(); }
$errors = [];
if (is_post()) {
    csrf_verify();
    $decision = post('decision');
    $note = trim((string)post('review_note'));
    if (!in_array($decision, ['approve','reject'], true)) $errors[] = 'Choose approve or reject.';
    if (($a['approval_status'] ?? '') !== 'Pending') $errors[] = 'This activity is not awaiting approval.';
    if ($decision === 'reject' && $note === '') $errors[] = 'Provide a reason when rejecting a submission.';
    if ($decision === 'approve') {
        $weightTotal = (float)fetch_value('SELECT COALESCE(SUM(weight_percent),0) FROM activities WHERE project_id=?', [(int)$a['project_id']]);
        if (abs($weightTotal - 100.0) > 0.01) $errors[] = 'The project activity weights must total 100% before completion can be approved. Current total: ' . number_format($weightTotal, 2) . '%. Update the activity weights first.';
    }
    if (!$errors) {
        if ($decision === 'approve') {
            q("UPDATE activities SET approval_status='Approved', status='Completed', progress_percent=100, reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE activity_id=? AND approval_status='Pending'", [$user['user_id'], $note ?: null, $id]);
            audit('Approve', 'activities', $id, 'Approved completion for ' . $a['title']);
            flash('success', 'Activity approved. Its weighted contribution is now included in confirmed project progress.');
        } else {
            q("UPDATE activities SET approval_status='Rejected', status='In progress', progress_percent=LEAST(progress_percent,99), reviewed_by=?, reviewed_at=NOW(), review_note=? WHERE activity_id=? AND approval_status='Pending'", [$user['user_id'], $note, $id]);
            audit('Reject', 'activities', $id, 'Rejected completion for ' . $a['title']);
            flash('success', 'Submission rejected and returned to the site engineer.');
        }
        redirect('projects/view.php?id=' . (int)$a['project_id']);
    }
}
render_header('Review activity completion', 'activities', [['Activities','activities/index.php'], [$a['title']]]);
show_errors($errors);
?>
<div class="card" style="max-width:760px"><div class="card-body">
<h2 class="h5"><?= e($a['title']) ?></h2><p class="text-muted">Project: <?= e($a['project_title']) ?></p>
<p><strong>Submitted notes:</strong><br><?= nl2br(e((string)$a['completion_notes'])) ?></p>
<p><strong>Activity contribution:</strong> <?= e(fmt_number((float)$a['weight_percent'])) ?>% of the project</p>
<p><strong>Submitted:</strong> <?= e(fmt_datetime($a['submitted_at'])) ?></p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
<label class="form-label" for="review_note">Review comments</label><textarea class="form-control mb-3" id="review_note" name="review_note" rows="4" maxlength="5000" placeholder="Required when rejecting; optional when approving"><?= e((string)post('review_note')) ?></textarea>
<button class="btn btn-success" name="decision" value="approve">Approve completion</button>
<button class="btn btn-outline-danger" name="decision" value="reject">Reject and return</button>
<a class="btn btn-outline-secondary" href="<?= e(url('projects/view.php?id='.(int)$a['project_id'])) ?>">Cancel</a>
</form></div></div>
<?php render_footer();
