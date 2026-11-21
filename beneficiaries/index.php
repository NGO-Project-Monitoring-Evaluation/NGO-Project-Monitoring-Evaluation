<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('view_beneficiaries');

$qText = get('q');
$state = in_array(get('state'), NIGERIAN_STATES, true) ? get('state') : '';
$projectId = int_or_null(get('project'));

$where = ['1=1']; $params = [];
if ($qText !== '') { $where[] = '(b.full_name LIKE ? OR b.beneficiary_code LIKE ? OR b.community LIKE ?)'; array_push($params, "%$qText%", "%$qText%", "%$qText%"); }
if ($state !== '') { $where[] = 'b.state = ?'; $params[] = $state; }
if ($projectId)    { $where[] = 'EXISTS (SELECT 1 FROM project_beneficiaries pb WHERE pb.beneficiary_id = b.beneficiary_id AND pb.project_id = ?)'; $params[] = $projectId; }
[$scopeSql, $scopeParams] = project_scope('pb.project_id');
if ($scopeSql !== '') { $where[] = 'EXISTS (SELECT 1 FROM project_beneficiaries pb WHERE pb.beneficiary_id=b.beneficiary_id' . $scopeSql . ')'; $params = array_merge($params, $scopeParams); }
if ($projectId && !can_view_project($projectId)) { deny_access(); }
$w = implode(' AND ', $where);

$total = (int) fetch_value("SELECT COUNT(*) FROM beneficiaries b WHERE $w", $params);
$pg = paginate($total, 25);
$rows = fetch_all(
    "SELECT b.*, (SELECT COUNT(*) FROM project_beneficiaries pb WHERE pb.beneficiary_id = b.beneficiary_id) AS projects
       FROM beneficiaries b WHERE $w ORDER BY b.created_at DESC, b.beneficiary_id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);

render_header('Beneficiaries', 'beneficiaries');
?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-4"><input class="form-control" name="q" value="<?= e($qText) ?>" placeholder="Search name, code or community"></div>
  <div class="col-md-3"><select class="form-select" name="state"><?= simple_options(NIGERIAN_STATES, $state, 'All states') ?></select></div>
  <div class="col-md-3"><select class="form-select" name="project"><?= options(projects_list(), 'project_id', 'title', $projectId, 'All projects') ?></select></div>
  <div class="col-auto"><button class="btn btn-primary">Search</button> <a class="btn btn-outline-secondary" href="index.php">Reset</a></div>
  <?php if (can('manage_beneficiaries')): ?><div class="col text-md-end"><a class="btn btn-primary" href="form.php">+ Register beneficiary</a></div><?php endif; ?>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Code</th><th>Name</th><th>Sex</th><th>Age</th><th>Community</th><th>State</th><th>Projects</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $b): $age = age_from_dob($b['date_of_birth']); ?>
    <tr><td><code><?= e($b['beneficiary_code']) ?></code></td>
      <td><a href="view.php?id=<?= (int) $b['beneficiary_id'] ?>"><?= e($b['full_name']) ?></a></td>
      <td><?= e($b['sex']) ?></td><td><?= $age === null ? '' : $age ?></td><td><?= e($b['community']) ?></td><td><?= e($b['state']) ?></td>
      <td><?= (int) $b['projects'] ?></td>
      <td class="text-end no-print"><?php if (can('manage_beneficiaries')): ?><a class="btn btn-sm btn-outline-secondary" href="form.php?id=<?= (int) $b['beneficiary_id'] ?>">Edit</a><?php endif; ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No beneficiaries found.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<div class="mt-3 d-flex justify-content-between align-items-center"><span class="small text-muted"><?= $total ?> beneficiar<?= $total === 1 ? 'y' : 'ies' ?></span><?= pagination_html($pg) ?></div>
<?php render_footer();
