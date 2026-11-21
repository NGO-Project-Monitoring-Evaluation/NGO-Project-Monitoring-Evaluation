<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('update_progress');

$statuses = is_officer() ? ['Not started', 'In progress', 'Delayed'] : ['Not started', 'In progress', 'Completed', 'Delayed'];
$id = int_or_null(get('id') ?: post('id'));
$a = $id ? fetch_one('SELECT a.*, p.title AS project_title, u.full_name AS assignee_name
                        FROM activities a JOIN projects p ON p.project_id = a.project_id LEFT JOIN users u ON u.user_id = a.assigned_to
                       WHERE a.activity_id = ?', [$id]) : null;
if (!$a) { not_found('activity'); }
if (!can_view_project((int)$a['project_id'])) { deny_access(); }
// A field officer may update only the activities assigned to them (checked on the server).
if (!can_update_activity($a)) { deny_access(); }
$from = (get('from') ?: post('from')) === 'dashboard' ? 'dashboard' : '';

$errors = [];
$form = ['status' => $a['status'], 'progress_percent' => (string) $a['progress_percent'], 'completion_notes' => (string) $a['completion_notes'], 'delay_reason' => (string) $a['delay_reason']];

if (is_post()) {
    csrf_verify();
    $form = ['status' => post('status'), 'progress_percent' => post('progress_percent'), 'completion_notes' => post('completion_notes'), 'delay_reason' => post('delay_reason')];
    $submitForApproval = is_officer() && post('submit_for_approval') === '1';
    if ($submitForApproval) { $form['status'] = 'Completed'; $form['progress_percent'] = '100'; }
    if (is_officer() && $form['status'] === 'Completed' && !$submitForApproval) $errors[] = 'Use the completion confirmation checkbox to submit this activity for approval.';
    $validStatuses = $submitForApproval ? array_merge($statuses, ['Completed']) : $statuses;
    if (!in_array($form['status'], $validStatuses, true)) $errors[] = 'Choose a valid status.';
    if (!ctype_digit($form['progress_percent']) || (int) $form['progress_percent'] > 100) $errors[] = 'Progress must be a whole number from 0 to 100.';
    if (mb_strlen($form['completion_notes']) > 5000) $errors[] = 'The notes are too long (5000 characters at most).';
    if (mb_strlen($form['delay_reason']) > 2000) $errors[] = 'The reason for the delay is too long (2000 characters at most).';
    if (!$errors) {
        [$status, $progress, $note] = normalise_activity_state($form['status'], (int) $form['progress_percent']);
        if ($status === 'Delayed' && $form['delay_reason'] === '') { $errors[] = 'Record the reason for the delay when the status is Delayed.'; }
    }
    if (!$errors) {
        $changed = $form['delay_reason'] !== trim((string) $a['delay_reason']);
        $at = $form['delay_reason'] === '' ? null : ($changed ? date('Y-m-d H:i:s') : $a['delay_reason_at']);
        $approval = is_officer() && $status === 'Completed' ? 'Pending' : (is_officer() ? (string)$a['approval_status'] : (string)$a['approval_status']);
        if (is_officer() && $status === 'Completed') {
            q("UPDATE activities SET status='In progress', progress_percent=100, completion_notes=?, delay_reason=?, delay_reason_at=?, approval_status='Pending', submitted_by=?, submitted_at=NOW(), updated_at=NOW() WHERE activity_id=?", [$form['completion_notes'] ?: null, $form['delay_reason'] ?: null, $at, $user['user_id'], $id]);
        } else {
            q('UPDATE activities SET status=?, progress_percent=?, completion_notes=?, delay_reason=?, delay_reason_at=?, updated_at=NOW() WHERE activity_id=?', [$status, $progress, $form['completion_notes'] ?: null, $form['delay_reason'] ?: null, $at, $id]);
        }
        audit('Update', 'activities', $id, "Progress update on '{$a['title']}': $status, $progress%" . ($changed && $form['delay_reason'] !== '' ? '; reason for delay recorded' : ''));
        $stillLate = $status !== 'Completed' && ($status === 'Delayed' || ($a['planned_end'] && $a['planned_end'] < today()));
        flash('success', (is_officer() && $status === 'Completed' ? 'Completion submitted to the project manager for confirmation.' : 'The activity was updated.') . ($note ? ' ' . $note : '') . ($stillLate && $form['delay_reason'] === '' ? ' This activity is overdue: please record the reason for the delay.' : ''));
        redirect($from === 'dashboard' ? 'dashboard.php' : (is_officer() ? 'activities/index.php' : 'projects/view.php?id=' . $a['project_id']));
    }
}
$late = days_overdue($a['planned_end']);
$flagged = activity_is_flagged($a);
$lines = can('view_finance_detail') ? array_values(array_filter(finance_lines(), fn($l) => (int) $l['activity_id'] === (int) $id)) : [];

render_header('Update activity', 'activities', [['Activities', 'activities/index.php'], [$a['title']]]);
show_errors($errors);
if ($flagged && empty($a['delay_reason'])): ?>
<div class="alert alert-danger"><strong>Follow-up needed.</strong> This activity was due on <?= e(fmt_date($a['planned_end'])) ?><?= $late ? ' (' . $late . ' day' . ($late === 1 ? '' : 's') . ' overdue)' : '' ?> and is not completed. Please record the reason for the delay below.</div>
<?php endif; ?>
<div class="row g-3"><div class="col-lg-5"><div class="card h-100"><div class="card-header">Activity</div><div class="card-body">
  <h2 class="h5"><?= e($a['title']) ?></h2>
  <dl class="row mb-0 small">
    <dt class="col-5">Project</dt><dd class="col-7"><a href="<?= e(url('projects/view.php?id=' . $a['project_id'])) ?>"><?= e($a['project_title']) ?></a></dd>
    <dt class="col-5">Assigned to</dt><dd class="col-7"><?= e($a['assignee_name'] ?: 'Not assigned') ?></dd>
    <dt class="col-5">Planned dates</dt><dd class="col-7"><?= e(fmt_date($a['planned_start'])) ?> &ndash; <?= e(fmt_date($a['planned_end'])) ?></dd>
    <dt class="col-5">Approval</dt><dd class="col-7"><?= e($a['approval_status'] ?? 'Not submitted') ?></dd>
    <dt class="col-5">Current status</dt><dd class="col-7"><?= activity_status_badge($a) ?></dd>
    <dt class="col-5">Last updated</dt><dd class="col-7"><?= e(fmt_datetime($a['updated_at'])) ?></dd>
  </dl>
  <?php if ($lines): ?><hr><div class="small fw-semibold mb-1">Linked budget (same numbers the finance team sees)</div>
    <?php foreach ($lines as $l): ?><div class="small mb-1"><?= e($l['description']) ?>: <?= e(money($l['spent'])) ?> spent of <?= e(money($l['planned_amount'])) ?>, <?= e(money($l['committed'])) ?> committed <?= finance_status_badge($l['status']) ?></div><?php endforeach; ?><?php endif; ?>
  </div></div></div>
<div class="col-lg-7"><div class="card"><div class="card-header">Record progress</div><div class="card-body">
<form method="post" novalidate>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="from" value="<?= e($from) ?>">
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label required" for="status">Status</label>
      <select class="form-select" id="status" name="status"><?= simple_options($statuses, $form['status']) ?></select></div>
    <div class="col-md-6"><label class="form-label required" for="progress_percent">Progress (%)</label>
      <input class="form-control" type="number" min="0" max="100" step="1" id="progress_percent" name="progress_percent" value="<?= e($form['progress_percent']) ?>" required></div>
    <div class="col-12"><label class="form-label" for="completion_notes">Notes or completion notes</label>
      <textarea class="form-control" id="completion_notes" name="completion_notes" rows="3"><?= e($form['completion_notes']) ?></textarea></div>
    <div class="col-12" id="delay"><label class="form-label <?= $flagged ? 'required' : '' ?>" for="delay_reason">Reason for delay</label>
      <textarea class="form-control <?= $flagged && $form['delay_reason'] === '' ? 'border-danger' : '' ?>" id="delay_reason" name="delay_reason" rows="3" maxlength="2000" placeholder="For example: supplier delivered late, heavy rain, staff absence, funds not yet released"><?= e($form['delay_reason']) ?></textarea>
      <div class="form-text">Required when the status is Delayed. Managers and the finance team can see this reason on the dashboard and in reports.<?= $a['delay_reason_at'] ? ' Last recorded ' . e(fmt_datetime($a['delay_reason_at'])) . '.' : '' ?></div></div>
  </div>
  <?php if (is_officer()): ?><div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="submit_for_approval" name="submit_for_approval" value="1"><label class="form-check-label" for="submit_for_approval">I confirm this activity is completed and submit it to the project manager for approval.</label></div><?php endif; ?>
  <p class="form-text mt-2">A site engineer's completion submission does not count toward official project progress until approved by the project manager.</p>
  <button class="btn btn-primary" type="submit">Save update</button> <a class="btn btn-outline-secondary" href="<?= e($from === 'dashboard' ? url('dashboard.php') : 'index.php') ?>">Cancel</a>
</form></div></div></div></div>
<?php render_footer();
