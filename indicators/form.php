<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('define_indicators');

$freqs = ['Monthly', 'Quarterly', 'Annual'];
$id = int_or_null(get('id'));
$ind = $id ? fetch_one('SELECT * FROM indicators WHERE indicator_id = ?', [$id]) : null;
if ($id && !$ind) { not_found('indicator'); }
if ($ind && !can_view_project((int)$ind['project_id'])) { deny_access(); }
$data = $ind ?: ['project_id' => int_or_null(get('project_id')), 'name' => '', 'unit' => '', 'baseline' => '0', 'target' => '', 'reporting_period' => 'Quarterly'];
$errors = [];

if (is_post()) {
    csrf_verify();
    $data = ['project_id' => post('project_id'), 'name' => post('name'), 'unit' => post('unit'), 'baseline' => post('baseline', '0'), 'target' => post('target'), 'reporting_period' => post('reporting_period')];
    $pid = int_or_null($data['project_id']);
    if (!$pid || !fetch_value('SELECT 1 FROM projects WHERE project_id = ?', [$pid]) || !can_view_project($pid)) $errors[] = 'Choose the project this indicator belongs to.';
    if ($data['name'] === '' || mb_strlen($data['name']) > 150) $errors[] = 'Enter the indicator name (150 characters at most).';
    if ($data['unit'] === '' || mb_strlen($data['unit']) > 30)  $errors[] = 'Enter the unit of measurement (30 characters at most), for example "boreholes" or "%".';
    if ($data['baseline'] === '') $data['baseline'] = '0';
    if (!valid_decimal($data['baseline'])) $errors[] = 'The baseline must be a number with at most two decimal places.';
    if (!valid_decimal($data['target']))   $errors[] = 'The target must be a number with at most two decimal places.';
    if (!in_array($data['reporting_period'], $freqs, true)) $errors[] = 'Choose a valid reporting period.';
    if (!$errors && $ind && $ind['reporting_period'] !== $data['reporting_period'] && fetch_value('SELECT COUNT(*) FROM indicator_values WHERE indicator_id = ?', [$id])) {
        $errors[] = 'The reporting period cannot be changed after values have been recorded, because the recorded period labels would no longer match.';
    }
    if (!$errors) {
        if ($ind) {
            q('UPDATE indicators SET project_id=?, name=?, unit=?, baseline=?, target=?, reporting_period=? WHERE indicator_id=?',
                [$pid, $data['name'], $data['unit'], $data['baseline'], $data['target'], $data['reporting_period'], $id]);
            audit('Update', 'indicators', $id, 'Updated indicator: ' . $data['name']);
            flash('success', 'The indicator was updated.');
        } else {
            q('INSERT INTO indicators (project_id, name, unit, baseline, target, reporting_period) VALUES (?,?,?,?,?,?)',
                [$pid, $data['name'], $data['unit'], $data['baseline'], $data['target'], $data['reporting_period']]);
            $id = (int) db()->lastInsertId();
            audit('Create', 'indicators', $id, 'Created indicator: ' . $data['name']);
            flash('success', 'The indicator was created.');
        }
        redirect('indicators/view.php?id=' . $id);
    }
}

render_header($ind ? 'Edit indicator' : 'New indicator', 'indicators', $ind
    ? [['Indicators', 'indicators/index.php'], [$ind['name'], 'indicators/view.php?id=' . $id], ['Edit']]
    : [['Indicators', 'indicators/index.php'], ['New indicator']]);
show_errors($errors);
?>
<div class="card" style="max-width:820px"><div class="card-body">
<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label required" for="project_id">Project</label>
      <select class="form-select" id="project_id" name="project_id"><?= options(projects_list(), 'project_id', 'title', $data['project_id'], 'Select a project') ?></select></div>
    <div class="col-md-6"><label class="form-label required" for="reporting_period">Reporting period</label>
      <select class="form-select" id="reporting_period" name="reporting_period"><?= simple_options($freqs, $data['reporting_period']) ?></select></div>
    <div class="col-md-8"><label class="form-label required" for="name">Indicator name</label>
      <input class="form-control" id="name" name="name" maxlength="150" value="<?= e($data['name']) ?>" required></div>
    <div class="col-md-4"><label class="form-label required" for="unit">Unit</label>
      <input class="form-control" id="unit" name="unit" maxlength="30" value="<?= e($data['unit']) ?>" placeholder="e.g. boreholes, %" required></div>
    <div class="col-md-6"><label class="form-label" for="baseline">Baseline (starting value)</label>
      <input class="form-control" id="baseline" name="baseline" inputmode="decimal" value="<?= e($data['baseline']) ?>"></div>
    <div class="col-md-6"><label class="form-label required" for="target">Target</label>
      <input class="form-control" id="target" name="target" inputmode="decimal" value="<?= e($data['target']) ?>" required></div>
  </div>
  <p class="form-text mt-2">Progress is worked out as (actual &minus; baseline) &divide; (target &minus; baseline) &times; 100. If the target equals the baseline, progress cannot be calculated.</p>
  <button class="btn btn-primary" type="submit">Save indicator</button> <a class="btn btn-outline-secondary" href="<?= e($ind ? 'view.php?id=' . $id : 'index.php') ?>">Cancel</a>
</form></div></div>
<?php render_footer();
