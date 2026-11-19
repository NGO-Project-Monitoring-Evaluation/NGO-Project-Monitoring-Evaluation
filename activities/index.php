<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('view_operations');

$statuses = ['Not started', 'In progress', 'Completed', 'Delayed'];
$projectId = int_or_null(get('project'));
$status    = in_array(get('status'), $statuses, true) ? get('status') : '';
$assignee  = is_officer() ? (int) $user['user_id'] : int_or_null(get('assigned'));

$where = ['1=1']; $params = [];
if ($projectId) { $where[] = 'a.project_id = ?'; $params[] = $projectId; }
if ($assignee)  { $where[] = 'a.assigned_to = ?'; $params[] = $assignee; }
if ($status === 'Delayed') {
    $where[] = flagged_sql('a'); $params[] = today();
} elseif ($status !== '') {
    $where[] = 'a.status = ?'; $params[] = $status;
    if ($status !== 'Completed') { $where[] = 'NOT ' . flagged_sql('a'); $params[] = today(); }
}
$w = implode(' AND ', $where);
$total = (int) fetch_value("SELECT COUNT(*) FROM activities a WHERE $w", $params);
$pg = paginate($total, 20);
$rows = fetch_all(
    "SELECT a.*, p.title AS project_title, u.full_name AS assignee_name
       FROM activities a JOIN projects p ON p.project_id = a.project_id LEFT JOIN users u ON u.user_id = a.assigned_to
      WHERE $w ORDER BY (a.status = 'Completed'), a.planned_end IS NULL, a.planned_end LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);

render_header('Activities', 'activities');
?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-3"><select class="form-select" name="project"><?= options(projects_list(), 'project_id', 'title', $projectId, 'All projects') ?></select></div>
  <div class="col-md-2"><select class="form-select" name="status"><?= simple_options($statuses, $status, 'All statuses') ?></select></div>
  <?php if (!is_officer()): ?><div class="col-md-3"><select class="form-select" name="assigned"><?= options(active_users(), 'user_id', 'full_name', $assignee, 'Anyone') ?></select></div><?php endif; ?>
  <div class="col-auto"><button class="btn btn-primary">Filter</button> <a class="btn btn-outline-secondary" href="index.php">Reset</a></div>
  <?php if (can('manage_activities')): ?><div class="col text-md-end"><a class="btn btn-primary" href="form.php<?= $projectId ? '?project_id=' . $projectId : '' ?>">+ New activity</a></div><?php endif; ?>
</form>
<?php if (is_officer()): ?><p class="small text-muted">You see the activities assigned to you. Delayed activities, and those past their planned end date, are highlighted.</p><?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
  <thead><tr><th>Activity</th><th>Project</th><th>Assigned to</th><th>Planned end</th><th>Status</th><th style="min-width:130px">Progress</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $a): ?>
    <tr class="<?= activity_is_flagged($a) ? 'table-flagged' : '' ?>">
      <td><strong><?= e($a['title']) ?></strong></td><td><?= e($a['project_title']) ?></td><td><?= e($a['assignee_name']) ?></td>
      <td><?= e(fmt_date($a['planned_end'])) ?></td><td><?= activity_status_badge($a) ?>
        <?php if (activity_is_flagged($a)): ?><div class="reason-text mt-1"><?= !empty($a['delay_reason']) ? 'Reason: ' . e(mb_strimwidth($a['delay_reason'], 0, 90, '...')) : '<span class="text-danger">No reason recorded</span>' ?></div><?php endif; ?></td>
      <td><?= progress_bar((float) $a['progress_percent'], progress_class((float) $a['progress_percent'])) ?></td>
      <td class="text-nowrap no-print">
        <?php if (can_update_activity($a)): ?>
          <a class="btn btn-sm btn-outline-primary" href="update.php?id=<?= (int) $a['activity_id'] ?>"><?= activity_is_flagged($a) && empty($a['delay_reason']) ? 'Record reason' : 'Update' ?></a><?php endif; ?>
        <?php if (can('manage_activities')): ?> <a class="btn btn-sm btn-outline-secondary" href="form.php?id=<?= (int) $a['activity_id'] ?>">Edit</a><?php endif; ?>
      </td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No activities found.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<div class="mt-3 d-flex justify-content-between align-items-center"><span class="small text-muted"><?= $total ?> activit<?= $total === 1 ? 'y' : 'ies' ?></span><?= pagination_html($pg) ?></div>
<?php render_footer();
