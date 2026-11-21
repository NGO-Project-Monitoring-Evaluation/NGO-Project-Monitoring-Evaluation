<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('view_finance_detail');
$projectId = int_or_null(get('project')); $status = in_array(get('status'), ['Pending approval', 'Approved', 'Paid', 'Cancelled', 'Rejected'], true) ? get('status') : '';
$where = ['1=1']; $params = [];
[$scopeSql, $scopeParams] = project_scope('l.project_id');
if ($scopeSql !== '') { $where[] = '1=1' . $scopeSql; $params = array_merge($params, $scopeParams); }
if (is_donor()) { $where[] = "c.status IN ('Approved','Paid')"; }
if ($projectId) { $where[] = 'l.project_id = ?'; $params[] = $projectId; }
if ($status !== '') { $where[] = 'c.status = ?'; $params[] = $status; }
$w = implode(' AND ', $where);
$rows = fetch_all("SELECT c.*, l.category, l.description AS line_desc, p.title AS project_title, s.full_name AS submitter, a.full_name AS approver
                     FROM commitments c JOIN budget_lines l ON l.line_id = c.line_id JOIN projects p ON p.project_id = l.project_id
                     LEFT JOIN users s ON s.user_id = c.submitted_by LEFT JOIN users a ON a.user_id = c.approved_by WHERE $w ORDER BY c.created_at DESC, c.commitment_id DESC", $params);
$encWhere = ["c.status = 'Approved'"]; $encParams = []; if ($projectId) { $encWhere[] = 'l.project_id = ?'; $encParams[] = $projectId; } if ($scopeSql !== '') { $encWhere[] = '1=1' . $scopeSql; $encParams = array_merge($encParams, $scopeParams); } $enc = (float) fetch_value('SELECT COALESCE(SUM(c.amount), 0) FROM commitments c JOIN budget_lines l ON l.line_id = c.line_id WHERE ' . implode(' AND ', $encWhere), $encParams);
render_header('Commitments (purchase orders)', 'commitments');
?>
<form class="row g-2 mb-3 no-print" method="get"><div class="col-md-4"><select class="form-select" name="project"><?= options(projects_list(), 'project_id', 'title', $projectId, 'All projects') ?></select></div>
  <div class="col-md-3"><select class="form-select" name="status"><?= simple_options(['Pending approval', 'Approved', 'Paid', 'Cancelled', 'Rejected'], $status, 'All statuses') ?></select></div>
  <div class="col-auto"><button class="btn btn-primary">Filter</button> <a class="btn btn-outline-secondary" href="commitments.php">Reset</a></div>
  <?php if (can('record_expenditure')): ?><div class="col text-md-end"><a class="btn btn-primary" href="commitment_form.php">+ New purchase order</a></div><?php endif; ?></form>
<div class="alert alert-light border small">Committed funds (approved purchase orders not yet paid): <strong><?= e(money($enc)) ?></strong>. They are set aside, so they reduce the funds available on each budget line before any money is paid out.</div>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>PO number</th><th>Project / budget line</th><th>Vendor</th><th class="money">Amount</th><th>Status</th><?php if (!is_donor()): ?><th>Submitted by</th><th>Decision</th><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($rows as $c): $mine = (int) $c['submitted_by'] === (int) $user['user_id']; ?>
  <tr><td><strong><?= e($c['po_number']) ?></strong></td><td><?= e($c['project_title']) ?><div class="small text-muted"><?= e($c['category'] . ' / ' . $c['line_desc']) ?></div></td><td><?= e($c['vendor']) ?><?= $c['description'] ? '<div class="small text-muted">' . e($c['description']) . '</div>' : '' ?></td>
    <td class="money"><?= e(money($c['amount'])) ?></td><td><?= finance_status_badge($c['status']) ?></td><?php if (!is_donor()): ?><td class="small"><?= e($c['submitter']) ?></td>
    <td class="small"><?= $c['approver'] ? e($c['approver']) . '<br>' . e(fmt_date($c['approved_at'])) : '' ?><?= $c['decision_note'] ? '<div class="text-muted">' . e($c['decision_note']) . '</div>' : '' ?></td>
    <td class="no-print" style="min-width:230px">
    <?php if ($c['status'] === 'Pending approval' && can('approve_finance')): if ($mine): ?><span class="small text-muted">You submitted this; another approver must decide.</span><?php else: ?>
      <form method="post" action="approve.php" class="d-inline"><?= csrf_field() ?><input type="hidden" name="type" value="commitment"><input type="hidden" name="id" value="<?= (int) $c['commitment_id'] ?>"><input type="hidden" name="decision" value="approve"><button class="btn btn-sm btn-success" type="submit">Approve</button></form>
      <form method="post" action="approve.php" class="d-inline-flex gap-1"><?= csrf_field() ?><input type="hidden" name="type" value="commitment"><input type="hidden" name="id" value="<?= (int) $c['commitment_id'] ?>"><input type="hidden" name="decision" value="reject"><input class="form-control form-control-sm" name="note" placeholder="Reason" style="width:7rem" required><button class="btn btn-sm btn-outline-danger" type="submit">Reject</button></form><?php endif; endif; ?>
    <?php if ($c['status'] === 'Approved' && can('record_expenditure')): ?>
      <form method="post" action="commitment_action.php" class="d-inline-flex gap-1" data-confirm="Record payment for this purchase order?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['commitment_id'] ?>"><input type="hidden" name="action" value="pay"><input class="form-control form-control-sm" name="amount" value="<?= e((string) $c['amount']) ?>" style="width:7.5rem" aria-label="Amount paid"><button class="btn btn-sm btn-primary" type="submit">Mark paid</button></form><?php endif; ?>
    <?php if (in_array($c['status'], ['Pending approval', 'Approved'], true) && can('record_expenditure')): ?>
      <form method="post" action="commitment_action.php" class="d-inline" data-confirm="Cancel this purchase order?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['commitment_id'] ?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-sm btn-outline-secondary" type="submit">Cancel</button></form><?php endif; ?></td><?php endif; ?></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No purchase orders found.</td></tr><?php endif; ?></tbody></table></div></div>
<?php render_footer();
