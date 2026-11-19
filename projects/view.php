<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('view_projects');

$id = int_or_null(get('id'));
$p = $id ? fetch_one('SELECT p.*, u.full_name AS manager_name, c.full_name AS creator_name
                        FROM projects p LEFT JOIN users u ON u.user_id = p.manager_id LEFT JOIN users c ON c.user_id = p.created_by
                       WHERE p.project_id = ?', [$id]) : null;
if (!$p || !can_view_project((int) $p['project_id'])) { not_found('project'); }
$ops = can('view_operations');

$activities = !$ops ? [] : fetch_all('SELECT a.*, u.full_name AS assignee FROM activities a LEFT JOIN users u ON u.user_id = a.assigned_to WHERE a.project_id = ? ORDER BY a.planned_end IS NULL, a.planned_end, a.title', [$id]);
$indicators = indicator_progress_rows($id);
$finRow = can('view_finance') ? (finance_projects($id)[0] ?? null) : null;
$enrolled = (int) fetch_value('SELECT COUNT(*) FROM project_beneficiaries WHERE project_id = ?', [$id]);

render_header('Project details', 'projects', [['Projects', 'projects/index.php'], [$p['title']]]);
?>
<div class="d-flex flex-wrap gap-2 mb-3 no-print">
  <a class="btn btn-outline-secondary" href="index.php">&larr; All projects</a>
  <?php if (can('manage_projects')): ?>
    <a class="btn btn-primary" href="form.php?id=<?= $id ?>">Edit project</a>
    <form method="post" action="delete.php" data-confirm="Delete this project? This cannot be undone." class="m-0">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-outline-danger" type="submit">Delete</button></form>
  <?php endif; ?>
</div>
<div class="card mb-3"><div class="card-body">
  <div class="d-flex justify-content-between flex-wrap"><h2 class="h4"><?= e($p['title']) ?></h2><div><?= status_badge($p['status']) ?></div></div>
  <?php if ($p['description']): ?><p><?= nl2br(e($p['description'])) ?></p><?php endif; ?>
  <dl class="row mb-0">
    <dt class="col-sm-3">Location</dt><dd class="col-sm-9"><?= e($p['location'] ?: 'Not recorded') ?></dd>
    <dt class="col-sm-3">Dates</dt><dd class="col-sm-9"><?= e(fmt_date($p['start_date'])) ?> &ndash; <?= e(fmt_date($p['end_date'])) ?></dd>
    <dt class="col-sm-3">Responsible officer</dt><dd class="col-sm-9"><?= e($p['manager_name'] ?: 'Not assigned') ?></dd>
    <dt class="col-sm-3">Created by</dt><dd class="col-sm-9"><?= e($p['creator_name'] ?: '') ?> on <?= e(fmt_date($p['created_at'])) ?></dd>
    <?php if (can('view_beneficiaries')): ?>
    <dt class="col-sm-3">Beneficiaries enrolled</dt><dd class="col-sm-9"><a href="<?= e(url('beneficiaries/index.php?project=' . $id)) ?>"><?= $enrolled ?></a></dd>
    <?php endif; ?>
  </dl></div></div>

<?php if ($ops): ?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>Activities (<?= count($activities) ?>)</span>
  <?php if (can('manage_activities')): ?><a class="small no-print" href="<?= e(url('activities/form.php?project_id=' . $id)) ?>">+ Add activity</a><?php endif; ?></div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Activity</th><th>Assigned to</th><th>Planned end</th><th>Status</th><th style="min-width:120px">Progress</th></tr></thead><tbody>
  <?php foreach ($activities as $a): ?>
    <tr class="<?= activity_is_flagged($a) ? 'table-flagged' : '' ?>"><td><?php if (can_update_activity($a)): ?><a href="<?= e(url('activities/update.php?id=' . $a['activity_id'])) ?>"><?= e($a['title']) ?></a><?php else: ?><?= e($a['title']) ?><?php endif; ?>
        <?php if (activity_is_flagged($a)): ?><div class="reason-text"><?= !empty($a['delay_reason']) ? 'Reason for delay: ' . e($a['delay_reason']) : '<span class="text-danger">No reason for the delay recorded</span>' ?></div><?php endif; ?></td>
      <td><?= e($a['assignee']) ?></td><td><?= e(fmt_date($a['planned_end'])) ?></td><td><?= activity_status_badge($a) ?></td>
      <td><?= progress_bar((float) $a['progress_percent'], progress_class((float) $a['progress_percent'])) ?></td></tr>
  <?php endforeach; if (!$activities): ?><tr><td colspan="5" class="text-center text-muted py-3">No activities yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>

<?php endif; ?>
<?php if ($finRow): ?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>Finance summary</span><?php if (can('view_finance')): ?><a class="small no-print" href="<?= e(url('finance/index.php?project=' . $id)) ?>">Finance overview</a><?php endif; ?></div>
  <div class="card-body"><div class="row g-3 text-center">
    <div class="col-6 col-md-2"><div class="small text-muted">Approved budget</div><div class="fw-bold"><?= e(money_short($finRow['budget'])) ?></div></div>
    <div class="col-6 col-md-2"><div class="small text-muted">Funds received</div><div class="fw-bold"><?= e(money_short($finRow['received'])) ?></div></div>
    <div class="col-6 col-md-2"><div class="small text-muted">Expenditure</div><div class="fw-bold"><?= e(money_short($finRow['spent'])) ?></div></div>
    <div class="col-6 col-md-2"><div class="small text-muted">Committed</div><div class="fw-bold"><?= e(money_short($finRow['committed'])) ?></div></div>
    <div class="col-6 col-md-2"><div class="small text-muted">Balance</div><div class="fw-bold"><?= e(money_short($finRow['balance'])) ?></div></div>
    <div class="col-6 col-md-2"><div class="small text-muted">Activity progress / budget spent</div><div class="fw-bold"><?= e(fmt_number($finRow['progress'])) ?>% / <?= $finRow['budget_spent_pct'] === null ? '&ndash;' : e(fmt_number($finRow['budget_spent_pct'])) . '%' ?></div></div>
  </div></div></div>
<?php endif; ?>
<div class="card"><div class="card-header d-flex justify-content-between"><span>Performance indicators (<?= count($indicators) ?>)</span>
  <?php if (can('define_indicators')): ?><a class="small no-print" href="<?= e(url('indicators/form.php?project_id=' . $id)) ?>">+ Add indicator</a><?php endif; ?></div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Indicator</th><th>Baseline</th><th>Target</th><th>Actual</th><th style="min-width:210px">Progress</th></tr></thead><tbody>
  <?php foreach ($indicators as $i): ?>
    <tr><td><?php if ($ops): ?><a href="<?= e(url('indicators/view.php?id=' . $i['indicator_id'])) ?>"><?= e($i['name']) ?></a><?php else: ?><?= e($i['name']) ?><?php endif; ?> <span class="text-muted small">(<?= e($i['unit']) ?>)</span></td>
      <td><?= e(fmt_number($i['baseline'])) ?></td><td><?= e(fmt_number($i['target'])) ?></td><td><?= $i['actual_value'] === null ? '&ndash;' : e(fmt_number($i['actual_value'])) ?></td>
      <td><?= indicator_progress_html($i['metrics']) ?></td></tr>
  <?php endforeach; if (!$indicators): ?><tr><td colspan="5" class="text-center text-muted py-3">No indicators yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php render_footer();
