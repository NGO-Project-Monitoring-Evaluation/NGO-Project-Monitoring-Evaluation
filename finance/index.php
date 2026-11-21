<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('view_finance');
$projectId = int_or_null(get('project'));
if ($projectId && !can_view_project($projectId)) { deny_access(); }
$fp = finance_projects($projectId); $ft = finance_totals($fp);
$detail = can('view_finance_detail') || can('view_budget_lines');
$lines = $detail ? finance_lines($projectId) : [];
[$lsc, $lsp] = project_scope('l.project_id');
$cw = $projectId ? ' AND l.project_id = ' . (int) $projectId : '';
$byCat = is_manager() ? [] : fetch_all("SELECT l.category, SUM(e.amount) AS total FROM expenditures e JOIN budget_lines l ON l.line_id = e.line_id WHERE e.status = 'Approved' $cw $lsc GROUP BY l.category ORDER BY total DESC", $lsp);
render_header('Finance overview', 'finance');
?>
<form class="row g-2 mb-3 no-print" method="get"><div class="col-md-5"><select class="form-select" name="project"><?= options(projects_list(), 'project_id', 'title', $projectId, 'All projects') ?></select></div>
  <div class="col-auto"><button class="btn btn-primary">Apply</button> <a class="btn btn-outline-secondary" href="index.php">Reset</a></div>
  <?php if (can('view_financial_reports')): ?><div class="col text-md-end"><a class="btn btn-outline-primary" href="<?= e(url('reports/index.php?generate=1&type=bva' . ($projectId ? '&project=' . $projectId : ''))) ?>">Budget versus actual report</a>
    <a class="btn btn-outline-primary" href="<?= e(url('reports/index.php?generate=1&type=variance' . ($projectId ? '&project=' . $projectId : ''))) ?>">Cost variance analysis</a></div><?php endif; ?></form>
<div class="row g-3 mb-3">
<?php $kpis = is_manager() ? [['Approved project budget', money($ft['budget']), ''], ['Approved expenditure', money($ft['spent']), 'Project-level summary only'], ['Budget utilisation', $ft['budget_spent_pct'] === null ? '-' : fmt_number($ft['budget_spent_pct']) . '%', 'Expenditure against approved budget']] : [['Approved budget', money($ft['budget']), ''], ['Funds received', money($ft['received']), 'Awarded: ' . money($ft['granted'])], ['Expenditure (approved)', money($ft['spent']), ($ft['budget_spent_pct'] === null ? '' : fmt_number($ft['budget_spent_pct']) . '% of budget')], ['Committed (approved purchase orders)', money($ft['committed']), 'Reduces available funds'], ['Balance (received - spent)', money($ft['balance']), 'After commitments: ' . money($ft['available'])], ['Budget utilisation', $ft['utilisation'] === null ? '-' : fmt_number($ft['utilisation']) . '%', 'Expenditure / funds received']]; foreach ($kpis as [$l, $v, $s]): ?>
  <div class="col-6 col-lg-4 col-xl-2"><div class="card kpi h-100"><div class="card-body"><div class="text-muted small"><?= e($l) ?></div><div class="value" style="font-size:1.05rem"><?= e($v) ?></div><div class="sub"><?= e($s) ?></div></div></div></div>
<?php endforeach; ?></div>
<?php if (!is_manager()): ?>
<div class="row g-3 mb-3">
  <div class="col-lg-5"><div class="card h-100"><div class="card-header">Expenditure by category</div><div class="card-body chart-box"><?= chart_donut(array_map(fn($r) => [$r['category'], (float) $r['total']], $byCat), money_short($ft['spent'])) ?></div></div></div>
  <div class="col-lg-7"><div class="card h-100"><div class="card-header">Funds received and expenditure</div><div class="card-body chart-box"><?= chart_pair_bars(array_map(fn($p) => [$p['title'], $p['received'], $p['spent']], $fp), 'Funds received', 'Expenditure') ?></div></div></div>
</div>
<?php endif; ?>
<div class="card mb-3"><div class="card-header">Programme and finance together</div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Project</th><th class="money">Approved budget</th><?php if (!is_manager()): ?><th class="money">Received</th><?php endif; ?><th class="money">Spent</th><?php if (!is_manager()): ?><th class="money">Committed</th><th class="money">Balance</th><?php endif; ?><th style="min-width:130px">Activity progress</th><th style="min-width:130px">Budget spent</th><th>Reading</th></tr></thead><tbody>
  <?php foreach ($fp as $p): ?><tr><td><a href="?project=<?= (int) $p['project_id'] ?>"><?= e($p['title']) ?></a></td><td class="money"><?= e(money_short($p['budget'])) ?></td><?php if (!is_manager()): ?><td class="money"><?= e(money_short($p['received'])) ?></td><?php endif; ?><td class="money"><?= e(money_short($p['spent'])) ?></td><?php if (!is_manager()): ?><td class="money"><?= e(money_short($p['committed'])) ?></td><td class="money"><?= e(money_short($p['balance'])) ?></td><?php endif; ?>
    <td><?= progress_bar($p['progress'], progress_class($p['progress'])) ?></td><td><?= $p['budget_spent_pct'] === null ? '&ndash;' : progress_bar(min(100, $p['budget_spent_pct']), $p['budget_spent_pct'] > 100 ? 'danger' : 'primary') ?></td>
    <td><span class="badge text-bg-<?= $p['alignment'] === 'Aligned' ? 'success' : ($p['alignment'] === 'No budget' ? 'secondary' : 'warning') ?>"><?= e($p['alignment']) ?></span></td></tr><?php endforeach; ?>
  <?php if (!$fp): ?><tr><td colspan="<?= is_manager() ? 6 : 9 ?>" class="text-center text-muted py-3">No projects.</td></tr><?php endif; ?></tbody></table></div></div>
<?php if ($detail): ?>
<div class="card"><div class="card-header d-flex justify-content-between"><span>Budget lines: budget versus actual</span><a class="small" href="budget.php<?= $projectId ? '?project=' . $projectId : '' ?>"><?= can('manage_budget') ? 'Manage budget lines' : 'View budget lines' ?></a></div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Project</th><th>Category</th><th>Line item</th><th class="money">Planned</th><th class="money">Actual</th><?php if (!is_manager()): ?><th class="money">Committed</th><?php endif; ?><th class="money">Available</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($lines as $l): ?><tr class="<?= in_array($l['status'], ['Overspent', 'Over-committed'], true) ? 'table-flagged' : '' ?>"><td><?= e($l['project_title']) ?></td><td><?= e($l['category']) ?></td><td><?= e($l['description']) ?></td><td class="money"><?= e(money($l['planned_amount'])) ?></td><td class="money"><?= e(money($l['spent'])) ?></td><?php if (!is_manager()): ?><td class="money"><?= e(money($l['committed'])) ?></td><?php endif; ?><td class="money"><?= e(money($l['available'])) ?></td><td><?= finance_status_badge($l['status']) ?></td></tr><?php endforeach; ?>
  <?php if (!$lines): ?><tr><td colspan="<?= is_manager() ? 7 : 8 ?>" class="text-center text-muted py-3">No budget lines yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>
<?php render_footer();
