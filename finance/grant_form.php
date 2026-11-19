<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('manage_funds');
$id = int_or_null(get('id')); $g = $id ? fetch_one('SELECT * FROM grants WHERE grant_id = ?', [$id]) : null; if ($id && !$g) { not_found('grant'); }
$data = $g ?: ['donor_id' => '', 'project_id' => '', 'reference' => '', 'amount' => '', 'start_date' => '', 'end_date' => '', 'status' => 'Active']; $errors = [];
if (is_post()) {
    csrf_verify();
    $data = ['donor_id' => post('donor_id'), 'project_id' => post('project_id'), 'reference' => strtoupper(post('reference')), 'amount' => post('amount'), 'start_date' => post('start_date'), 'end_date' => post('end_date'), 'status' => post('status')];
    $did = int_or_null($data['donor_id']); $pid = int_or_null($data['project_id']);
    if (!$did || !fetch_value('SELECT 1 FROM donors WHERE donor_id = ?', [$did])) $errors[] = 'Choose the donor.';
    if (!$pid || !fetch_value('SELECT 1 FROM projects WHERE project_id = ?', [$pid])) $errors[] = 'Choose the project the grant funds.';
    if ($data['reference'] === '' || mb_strlen($data['reference']) > 50) $errors[] = 'Enter the grant reference (50 characters at most).';
    elseif (fetch_value('SELECT 1 FROM grants WHERE reference = ? AND grant_id <> ?', [$data['reference'], $id ?? 0])) $errors[] = 'That grant reference is already in use.';
    if (!valid_money($data['amount'])) $errors[] = 'The grant amount must be a positive number with at most two decimal places.';
    foreach (['start_date', 'end_date'] as $k) { if ($data[$k] !== '' && !valid_date($data[$k])) $errors[] = 'Enter valid grant dates.'; }
    if ($data['start_date'] !== '' && $data['end_date'] !== '' && $data['end_date'] < $data['start_date']) $errors[] = 'The grant end date cannot be before its start date.';
    if (!in_array($data['status'], ['Pending', 'Active', 'Closed'], true)) $errors[] = 'Choose the status.';
    if (!$errors && $g) { $rec = (float) fetch_value('SELECT COALESCE(SUM(amount), 0) FROM fund_receipts WHERE grant_id = ?', [$id]); if ((float) $data['amount'] < $rec) $errors[] = 'The grant amount cannot be lower than the ' . money($rec) . ' already received.'; }
    if (!$errors) {
        $v = [$did, $pid, $data['reference'], $data['amount'], $data['start_date'] ?: null, $data['end_date'] ?: null, $data['status']];
        if ($g) { q('UPDATE grants SET donor_id=?, project_id=?, reference=?, amount=?, start_date=?, end_date=?, status=? WHERE grant_id=?', [...$v, $id]); audit('Update', 'grants', $id, 'Grant updated: ' . $data['reference']); }
        else { q('INSERT INTO grants (donor_id, project_id, reference, amount, start_date, end_date, status, created_by) VALUES (?,?,?,?,?,?,?,?)', [...$v, $user['user_id']]); audit('Create', 'grants', (int) db()->lastInsertId(), 'Grant created: ' . $data['reference'] . ' ' . money($data['amount'])); }
        flash('success', 'The grant was saved.'); redirect('finance/funds.php');
    }
}
render_header($g ? 'Edit grant' : 'New grant', 'funds', [['Donors and funds', 'finance/funds.php'], [$g ? 'Edit grant' : 'New grant']]); show_errors($errors);
?>
<div class="card" style="max-width:760px"><div class="card-body"><form method="post" novalidate><?= csrf_field() ?><div class="row g-3">
  <div class="col-md-6"><label class="form-label required" for="donor_id">Donor</label><select class="form-select" id="donor_id" name="donor_id"><?= options(fetch_all('SELECT donor_id, name FROM donors ORDER BY name'), 'donor_id', 'name', $data['donor_id'], 'Select a donor') ?></select></div>
  <div class="col-md-6"><label class="form-label required" for="project_id">Project funded</label><select class="form-select" id="project_id" name="project_id"><?= options(projects_list(), 'project_id', 'title', $data['project_id'], 'Select a project') ?></select></div>
  <div class="col-md-4"><label class="form-label required" for="reference">Grant reference</label><input class="form-control" id="reference" name="reference" maxlength="50" value="<?= e($data['reference']) ?>" required></div>
  <div class="col-md-4"><label class="form-label required" for="amount">Grant amount (<?= e(cfg('currency')) ?>)</label><input class="form-control" id="amount" name="amount" inputmode="decimal" value="<?= e($data['amount']) ?>" required></div>
  <div class="col-md-4"><label class="form-label required" for="status">Status</label><select class="form-select" id="status" name="status"><?= simple_options(['Pending', 'Active', 'Closed'], $data['status']) ?></select></div>
  <div class="col-md-6"><label class="form-label" for="start_date">Start date</label><input class="form-control" type="date" id="start_date" name="start_date" value="<?= e($data['start_date']) ?>"></div>
  <div class="col-md-6"><label class="form-label" for="end_date">End date</label><input class="form-control" type="date" id="end_date" name="end_date" value="<?= e($data['end_date']) ?>"></div>
</div><div class="mt-4"><button class="btn btn-primary" type="submit">Save grant</button> <a class="btn btn-outline-secondary" href="funds.php">Cancel</a></div></form></div></div>
<?php render_footer();
