<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('manage_activities');

$statuses = ['Not started', 'In progress', 'Completed', 'Delayed'];
$engineers = fetch_all("SELECT u.user_id, u.full_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.is_active=1 AND r.role_name='Field Officer' ORDER BY u.full_name");
$id = int_or_null(get('id'));
$activity = $id ? fetch_one('SELECT * FROM activities WHERE activity_id = ?', [$id]) : null;
if ($id && !$activity) { not_found('activity'); }
if ($activity && !can_view_project((int)$activity['project_id'])) { deny_access(); }

$data = $activity ?: ['project_id' => int_or_null(get('project_id')), 'title' => '', 'assigned_to' => '', 'planned_start' => '', 'planned_end' => '', 'status' => 'Not started', 'weight_percent' => '0'];
$errors = [];

if (is_post()) {
    csrf_verify();
    $data = ['project_id' => post('project_id'), 'title' => post('title'), 'assigned_to' => post('assigned_to'),
             'planned_start' => post('planned_start'), 'planned_end' => post('planned_end'), 'status' => post('status'), 'weight_percent' => post('weight_percent')];
    $pid = int_or_null($data['project_id']);
    if (!$pid || !fetch_value('SELECT 1 FROM projects WHERE project_id = ?', [$pid]) || !can_view_project($pid)) $errors[] = 'Choose the project this activity belongs to.';
    if ($data['title'] === '')            $errors[] = 'Enter the activity title.';
    if (mb_strlen($data['title']) > 150)  $errors[] = 'The title must not be longer than 150 characters.';
    if (!valid_date($data['planned_start'])) $errors[] = 'Enter a valid planned start date.';
    if (!valid_date($data['planned_end']))   $errors[] = 'Enter a valid planned end date.';
    if (valid_date($data['planned_start']) && valid_date($data['planned_end']) && $data['planned_end'] < $data['planned_start']) {
        $errors[] = 'The planned end date cannot be before the planned start date.';
    }
    if (!is_numeric($data['weight_percent']) || (float)$data['weight_percent'] < 0 || (float)$data['weight_percent'] > 100) $errors[] = 'Activity weight must be between 0 and 100.';
    if (!in_array($data['status'], $statuses, true)) $errors[] = 'Choose a valid status.';
    $assignee = int_or_null($data['assigned_to']);
    if ($data['assigned_to'] !== '' && !$assignee) $errors[] = 'Choose a valid assignee.';
    if ($assignee && !fetch_value("SELECT 1 FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=? AND (u.is_active=1 OR u.user_id=?) AND r.role_name='Field Officer'", [$assignee, $activity['assigned_to'] ?? 0])) $errors[] = 'The assignee must be an active site engineer (Field Officer).';

    if (!$errors) {
        $progress = $activity ? (int) $activity['progress_percent'] : 0;
        [$status, $progress, $note] = normalise_activity_state($data['status'], $progress);
        if ($activity) {
            q('UPDATE activities SET project_id=?, title=?, assigned_to=?, planned_start=?, planned_end=?, status=?, weight_percent=?, progress_percent=?, updated_at=NOW() WHERE activity_id=?',
                [$pid, $data['title'], $assignee, $data['planned_start'], $data['planned_end'], $status, (float)$data['weight_percent'], $progress, $id]);
            audit('Update', 'activities', $id, 'Updated activity: ' . $data['title']);
            flash('success', 'The activity was updated.' . ($note ? ' ' . $note : ''));
        } else {
            q('INSERT INTO activities (project_id, title, assigned_to, planned_start, planned_end, status, weight_percent, progress_percent, updated_at) VALUES (?,?,?,?,?,?,?,?,NOW())',
                [$pid, $data['title'], $assignee, $data['planned_start'], $data['planned_end'], $status, (float)$data['weight_percent'], $progress]);
            $id = (int) db()->lastInsertId();
            audit('Create', 'activities', $id, 'Created activity: ' . $data['title']);
            flash('success', 'The activity was created.' . ($note ? ' ' . $note : ''));
        }
        redirect('projects/view.php?id=' . $pid);
    }
}

render_header($activity ? 'Edit activity' : 'New activity', 'activities', [['Activities', 'activities/index.php'], [$activity ? 'Edit activity' : 'New activity']]);
show_errors($errors);
?>
<div class="card" style="max-width:820px"><div class="card-body">
<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label required" for="project_id">Project</label>
      <select class="form-select" id="project_id" name="project_id"><?= options(projects_list(), 'project_id', 'title', $data['project_id'], 'Select a project') ?></select></div>
    <div class="col-md-6"><label class="form-label" for="assigned_to">Assigned to</label>
      <select class="form-select" id="assigned_to" name="assigned_to"><?= options($engineers, 'user_id', 'full_name', $data['assigned_to'], 'Not assigned') ?></select></div>
    <div class="col-12"><label class="form-label required" for="title">Activity title</label>
      <input class="form-control" id="title" name="title" maxlength="150" value="<?= e($data['title']) ?>" required></div>
    <div class="col-md-4"><label class="form-label required" for="planned_start">Planned start</label>
      <input class="form-control" type="date" id="planned_start" name="planned_start" value="<?= e($data['planned_start']) ?>" required></div>
    <div class="col-md-4"><label class="form-label required" for="planned_end">Planned end</label>
      <input class="form-control" type="date" id="planned_end" name="planned_end" value="<?= e($data['planned_end']) ?>" required></div>
    <div class="col-md-4"><label class="form-label required" for="status">Status</label>
      <select class="form-select" id="status" name="status"><?= simple_options($statuses, $data['status']) ?></select></div>
  </div>
  <div class="mt-3"><label class="form-label" for="weight_percent">Contribution to overall project (%)</label><input class="form-control" type="number" min="0" max="100" step="0.01" id="weight_percent" name="weight_percent" value="<?= e((string)$data['weight_percent']) ?>" required><div class="form-text">All activity weights for a project must total 100% before progress tracking is finalized.</div></div>
  <div class="mt-4"><button class="btn btn-primary" type="submit">Save activity</button> <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
  <?php if ($activity): ?><span class="ms-2 small text-muted">Progress is recorded with the Update button on the activity list.</span><?php endif; ?></div>
</form>
<?php if ($activity): ?>
<form method="post" action="delete.php" class="mt-3" data-confirm="Delete this activity? This cannot be undone.">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $id ?>"><button class="btn btn-outline-danger btn-sm" type="submit">Delete this activity</button></form>
<?php endif; ?>
</div></div>
<?php render_footer();
