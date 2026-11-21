<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('view_budget_lines');
$projectId = int_or_null(get('project')); $category = in_array(get('category'), EXPENSE_CATEGORIES, true) ? get('category') : '';
$lines = finance_lines($projectId, $category ?: null);
render_header('Budget lines', 'budget');
?>
<form class="row g-2 mb-3 no-print" method="get"><div class="col-md-4"><select class="form-select" name="project"><?= options(projects_list(), 'project_id', 'title', $projectId, 'All projects') ?></select></div>
  <div class="col-md-3"><select class="form-select" name="category"><?= simple_options(EXPENSE_CATEGORIES, $category, 'All categories') ?></select></div>
  <div class="col-auto"><button class="btn btn-primary">Filter</button> <a class="btn btn-outline-secondary" href="budget.php">Reset</a></div>
  <?php if (can('manage_budget')): ?><div class="col text-md-end"><a class="btn btn-primary" href="line_form.php<?= $projectId ? '?project_id=' . $projectId : '' ?>">+ New budget line</a></div><?php endif; ?></form>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
  <thead><tr><th>Project</th><th>Category</th><th>Line item</th><th>Linked activity</th><th class="money">Planned</th><th class="money">Actual</th><?php if (!is_manager()): ?><th class="money">Committed</th><?php endif; ?><th class="money">Available</th><th style="min-width:110px">Spent</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($lines as $l): ?><tr class="<?= in_array($l['status'], ['Overspent', 'Over-committed'], true) ? 'table-flagged' : '' ?>"><td><?= e($l['project_title']) ?></td><td><?= e($l['category']) ?></td><td><strong><?= e($l['description']) ?></strong></td><td class="small"><?= e($l['activity_title']) ?></td>
    <td class="money"><?= e(money($l['planned_amount'])) ?></td><td class="money"><?= e(money($l['spent'])) ?></td><?php if (!is_manager()): ?><td class="money"><?= e(money($l['committed'])) ?></td><?php endif; ?><td class="money"><?= e(money($l['available'])) ?></td>
    <td><?= $l['pct_spent'] === null ? '' : progress_bar(min(100, $l['pct_spent']), $l['pct_spent'] > 100 ? 'danger' : 'primary') ?></td><td><?= finance_status_badge($l['status']) ?></td>
    <td class="text-nowrap no-print"><?php if (can('manage_budget')): ?><a class="btn btn-sm btn-outline-secondary" href="line_form.php?id=<?= (int) $l['line_id'] ?>">Edit</a>
      <form method="post" action="line_delete.php" class="d-inline" data-confirm="Delete this budget line?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $l['line_id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$lines): ?><tr><td colspan="<?= is_manager() ? 10 : 11 ?>" class="text-center text-muted py-4">No budget lines found.</td></tr><?php endif; ?></tbody></table></div></div>
<p class="small text-muted mt-2"><?php if (is_manager()): ?>Project Managers can view budget allocations and approved expenditure totals for their assigned projects. Transaction records, donor receipts, funding details and budget editing remain restricted. Available = planned - approved actual expenditure.<?php else: ?>Actual = approved expenditures. Committed = approved purchase orders not yet paid. Available = planned - actual - committed.<?php endif; ?> Status compares spending with the forecast to date (planned amount spread over the activity or project dates) using a tolerance of <?= e(fmt_number(cfg('variance_threshold', 10))) ?>%.</p>
<?php render_footer();
