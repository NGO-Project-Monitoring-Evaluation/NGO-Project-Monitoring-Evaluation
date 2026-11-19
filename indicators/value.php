<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('record_values');

$valueId = int_or_null(get('value_id'));
$value = $valueId ? fetch_one('SELECT * FROM indicator_values WHERE value_id = ?', [$valueId]) : null;
if ($valueId && !$value) { not_found('recorded value'); }
$indicatorId = $value ? (int) $value['indicator_id'] : int_or_null(get('indicator_id'));
$ind = $indicatorId ? fetch_one('SELECT i.*, p.title AS project_title FROM indicators i JOIN projects p ON p.project_id = i.project_id WHERE i.indicator_id = ?', [$indicatorId]) : null;
if (!$ind) { not_found('indicator'); }

$data = $value ?: ['period_label' => '', 'actual_value' => ''];
$errors = [];
$existingId = null;

if (is_post()) {
    csrf_verify();
    $data = ['period_label' => $value ? $value['period_label'] : strtoupper(post('period_label')), 'actual_value' => post('actual_value')];
    if (!$value && !valid_period($data['period_label'], $ind['reporting_period'])) {
        $errors[] = 'Enter the reporting period in the format ' . period_hint($ind['reporting_period']) . '.';
    }
    if (!valid_decimal($data['actual_value'])) $errors[] = 'The actual value must be a number with at most two decimal places.';
    if (!$errors && !$value) {
        $existingId = fetch_value('SELECT value_id FROM indicator_values WHERE indicator_id = ? AND period_label = ?', [$indicatorId, $data['period_label']]);
        if ($existingId) { $errors[] = 'A value has already been recorded for ' . $data['period_label'] . '. Only one value is allowed for each indicator and period; edit the existing value instead.'; }
    }
    if (!$errors) {
        if ($value) {
            q('UPDATE indicator_values SET actual_value=?, recorded_by=?, recorded_at=NOW() WHERE value_id=?', [$data['actual_value'], $user['user_id'], $valueId]);
            audit('Update', 'indicator_values', $valueId, "Changed {$ind['name']} for {$value['period_label']} to {$data['actual_value']}");
            flash('success', 'The value was updated.');
        } else {
            try {
                q('INSERT INTO indicator_values (indicator_id, period_label, actual_value, recorded_by) VALUES (?,?,?,?)', [$indicatorId, $data['period_label'], $data['actual_value'], $user['user_id']]);
            } catch (PDOException $ex) {
                if ($ex->getCode() === '23000') { flash('danger', 'A value for that period already exists.'); redirect('indicators/value.php?indicator_id=' . $indicatorId); }
                throw $ex;
            }
            $newId = (int) db()->lastInsertId();
            audit('Create', 'indicator_values', $newId, "Recorded {$ind['name']} for {$data['period_label']}: {$data['actual_value']}");
            $mt = indicator_metrics($ind['baseline'], $ind['target'], $data['actual_value']);
            flash('success', 'The value was recorded. ' . ($mt['headline'] === null ? 'Progress cannot be calculated because the target equals the baseline.' : fmt_number($mt['headline']) . '% ' . $mt['basis'] . ($mt['status'] === 'Target exceeded' ? ' (target exceeded).' : '.')));
        }
        redirect('indicators/view.php?id=' . $indicatorId);
    }
}

render_header($value ? 'Edit recorded value' : 'Record indicator value', 'indicators', [['Indicators', 'indicators/index.php'], [$ind['name'], 'indicators/view.php?id=' . $indicatorId], [$value ? 'Edit value' : 'Record value']]);
show_errors($errors);
?>
<div class="card mb-3" style="max-width:820px"><div class="card-body small">
  <strong><?= e($ind['name']) ?></strong> &middot; <?= e($ind['project_title']) ?><br>
  Unit: <?= e($ind['unit']) ?> &middot; Baseline: <?= e(fmt_number($ind['baseline'])) ?> &middot; Target: <?= e(fmt_number($ind['target'])) ?> &middot; Reported <?= e(strtolower($ind['reporting_period'])) ?>
</div></div>
<div class="card" style="max-width:820px"><div class="card-body">
<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label required" for="period_label">Reporting period</label>
      <input class="form-control" id="period_label" name="period_label" data-frequency="<?= e($ind['reporting_period']) ?>" maxlength="20" value="<?= e($data['period_label']) ?>" <?= $value ? 'readonly' : 'required' ?>>
      <div class="form-text" id="period_hint">Format: <?= e(period_hint($ind['reporting_period'])) ?></div></div>
    <div class="col-md-6"><label class="form-label required" for="actual_value">Actual value (<?= e($ind['unit']) ?>)</label>
      <input class="form-control" id="actual_value" name="actual_value" inputmode="decimal" value="<?= e($data['actual_value']) ?>" required></div>
  </div>
  <div class="mt-4"><button class="btn btn-primary" type="submit">Save value</button>
    <a class="btn btn-outline-secondary" href="view.php?id=<?= (int) $indicatorId ?>">Cancel</a>
    <?php if ($existingId): ?> <a class="btn btn-outline-primary" href="value.php?value_id=<?= (int) $existingId ?>">Edit the existing value</a><?php endif; ?></div>
</form></div></div>
<?php render_footer();
