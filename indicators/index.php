<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('view_operations');

$projectId = int_or_null(get('project'));
$period = get('period');
$periods = array_column(fetch_all('SELECT DISTINCT period_label FROM indicator_values ORDER BY period_label DESC'), 'period_label');
if ($period !== '' && !in_array($period, $periods, true)) { $period = ''; }
$rows = indicator_progress_rows($projectId, $period ?: null);

render_header('Indicators and progress', 'indicators');
?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-4"><select class="form-select" name="project"><?= options(projects_list(), 'project_id', 'title', $projectId, 'All projects') ?></select></div>
  <div class="col-md-3"><select class="form-select" name="period"><?= simple_options($periods, $period, 'Latest period') ?></select></div>
  <div class="col-auto"><button class="btn btn-primary">Apply</button> <a class="btn btn-outline-secondary" href="index.php">Reset</a></div>
  <?php if (can('define_indicators')): ?><div class="col text-md-end"><a class="btn btn-primary" href="form.php<?= $projectId ? '?project_id=' . $projectId : '' ?>">+ New indicator</a></div><?php endif; ?>
</form>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
  <thead><tr><th>Indicator</th><th>Project</th><th>Unit</th><th>Baseline</th><th>Target</th><th>Actual</th><th>Period</th><th style="min-width:210px">Progress</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $i): ?>
    <tr><td><a href="view.php?id=<?= (int) $i['indicator_id'] ?>"><strong><?= e($i['name']) ?></strong></a><div class="small text-muted"><?= e($i['reporting_period']) ?></div></td>
      <td><?= e($i['project_title']) ?></td><td><?= e($i['unit']) ?></td><td><?= e(fmt_number($i['baseline'])) ?></td><td><?= e(fmt_number($i['target'])) ?></td>
      <td><?= $i['actual_value'] === null ? '&ndash;' : e(fmt_with_unit($i['actual_value'], $i['unit'])) ?></td><td><?= e($i['value_period']) ?></td>
      <td><?= indicator_progress_html($i['metrics']) ?></td>
      <td class="text-nowrap no-print"><?php if (can('record_values')): ?><a class="btn btn-sm btn-outline-primary" href="value.php?indicator_id=<?= (int) $i['indicator_id'] ?>">Record value</a><?php endif; ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No indicators found.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<p class="small text-muted mt-2">The percentage is the actual value as a share of the target (62 of 80 is 77.5%). Where an indicator has a baseline, the progress from the baseline is shown too: (actual &minus; baseline) &divide; (target &minus; baseline) &times; 100. The bar stops at 100%, and a value above the target is labelled <strong>Target exceeded</strong>.</p>
<?php render_footer();
