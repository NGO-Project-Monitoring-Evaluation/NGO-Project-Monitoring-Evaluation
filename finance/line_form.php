<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('manage_budget');
$id = int_or_null(get('id')); $line = $id ? fetch_one('SELECT * FROM budget_lines WHERE line_id = ?', [$id]) : null;
if ($id && !$line) { not_found('budget line'); }
$data = $line ?: ['project_id' => int_or_null(get('project_id')), 'category' => 'Personnel', 'description' => '', 'activity_id' => '', 'planned_amount' => ''];
$errors = [];
if (is_post()) {
    csrf_verify();
    $data = ['project_id' => post('project_id'), 'category' => post('category'), 'description' => post('description'), 'activity_id' => post('activity_id'), 'planned_amount' => post('planned_amount')];
    $pid = int_or_null($data['project_id']); $act = int_or_null($data['activity_id']);
    if (!$pid || !fetch_value('SELECT 1 FROM projects WHERE project_id = ?', [$pid])) $errors[] = 'Choose the project.';
    if (!in_array($data['category'], EXPENSE_CATEGORIES, true)) $errors[] = 'Choose a category.';
    if ($data['description'] === '' || mb_strlen($data['description']) > 150) $errors[] = 'Enter the line item (150 characters at most).';
    if (!valid_money($data['planned_amount'])) $errors[] = 'The planned amount must be a positive number with at most two decimal places.';
    if ($act && !fetch_value('SELECT 1 FROM activities WHERE activity_id = ? AND project_id = ?', [$act, $pid ?? 0])) $errors[] = 'The linked activity must belong to the chosen project.';
    if (!$errors && $line) {
        $used = (float) fetch_value("SELECT COALESCE((SELECT SUM(amount) FROM expenditures WHERE line_id = ? AND status = 'Approved'), 0) + COALESCE((SELECT SUM(amount) FROM commitments WHERE line_id = ? AND status = 'Approved'), 0)", [$id, $id]);
        if ((float) $data['planned_amount'] < $used) $errors[] = 'The planned amount cannot be lower than the ' . money($used) . ' already spent or committed on this line.';
    }
    if (!$errors) {
        if ($line) {
            q('UPDATE budget_lines SET project_id=?, category=?, description=?, activity_id=?, planned_amount=? WHERE line_id=?', [$pid, $data['category'], $data['description'], $act, $data['planned_amount'], $id]);
            audit('Update', 'budget_lines', $id, 'Budget line updated: ' . $data['description'] . ' to ' . money($data['planned_amount'])); flash('success', 'The budget line was updated.');
        } else {
            q('INSERT INTO budget_lines (project_id, category, description, activity_id, planned_amount, created_by) VALUES (?,?,?,?,?,?)', [$pid, $data['category'], $data['description'], $act, $data['planned_amount'], $user['user_id']]);
            audit('Create', 'budget_lines', (int) db()->lastInsertId(), 'Budget line created: ' . $data['description'] . ' ' . money($data['planned_amount'])); flash('success', 'The budget line was created.');
        }
        redirect('finance/budget.php?project=' . $pid);
    }
}
$acts = fetch_all('SELECT a.activity_id, CONCAT(p.title, " / ", a.title) AS label FROM activities a JOIN projects p ON p.project_id = a.project_id ORDER BY p.title, a.title');
render_header($line ? 'Edit budget line' : 'New budget line', 'budget', [['Budget lines', 'finance/budget.php'], [$line ? 'Edit budget line' : 'New budget line']]);
show_errors($errors);
?>
<div class="card" style="max-width:760px"><div class="card-body"><form method="post" novalidate><?= csrf_field() ?><div class="row g-3">
  <div class="col-md-6"><label class="form-label required" for="project_id">Project</label><select class="form-select" id="project_id" name="project_id"><?= options(projects_list(), 'project_id', 'title', $data['project_id'], 'Select a project') ?></select></div>
  <div class="col-md-6"><label class="form-label required" for="category">Category</label><select class="form-select" id="category" name="category"><?= simple_options(EXPENSE_CATEGORIES, $data['category']) ?></select></div>
  <div class="col-12"><label class="form-label required" for="description">Line item</label><input class="form-control" id="description" name="description" maxlength="150" value="<?= e($data['description']) ?>" placeholder="e.g. Field officer salaries, Vehicle hire, Drilling materials" required></div>
  <div class="col-md-8"><label class="form-label" for="activity_id">Linked activity (connects the budget to field work)</label><select class="form-select" id="activity_id" name="activity_id"><?= options($acts, 'activity_id', 'label', $data['activity_id'], 'Not linked') ?></select></div>
  <div class="col-md-4"><label class="form-label required" for="planned_amount">Planned amount (<?= e(cfg('currency')) ?>)</label><input class="form-control" id="planned_amount" name="planned_amount" inputmode="decimal" value="<?= e($data['planned_amount']) ?>" required></div>
</div><div class="mt-4"><button class="btn btn-primary" type="submit">Save budget line</button> <a class="btn btn-outline-secondary" href="budget.php">Cancel</a></div></form></div></div>
<?php render_footer();
