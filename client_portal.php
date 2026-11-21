<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache');
$staff=current_user();
if (get('reset') === '1') { unset($_SESSION['client_project_id'], $_SESSION['client_code_attempts']); if (!$staff) unset($_SESSION['client_org_code'], $_SESSION['org_id'], $_SESSION['org_code']); }
$multiTenant=platform_registry_available();
if (!$staff && $multiTenant && !empty($_SESSION['client_org_code'])) set_organisation_context((string)$_SESSION['client_org_code']);
$error=''; $project=null;
if (is_post()) {
    csrf_verify();
    if (!$staff && $multiTenant) {
        $orgCode=strtoupper(post('organisation_code'));
        if (!empty($_SESSION['client_org_code']) && $_SESSION['client_org_code'] !== $orgCode) unset($_SESSION['client_project_id']);
        if (!set_organisation_context($orgCode)) { unset($_SESSION['client_project_id'], $_SESSION['client_org_code'], $_SESSION['org_id'], $_SESSION['org_code']); $error='The organisation code was not recognised or the organisation is inactive.'; }
        else $_SESSION['client_org_code']=$orgCode;
    }
    if ($error==='') {
        ensure_schema();
        $attempts=(int)($_SESSION['client_code_attempts']??0);
        if($attempts>=10) $error='Too many attempts. Please try again later by opening a new browser session.';
        else {
            $_SESSION['client_code_attempts']=$attempts+1;
            $code=strtoupper(trim((string)post('project_code')));
            if($code!=='' && strlen($code)<=32) $project=fetch_one('SELECT project_id,title,description,location,start_date,end_date,status,allocated_amount FROM projects WHERE client_code=?',[$code]);
            if(!$project) $error='The project code was not recognised. Please check it and try again.';
            elseif($staff && !can_view_project((int)$project['project_id'])) { $project=null; $error='Your account is not assigned to this project.'; }
            else $_SESSION['client_project_id']=(int)$project['project_id'];
        }
    }
} elseif (!empty($_SESSION['client_project_id'])) {
    if ($staff || !$multiTenant || current_organisation()) {
        ensure_schema();
        $project=fetch_one('SELECT project_id,title,description,location,start_date,end_date,status,allocated_amount FROM projects WHERE project_id=?',[(int)$_SESSION['client_project_id']]);
        if($project && $staff && !can_view_project((int)$project['project_id'])) $project=null;
    }
}
if ($project) {
    $id=(int)$project['project_id'];
    $activities=fetch_all("SELECT title,planned_start,planned_end,status,weight_percent,approval_status,completion_notes FROM activities WHERE project_id=? AND (approval_status='Approved' OR status='In progress') ORDER BY planned_start,activity_id",[$id]);
    $finance=fetch_one("SELECT COALESCE((SELECT SUM(e.amount) FROM expenditures e JOIN budget_lines b ON b.line_id=e.line_id WHERE b.project_id=? AND e.status='Approved'),0) spent, COALESCE((SELECT SUM(c.amount) FROM commitments c JOIN budget_lines b ON b.line_id=c.line_id WHERE b.project_id=? AND c.status='Approved'),0) committed, COALESCE((SELECT SUM(g.amount) FROM grants g WHERE g.project_id=? AND g.status='Active'),0) funded",[$id,$id,$id]);
    $progress=project_completion_percent($id);
}
render_header('Client Project Tracking','client_tracking'); $loggedInStaff=$staff!==null;
?>
<?php if(!$loggedInStaff): ?><div class="client-public-brand d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4"><div class="sidebar-brand p-0 text-dark"><?=e(cfg('app_name'))?><span class="brand-sub text-muted">Monitoring, Evaluation and Finance</span></div><a class="btn btn-outline-secondary btn-sm" href="<?=e(url('login.php'))?>">Staff login</a></div><?php endif; ?>
<div class="client-tracking-page"><div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4"><div><?php if(!$loggedInStaff): ?><h2 class="h5 page-title mb-1">Client Project Tracking</h2><?php endif; ?><p class="text-muted mb-0">View verified project progress and approved financial summaries.</p></div><?php if($project): ?><span class="small text-muted">Updates automatically every 60 seconds</span><?php endif; ?></div>
<?php if(!$project): ?><div class="card client-access-card"><div class="card-body p-4"><h3 class="h5 mb-2">Access your project</h3><p class="text-muted">Enter the organisation and project tracking details provided by your project administrator.</p>
<?php if($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?><form method="post"><?=csrf_field()?>
<?php if(!$loggedInStaff && $multiTenant): ?><div class="mb-3"><label class="form-label" for="organisation_code">Organisation code</label><input class="form-control text-uppercase" id="organisation_code" name="organisation_code" maxlength="24" value="<?=e($_SESSION['client_org_code']??'')?>" required></div><?php endif; ?>
<label class="form-label" for="project_code">Project code</label><div class="d-flex flex-column flex-sm-row gap-2"><input class="form-control" id="project_code" name="project_code" maxlength="32" required autocomplete="off"><button class="btn btn-primary flex-shrink-0" type="submit">View project</button></div></form></div></div>
<?php else: ?><div class="card mb-3"><div class="card-body p-4"><div class="d-flex flex-wrap justify-content-between align-items-start gap-2"><div><h3 class="h5 mb-1"><?=e($project['title'])?></h3><p class="text-muted mb-0"><?=e($project['location']?:'Location not specified')?> · <?=e(fmt_date($project['start_date']))?> – <?=e(fmt_date($project['end_date']))?></p></div><span class="badge text-bg-primary"><?=e($project['status'])?></span></div><hr><div class="row g-3"><div class="col-md-4"><div class="small text-muted mb-1">Confirmed completion</div><div class="display-6 text-primary"><?=e(fmt_number($progress))?>%</div><div class="progress"><div class="progress-bar" role="progressbar" style="width:<?=e((string)$progress)?>%" aria-valuenow="<?=e((string)$progress)?>" aria-valuemin="0" aria-valuemax="100"></div></div></div><div class="col-md-4"><div class="small text-muted mb-1">Approved funding</div><div class="h4 mb-0"><?=e(money((float)$finance['funded']))?></div></div><div class="col-md-4"><div class="small text-muted mb-1">Approved expenditure</div><div class="h4 mb-0"><?=e(money((float)$finance['spent']))?></div></div></div></div></div>
<div class="card mb-3"><div class="card-header">Project activities</div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Activity</th><th>Planned end</th><th>Contribution</th><th>Status</th></tr></thead><tbody><?php foreach($activities as $a): ?><tr><td><?=e($a['title'])?></td><td><?=e(fmt_date($a['planned_end']))?></td><td><?=e(fmt_number((float)$a['weight_percent']))?>%</td><td><?=e($a['approval_status']==='Approved'?'Confirmed':$a['status'])?></td></tr><?php endforeach; if(!$activities): ?><tr><td colspan="4" class="text-muted text-center">No published activities yet.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><div class="card-header">Financial summary</div><div class="card-body p-4"><div class="row g-3"><div class="col-md-3"><div class="small text-muted">Project allocation</div><strong><?=e(money((float)$project['allocated_amount']))?></strong></div><div class="col-md-3"><div class="small text-muted">Approved funding</div><strong><?=e(money((float)$finance['funded']))?></strong></div><div class="col-md-3"><div class="small text-muted">Approved expenditure</div><strong><?=e(money((float)$finance['spent']))?></strong></div><div class="col-md-3"><div class="small text-muted">Approved commitments</div><strong><?=e(money((float)$finance['committed']))?></strong></div></div><p class="small text-muted mt-3 mb-0">Only approved financial records are included. Figures reflect the latest saved records.</p></div></div>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3"><p class="small text-muted mb-0">Last refreshed: <?=e(date('d M Y H:i'))?>.</p><a class="btn btn-outline-secondary btn-sm" href="<?=e(url('client_portal.php?reset=1'))?>">Check another project</a></div><?php endif; ?></div>
<?php if($project): ?><script>window.setTimeout(function(){window.location.reload();},60000);</script><?php endif; ?>
<?php render_footer(); ?>
