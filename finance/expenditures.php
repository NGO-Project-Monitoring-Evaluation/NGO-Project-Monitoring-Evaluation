<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('view_finance_detail');
$projectId = int_or_null(get('project')); $status = in_array(get('status'), ['Pending approval', 'Approved', 'Rejected'], true) ? get('status') : '';
$where = ['1=1']; $params = [];
if ($projectId) { $where[] = 'l.project_id = ?'; $params[] = $projectId; }
if ($status !== '') { $where[] = 'e.status = ?'; $params[] = $status; }
$w = implode(' AND ', $where);
$total = (int) fetch_value("SELECT COUNT(*) FROM expenditures e JOIN budget_lines l ON l.line_id = e.line_id WHERE $w", $params);
$pg = paginate($total, 25);
$rows = fetch_all("SELECT e.*, l.category, l.description AS line_desc, l.project_id, p.title AS project_title, s.full_name AS submitter, a.full_name AS approver
                     FROM expenditures e JOIN budget_lines l ON l.line_id = e.line_id JOIN projects p ON p.project_id = l.project_id
                     LEFT JOIN users s ON s.user_id = e.submitted_by LEFT JOIN users a ON a.user_id = e.approved_by
                    WHERE $w ORDER BY e.spent_on DESC, e.expenditure_id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
$sum = (float) fetch_value("SELECT COALESCE(SUM(e.amount), 0) FROM expenditures e JOIN budget_lines l ON l.line_id = e.line_id WHERE $w", $params);
render_header('Expenditures', 'expenditures');
?>
<form class="row g-2 mb-3 no-print" method="get"><div class="col-md-4"><select class="form-select" name="project"><?= options(projects_list(), 'project_id', 'title', $projectId, 'All projects') ?></select></div>
  <div class="col-md-3"><select class="form-select" name="status"><?= simple_options(['Pending approval', 'Approved', 'Rejected'], $status, 'All statuses') ?></select></div>
  <div class="col-auto"><button class="btn btn-primary">Filter</button> <a class="btn btn-outline-secondary" href="expenditures.php">Reset</a></div>
  <?php if (can('record_expenditure')): ?><div class="col text-md-end"><a class="btn btn-primary" href="expenditure_form.php">+ Record expenditure</a></div><?php endif; ?></form>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>#</th><th>Date</th><th>Project / budget line</th><th>Payee</th><th>Reference</th><th class="money">Amount</th><th>Status</th><th>Submitted by</th><th>Decision</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $mine = (int) $r['submitted_by'] === (int) $user['user_id']; ?>
  <tr><td><?= (int) $r['expenditure_id'] ?></td><td class="text-nowrap"><?= e(fmt_date($r['spent_on'])) ?></td><td><?= e($r['project_title']) ?><div class="small text-muted"><?= e($r['category'] . ' / ' . $r['line_desc']) ?></div></td><td><?= e($r['payee']) ?><?= $r['description'] ? '<div class="small text-muted">' . e($r['description']) . '</div>' : '' ?></td><td><?= e($r['reference']) ?></td>
    <td class="money"><?= e(money($r['amount'])) ?></td><td><?= finance_status_badge($r['status']) ?></td><td class="small"><?= e($r['submitter']) ?></td>
    <td class="small"><?= $r['approver'] ? e($r['approver']) . '<br>' . e(fmt_date($r['approved_at'])) : '' ?><?= $r['decision_note'] ? '<div class="text-muted">' . e($r['decision_note']) . '</div>' : '' ?></td>
    <td class="no-print" style="min-width:210px"><?php if ($r['status'] === 'Pending approval' && can('approve_finance')): ?>
      <?php if ($mine): ?><span class="small text-muted">You submitted this; another approver must decide.</span><?php else: ?>
      <form method="post" action="approve.php" class="d-inline"><?= csrf_field() ?><input type="hidden" name="type" value="expenditure"><input type="hidden" name="id" value="<?= (int) $r['expenditure_id'] ?>"><input type="hidden" name="decision" value="approve"><button class="btn btn-sm btn-success" type="submit">Approve</button></form>
      <form method="post" action="approve.php" class="d-inline-flex gap-1"><?= csrf_field() ?><input type="hidden" name="type" value="expenditure"><input type="hidden" name="id" value="<?= (int) $r['expenditure_id'] ?>"><input type="hidden" name="decision" value="reject"><input class="form-control form-control-sm" name="note" placeholder="Reason" style="width:7rem" required><button class="btn btn-sm btn-outline-danger" type="submit">Reject</button></form><?php endif; ?><?php endif; ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">No expenditures found.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="mt-3 d-flex justify-content-between align-items-center"><span class="small text-muted"><?= $total ?> record(s), total <?= e(money($sum)) ?>. Only approved expenditures count as actual spending.</span><?= pagination_html($pg) ?></div>
<?php render_footer();
