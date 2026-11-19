<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('view_operations');

$id = int_or_null(get('id'));
$ind = $id ? fetch_one('SELECT i.*, p.title AS project_title FROM indicators i JOIN projects p ON p.project_id = i.project_id WHERE i.indicator_id = ?', [$id]) : null;
if (!$ind) { not_found('indicator'); }
$values = fetch_all('SELECT v.*, u.full_name AS recorder FROM indicator_values v LEFT JOIN users u ON u.user_id = v.recorded_by WHERE v.indicator_id = ? ORDER BY v.period_label DESC', [$id]);

render_header('Indicator details', 'indicators', [['Indicators', 'indicators/index.php'], [$ind['name']]]);
?>
<div class="d-flex flex-wrap gap-2 mb-3 no-print">
  <a class="btn btn-outline-secondary" href="index.php">&larr; All indicators</a>
  <?php if (can('record_values')): ?><a class="btn btn-primary" href="value.php?indicator_id=<?= $id ?>">Record value</a><?php endif; ?>
  <?php if (can('define_indicators')): ?>
    <a class="btn btn-outline-primary" href="form.php?id=<?= $id ?>">Edit indicator</a>
    <form method="post" action="delete.php" data-confirm="Delete this indicator?" class="m-0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-outline-danger" type="submit">Delete</button></form>
  <?php endif; ?>
</div>
<div class="card mb-3"><div class="card-body">
  <h2 class="h5"><?= e($ind['name']) ?></h2>
  <dl class="row mb-0">
    <dt class="col-sm-3">Project</dt><dd class="col-sm-9"><a href="<?= e(url('projects/view.php?id=' . $ind['project_id'])) ?>"><?= e($ind['project_title']) ?></a></dd>
    <dt class="col-sm-3">Unit</dt><dd class="col-sm-9"><?= e($ind['unit']) ?></dd>
    <dt class="col-sm-3">Baseline</dt><dd class="col-sm-9"><?= e(fmt_number($ind['baseline'])) ?></dd>
    <dt class="col-sm-3">Target</dt><dd class="col-sm-9"><?= e(fmt_number($ind['target'])) ?></dd>
    <dt class="col-sm-3">Reporting period</dt><dd class="col-sm-9"><?= e($ind['reporting_period']) ?></dd>
  </dl></div></div>
<div class="card"><div class="card-header">Recorded values</div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Period</th><th>Actual</th><th style="min-width:210px">Progress</th><th>Recorded by</th><th>Recorded on</th><th></th></tr></thead><tbody>
  <?php foreach ($values as $v): $mt = indicator_metrics($ind['baseline'], $ind['target'], $v['actual_value']); ?>
    <tr><td><?= e($v['period_label']) ?></td><td><?= e(fmt_with_unit($v['actual_value'], $ind['unit'])) ?></td>
      <td><?= indicator_progress_html($mt) ?></td>
      <td><?= e($v['recorder']) ?></td><td><?= e(fmt_datetime($v['recorded_at'])) ?></td>
      <td class="text-nowrap no-print"><?php if (can('record_values')): ?><a class="btn btn-sm btn-outline-secondary" href="value.php?value_id=<?= (int) $v['value_id'] ?>">Edit</a><?php endif; ?>
        <?php if (can('define_indicators')): ?>
          <form method="post" action="delete_value.php" class="d-inline" data-confirm="Delete this recorded value?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $v['value_id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form>
        <?php endif; ?></td></tr>
  <?php endforeach; if (!$values): ?><tr><td colspan="6" class="text-center text-muted py-3">No values recorded yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php render_footer();
