<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('view_finance_detail');
$projectId = int_or_null(get('project'));
$alerts = finance_alerts($projectId);
render_header('Fraud and unusual-expenditure alerts', 'alerts');
?>
<form class="row g-2 mb-3 no-print" method="get"><div class="col-md-5"><select class="form-select" name="project"><?= options(projects_list(), 'project_id', 'title', $projectId, 'All projects') ?></select></div><div class="col-auto"><button class="btn btn-primary">Filter</button> <a class="btn btn-outline-secondary" href="alerts.php">Reset</a></div></form>
<div class="card"><ul class="list-group list-group-flush">
<?php foreach ($alerts as $al): ?><li class="list-group-item"><span class="badge text-bg-<?= e($al['level']) ?> me-2"><?= e($al['type']) ?></span><?= e($al['message']) ?> <a class="small" href="<?= e(url($al['link'])) ?>">Open</a></li><?php endforeach; ?>
<?php if (!$alerts): ?><li class="list-group-item text-muted">No alerts. Nothing unusual was found in the recorded finances.</li><?php endif; ?></ul></div>
<p class="small text-muted mt-2">Alerts are worked out live from the records: overspent or over-committed budget lines, spending ahead of the forecast, possible duplicate payments, unusually large or round-sum payments, payments outside the project dates, and approvals waiting more than 7 days. An alert is a prompt to check the supporting documents, not proof of wrongdoing.</p>
<?php render_footer();
