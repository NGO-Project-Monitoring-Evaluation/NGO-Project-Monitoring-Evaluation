<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('view_projects');

$statuses = ['Planned', 'Ongoing', 'Completed', 'On hold'];
$qText  = get('q');
$status = in_array(get('status'), $statuses, true) ? get('status') : '';

$where = ['1=1']; $params = [];
if ($qText !== '') { $where[] = '(p.title LIKE ? OR p.location LIKE ?)'; $params[] = "%$qText%"; $params[] = "%$qText%"; }
if ($status !== '') { $where[] = 'p.status = ?'; $params[] = $status; }
[$sc, $sp] = project_scope('p.project_id');
$w = implode(' AND ', $where) . $sc; $params = array_merge($params, $sp);

$total = (int) fetch_value("SELECT COUNT(*) FROM projects p WHERE $w", $params);
$pg = paginate($total, 20);
$rows = fetch_all(
    "SELECT p.*, u.full_name AS manager_name,
            (SELECT COUNT(*) FROM activities a WHERE a.project_id = p.project_id) AS activity_count,
            (SELECT ROUND(AVG(a.progress_percent)) FROM activities a WHERE a.project_id = p.project_id) AS avg_progress
       FROM projects p LEFT JOIN users u ON u.user_id = p.manager_id
      WHERE $w ORDER BY p.start_date DESC, p.title LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);

render_header('Projects', 'projects');
?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-5"><input class="form-control" name="q" value="<?= e($qText) ?>" placeholder="Search title or location"></div>
  <div class="col-md-3"><select class="form-select" name="status"><?= simple_options($statuses, $status, 'All statuses') ?></select></div>
  <div class="col-auto"><button class="btn btn-primary">Filter</button> <a class="btn btn-outline-secondary" href="index.php">Reset</a></div>
  <?php if (can('manage_projects')): ?><div class="col text-md-end"><a class="btn btn-primary" href="form.php">+ New project</a></div><?php endif; ?>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Project</th><th>Location</th><th>Dates</th><th>Status</th><th>Responsible officer</th><th>Activities</th><th style="min-width:130px">Average progress</th></tr></thead><tbody>
  <?php foreach ($rows as $p): ?>
    <tr>
      <td><a href="view.php?id=<?= (int) $p['project_id'] ?>"><strong><?= e($p['title']) ?></strong></a></td>
      <td><?= e($p['location']) ?></td>
      <td class="small"><?= e(fmt_date($p['start_date'])) ?> &ndash; <?= e(fmt_date($p['end_date'])) ?></td>
      <td><?= status_badge($p['status']) ?></td>
      <td><?= e($p['manager_name']) ?></td>
      <td><?= (int) $p['activity_count'] ?></td>
      <td><?= $p['avg_progress'] === null ? '<span class="text-muted small">No activities</span>' : progress_bar((float) $p['avg_progress'], progress_class((float) $p['avg_progress'])) ?></td>
    </tr>
  <?php endforeach; if (!$rows): ?>
    <tr><td colspan="7" class="text-center text-muted py-4">No projects found.</td></tr>
  <?php endif; ?>
  </tbody></table></div></div>
<div class="mt-3 d-flex justify-content-between align-items-center"><span class="small text-muted"><?= $total ?> project(s)</span><?= pagination_html($pg) ?></div>
<?php render_footer();
