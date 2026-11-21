<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('manage_projects');


$statuses = ['Planned', 'Ongoing', 'Completed', 'On hold'];
$projectManagers = fetch_all("SELECT u.user_id,u.full_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.is_active=1 AND r.role_name='Project Manager' ORDER BY u.full_name");
$id = int_or_null(get('id'));
$project = $id ? fetch_one('SELECT * FROM projects WHERE project_id = ?', [$id]) : null;
if ($id && !$project) { not_found('project'); }
if ($project && !can_view_project((int) $id)) { deny_access(); }

$data = $project ?: ['title' => '', 'description' => '', 'location' => '', 'start_date' => '', 'end_date' => '', 'status' => 'Planned', 'manager_id' => $user['user_id'], 'allocated_amount' => '0'];
$errors = [];

if (is_post()) {
    csrf_verify();
    $data = [
        'title'       => post('title'),
        'description' => post('description'),
        'location'    => post('location'),
        'start_date'  => post('start_date'),
        'end_date'    => post('end_date'),
        'status'      => post('status'),
        'manager_id'  => post('manager_id'),
        'allocated_amount' => post('allocated_amount'),
    ];
    if ($data['title'] === '')                    $errors[] = 'Enter the project title.';
    if (mb_strlen($data['title']) > 150)          $errors[] = 'The title must not be longer than 150 characters.';
    if (mb_strlen($data['location']) > 150)       $errors[] = 'The location must not be longer than 150 characters.';
    if (!valid_date($data['start_date']))         $errors[] = 'Enter a valid start date.';
    if (!valid_date($data['end_date']))           $errors[] = 'Enter a valid end date.';
    if (valid_date($data['start_date']) && valid_date($data['end_date']) && $data['end_date'] < $data['start_date']) {
        $errors[] = 'The end date cannot be before the start date.';
    }
    if (!in_array($data['status'], $statuses, true)) $errors[] = 'Choose a valid status.';
    if (!is_numeric($data['allocated_amount']) || (float) $data['allocated_amount'] < 0) $errors[] = 'Enter a valid project allocation amount.';
    $mgr = int_or_null($data['manager_id']);
    if ($data['manager_id'] !== '' && !$mgr) $errors[] = 'Choose a valid responsible officer.';
    if ($mgr && !fetch_value("SELECT 1 FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=? AND (u.is_active=1 OR u.user_id=?) AND r.role_name='Project Manager'", [$mgr, $project['manager_id'] ?? 0])) {
        $errors[] = 'The assigned manager must be an active Project Manager.';
    }
    if (!$errors) {
        if ($project) {
            q('UPDATE projects SET title=?, description=?, location=?, start_date=?, end_date=?, status=?, manager_id=?, allocated_amount=? WHERE project_id=?',
                [$data['title'], $data['description'] ?: null, $data['location'] ?: null, $data['start_date'], $data['end_date'], $data['status'], $mgr, (float)$data['allocated_amount'], $id]);
            audit('Update', 'projects', $id, 'Updated project: ' . $data['title']);
            flash('success', 'The project was updated.');
            redirect('projects/view.php?id=' . $id);
        }
        q('INSERT INTO projects (title, description, location, start_date, end_date, status, manager_id, allocated_amount, client_code, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$data['title'], $data['description'] ?: null, $data['location'] ?: null, $data['start_date'], $data['end_date'], $data['status'], $mgr, (float)$data['allocated_amount'], 'PRJ-' . strtoupper(bin2hex(random_bytes(14))), $user['user_id']]);
        $newId = (int) db()->lastInsertId();
        audit('Create', 'projects', $newId, 'Created project: ' . $data['title']);
        flash('success', 'The project was created.');
        redirect('projects/view.php?id=' . $newId);
    }
}

render_header($project ? 'Edit project' : 'New project', 'projects', $project
    ? [['Projects', 'projects/index.php'], [$project['title'], 'projects/view.php?id=' . $id], ['Edit']]
    : [['Projects', 'projects/index.php'], ['New project']]);
show_errors($errors);
?>
<div class="card" style="max-width:820px"><div class="card-body">
<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="mb-3"><label class="form-label required" for="title">Project title</label>
    <input class="form-control" id="title" name="title" maxlength="150" value="<?= e($data['title']) ?>" required></div>
  <div class="mb-3"><label class="form-label" for="description">Description</label>
    <textarea class="form-control" id="description" name="description" rows="3"><?= e($data['description']) ?></textarea></div>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="location">Location</label>
      <input class="form-control" id="location" name="location" maxlength="150" value="<?= e($data['location']) ?>"></div>
    <div class="col-md-6"><label class="form-label required" for="manager_id">Project manager</label>
      <select class="form-select" id="manager_id" name="manager_id"><?= options($projectManagers, 'user_id', 'full_name', $data['manager_id'], 'Not assigned') ?></select></div>
    <div class="col-md-4"><label class="form-label" for="allocated_amount">Amount allocated to project (₦)</label><input class="form-control" type="number" min="0" step="0.01" id="allocated_amount" name="allocated_amount" value="<?= e((string)$data['allocated_amount']) ?>"></div>
    <div class="col-md-4"><label class="form-label required" for="start_date">Start date</label>
      <input class="form-control" type="date" id="start_date" name="start_date" value="<?= e($data['start_date']) ?>" required></div>
    <div class="col-md-4"><label class="form-label required" for="end_date">End date</label>
      <input class="form-control" type="date" id="end_date" name="end_date" value="<?= e($data['end_date']) ?>" required></div>
    <div class="col-md-4"><label class="form-label required" for="status">Status</label>
      <select class="form-select" id="status" name="status"><?= simple_options($statuses, $data['status']) ?></select></div>
  </div>
  <?php if ($project && !empty($project['client_code'])): ?><div class="alert alert-info mt-3 mb-0"><strong>Client tracking code:</strong> <code><?= e($project['client_code']) ?></code> — share this code with the client to view the project's public progress.</div><?php endif; ?>
  <div class="mt-4"><button class="btn btn-primary" type="submit">Save project</button>
    <a class="btn btn-outline-secondary" href="<?= e(url($project ? 'projects/view.php?id=' . $id : 'projects/index.php')) ?>">Cancel</a></div>
</form></div></div>
<?php render_footer();
