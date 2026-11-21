<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('view_beneficiaries');

$id = int_or_null(get('id'));
$b = $id ? fetch_one('SELECT b.*, u.full_name AS registrar FROM beneficiaries b LEFT JOIN users u ON u.user_id = b.registered_by WHERE b.beneficiary_id = ?', [$id]) : null;
if (!$b) { not_found('beneficiary'); }
if (!can_view_beneficiary((int)$id)) { deny_access(); }

[$bScope, $bScopeParams] = project_scope('pb.project_id');
$enrolments = fetch_all('SELECT pb.id, pb.enrolled_on, p.project_id, p.title, p.status FROM project_beneficiaries pb JOIN projects p ON p.project_id = pb.project_id WHERE pb.beneficiary_id = ?' . $bScope . ' ORDER BY pb.enrolled_on DESC', array_merge([$id], $bScopeParams));
$available = array_values(array_filter(projects_list(), fn($pr) => !fetch_value('SELECT 1 FROM project_beneficiaries WHERE beneficiary_id=? AND project_id=?', [$id, $pr['project_id']])));
$age = age_from_dob($b['date_of_birth']);

render_header('Beneficiary details', 'beneficiaries', [['Beneficiaries', 'beneficiaries/index.php'], [$b['beneficiary_code'] . ' ' . $b['full_name']]]);
?>
<div class="mb-3 no-print"><a class="btn btn-outline-secondary" href="index.php">&larr; All beneficiaries</a> <?php if (can('manage_beneficiaries')): ?><a class="btn btn-primary" href="form.php?id=<?= $id ?>">Edit record</a><?php endif; ?></div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Record <code><?= e($b['beneficiary_code']) ?></code></div><div class="card-body">
    <h2 class="h5"><?= e($b['full_name']) ?></h2>
    <dl class="row mb-0">
      <dt class="col-5">Sex</dt><dd class="col-7"><?= e($b['sex'] ?: 'Not recorded') ?></dd>
      <dt class="col-5">Date of birth</dt><dd class="col-7"><?= $b['date_of_birth'] ? e(fmt_date($b['date_of_birth'])) . ' (age ' . $age . ')' : 'Not recorded' ?></dd>
      <dt class="col-5">Phone</dt><dd class="col-7"><?= e($b['phone'] ?: 'Not recorded') ?></dd>
      <dt class="col-5">Community</dt><dd class="col-7"><?= e($b['community'] ?: 'Not recorded') ?></dd>
      <dt class="col-5">State</dt><dd class="col-7"><?= e($b['state'] ?: 'Not recorded') ?></dd>
      <dt class="col-5">Consent</dt><dd class="col-7">Confirmed on <?= e(fmt_date($b['consent_date'])) ?></dd>
      <dt class="col-5">Registered by</dt><dd class="col-7"><?= e($b['registrar']) ?> on <?= e(fmt_date($b['created_at'])) ?></dd>
    </dl></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Projects enrolled in</div>
    <ul class="list-group list-group-flush">
      <?php foreach ($enrolments as $en): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center">
          <span><a href="<?= e(url('projects/view.php?id=' . $en['project_id'])) ?>"><?= e($en['title']) ?></a> <?= status_badge($en['status']) ?>
            <span class="small text-muted d-block">Enrolled <?= e(fmt_date($en['enrolled_on'])) ?></span></span>
          <?php if (can('manage_beneficiaries')): ?><form method="post" action="unenrol.php" data-confirm="Remove this beneficiary from the project?" class="m-0 no-print"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $en['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Remove</button></form><?php endif; ?>
        </li>
      <?php endforeach; if (!$enrolments): ?><li class="list-group-item text-muted">Not enrolled in any project yet.</li><?php endif; ?>
    </ul>
    <?php if ($available && can('manage_beneficiaries')): ?>
    <div class="card-body border-top no-print">
      <form method="post" action="enrol.php" class="row g-2"><?= csrf_field() ?><input type="hidden" name="beneficiary_id" value="<?= $id ?>">
        <div class="col"><select class="form-select" name="project_id" aria-label="Project"><?= options($available, 'project_id', 'title', null, 'Choose a project') ?></select></div>
        <div class="col-auto"><button class="btn btn-primary" type="submit">Enrol</button></div></form>
    </div>
    <?php endif; ?>
  </div></div>
</div>
<?php render_footer();
