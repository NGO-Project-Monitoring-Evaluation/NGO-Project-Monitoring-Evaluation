<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$admin=require_platform_login();
$errors=[]; $data=['organisation_name'=>'','organisation_code'=>'','admin_name'=>'','admin_email'=>''];
if (is_post()) {
    csrf_verify(); foreach($data as $k=>$v) $data[$k]=post($k);
    $data['organisation_code']=strtoupper(preg_replace('/[^A-Za-z0-9]/','',$data['organisation_code']));
    $password=(string)($_POST['admin_password']??''); $password2=(string)($_POST['admin_password2']??'');
    if($data['organisation_name']==='' || mb_strlen($data['organisation_name'])>150) $errors[]='Enter a valid organisation name.';
    if(!preg_match('/^[A-Z0-9]{3,24}$/',$data['organisation_code'])) $errors[]='Use 3–24 letters or numbers for the organisation code.';
    if($data['admin_name']==='' || mb_strlen($data['admin_name'])>100) $errors[]='Enter the organisation administrator name.';
    if(!valid_email($data['admin_email'])) $errors[]='Enter a valid administrator email address.';
    if($p=password_problem($password)) $errors[]=$p;
    if($password!==$password2) $errors[]='The administrator passwords do not match.';
    if(!$errors) {
        try {
            $created=provision_organisation($data['organisation_name'],$data['organisation_code'],$data['admin_name'],$data['admin_email'],$password);
            // Post/redirect/get: the Super Administrator stays on this dashboard with the session intact,
            // the form is empty again, and nothing is opened or switched automatically.
            $_SESSION['platform_created_org']=['name'=>$created['organisation_name'],'code'=>$created['organisation_code']];
            flash('success','The organisation "'.$created['organisation_name'].'" was created with its own separate, clean database. You are still signed in here and can create another organisation or manage the existing ones.');
            redirect('platform/index.php');
        } catch(OrganisationException $e) {
            $errors[]=$e->getMessage();
        } catch(Throwable $e) {
            error_log('Organisation creation failed: '.$e->getMessage());
            $errors[]='The organisation could not be created. Check the database permissions and server error log.';
        }
    }
}
ensure_registry_constraints();
$organisations=platform_db()->query('SELECT organisation_id,organisation_name,organisation_code,database_name,status,created_at FROM organisations ORDER BY organisation_id')->fetchAll();
// Older builds disabled the original tenant Super Administrator during conversion. Restore
// that same account in the first (original) organisation so the platform owner can still
// return to the original dashboard; this does not grant access to any other tenant.
$restoreMarker = APP_ROOT . '/config/original_admin_access_restored.lock';
if ($organisations && !is_file($restoreMarker)) {
    try {
        $originalTenant = connect_database((string) $organisations[0]['database_name']);
        $restore = $originalTenant->prepare("UPDATE users u JOIN roles r ON r.role_id=u.role_id SET u.is_active=1 WHERE LOWER(u.email)=LOWER(?) AND r.role_name='Super Administrator' AND u.is_active=0");
        $restore->execute([(string) $admin['email']]);
        // One-time compatibility repair for installations migrated by the earlier build.
        if (file_put_contents($restoreMarker, 'Original administrator access restored on ' . date('c')) === false) {
            throw new RuntimeException('Could not write the original administrator access marker.');
        }
    } catch (Throwable $restoreError) {
        error_log('Original organisation administrator access check failed: ' . $restoreError->getMessage());
    }
}
$justCreated = $_SESSION['platform_created_org'] ?? null; unset($_SESSION['platform_created_org']);
$health = []; $healthIssues = 0;
foreach ($organisations as $o) { try { $health[(int)$o['organisation_id']] = organisation_health($o); } catch (Throwable $he) { $health[(int)$o['organisation_id']] = ['level'=>'error','messages'=>['The organisation could not be checked.'],'repairable'=>false,'records'=>null]; } if ($health[(int)$o['organisation_id']]['level'] !== 'ok') $healthIssues++; }
render_header('Organisation administration', 'platform'); show_errors($errors);
$originalOrg = $organisations[0] ?? null;
$loginUrl = fn(string $code) => url('login.php?organisation_code=' . rawurlencode($code));
?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><h2 class="h5 mb-1">Platform administration</h2><p class="text-muted small mb-0">Signed in as <?=e($admin['full_name'])?> (Platform Super Administrator)</p></div><?php if ($originalOrg && $originalOrg['status'] === 'Active' && ($health[(int)$originalOrg['organisation_id']]['level'] ?? '') !== 'error'): ?><a class="btn btn-outline-primary btn-sm" href="<?=e(url('platform/enter.php?to=dashboard.php&t='.csrf_token()))?>">Open my original dashboard</a><?php endif; ?></div>
<?php if ($justCreated): ?><div class="alert alert-success d-flex justify-content-between align-items-center flex-wrap gap-2"><span><strong><?=e($justCreated['name'])?></strong> is ready (code <code><?=e($justCreated['code'])?></code>). Your platform session is unchanged.</span><a class="btn btn-success btn-sm" target="_blank" rel="noopener" href="<?=e($loginUrl($justCreated['code']))?>">Open its sign-in page in a new tab</a></div><?php endif; ?>
<?php if ($healthIssues): ?><div class="alert alert-warning"><strong><?=$healthIssues?> organisation<?=$healthIssues===1?' has':'s have'?> a database assignment that needs attention.</strong> See the Database check column. Repairing gives the organisation its own new, clean database; the database it was pointing at is never modified or deleted.</div><?php endif; ?>
<div class="card mb-4"><div class="card-header d-flex justify-content-between"><strong>Registered organisations</strong><span class="text-muted small"><?=count($organisations)?> registered</span></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Organisation</th><th>Organisation code</th><th>Database</th><th>Database check</th><th>Status</th><th>Created</th><th>Administration</th></tr></thead><tbody>
<?php foreach($organisations as $org): $oid=(int)$org['organisation_id']; $h=$health[$oid]; ?><tr><td><?=e($org['organisation_name'])?></td><td><code><?=e($org['organisation_code'])?></code></td><td><code><?=e($org['database_name'])?></code></td>
<td style="min-width:14rem"><span class="badge text-bg-<?=$h['level']==='ok'?'success':($h['level']==='warn'?'warning':'danger')?>"><?=$h['level']==='ok'?'Separate database':($h['level']==='warn'?'Review':'Needs repair')?></span><?php if($h['records']!==null): ?> <span class="small text-muted"><?=(int)$h['records']?> project<?=$h['records']===1?'':'s'?></span><?php endif; ?>
<?php foreach($h['messages'] as $m): ?><div class="small text-danger mt-1"><?=e($m)?></div><?php endforeach; ?>
<?php if($h['repairable']): ?><details class="mt-2"><summary class="small">Repair this organisation</summary>
<form method="post" action="<?=e(url('platform/repair.php'))?>" class="mt-2" data-confirm="Create a new clean database for <?=e($org['organisation_name'])?> and re-assign it? The current database is left untouched."><?=csrf_field()?><input type="hidden" name="organisation_id" value="<?=$oid?>">
<p class="small text-muted mb-2">A new clean database is created and the organisation is re-assigned to it. Enter the new Organisation Administrator for it. Nothing in the current database is changed or deleted.</p>
<input class="form-control form-control-sm mb-1" name="admin_name" placeholder="Administrator full name" required maxlength="100"><input class="form-control form-control-sm mb-1" type="email" name="admin_email" placeholder="Administrator email" required><input class="form-control form-control-sm mb-1" type="password" name="admin_password" placeholder="Initial password" autocomplete="new-password" required><input class="form-control form-control-sm mb-2" type="password" name="admin_password2" placeholder="Confirm password" autocomplete="new-password" required><button class="btn btn-sm btn-warning" type="submit">Create clean database and re-assign</button></form></details><?php endif; ?></td>
<td><span class="badge text-bg-<?=$org['status']==='Active'?'success':'secondary'?>"><?=e($org['status'])?></span></td><td><?=e(fmt_datetime($org['created_at']))?></td><td><div class="mb-1"><?php $tenantAdmins=[]; if(organisation_database_conflict($org)===null){ try { $tenantAdmins=connect_database((string)$org['database_name'])->query('SELECT user_id,full_name,email,is_active FROM users WHERE role_id=10 ORDER BY user_id')->fetchAll(); } catch(Throwable $e) { $tenantAdmins=[]; } } ?><?php foreach($tenantAdmins as $ta): ?><a class="small d-block" href="<?=e(url('platform/admin.php?id='.$oid.'&user_id='.(int)$ta['user_id']))?>"><?=e($ta['full_name'])?> — <?=e($ta['email'])?><?=!(int)$ta['is_active']?' (inactive)':''?></a><?php endforeach; ?></div>
<?php if($h['level']!=='error'): ?><a class="btn btn-sm btn-outline-primary me-1" href="<?=e(url('platform/admin.php?id='.$oid))?>">Add admin</a><a class="btn btn-sm btn-outline-secondary me-1" target="_blank" rel="noopener" href="<?=e($loginUrl($org['organisation_code']))?>">Open workspace</a><?php endif; ?><form method="post" action="<?=e(url('platform/toggle.php'))?>" class="d-inline m-0"><?=csrf_field()?><input type="hidden" name="organisation_id" value="<?=e($org['organisation_id'])?>"><button class="btn btn-sm btn-outline-<?=$org['status']==='Active'?'danger':'success'?>"><?=$org['status']==='Active'?'Suspend':'Activate'?></button></form></td></tr><?php endforeach; ?>
<?php if(!$organisations): ?><tr><td colspan="7" class="text-center text-muted py-4">No organisations have been registered.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="card"><div class="card-header">Create an organisation</div><div class="card-body"><p class="text-muted small">Each new organisation receives its own separate database, standard modules and its own administrator. New workspaces start empty; no demonstration or existing organisation records are copied. You stay on this page after creating it.</p>
<form method="post" autocomplete="off"><?=csrf_field()?>
<div class="row g-3"><div class="col-md-6"><label class="form-label" for="organisation_name">Organisation name</label><input class="form-control" id="organisation_name" name="organisation_name" maxlength="150" value="<?=e($data['organisation_name'])?>" required></div><div class="col-md-6"><label class="form-label" for="organisation_code">Organisation code</label><input class="form-control text-uppercase" id="organisation_code" name="organisation_code" maxlength="24" value="<?=e($data['organisation_code'])?>" required><div class="form-text">3–24 letters or numbers. Staff use this code to sign in.</div></div><div class="col-md-6"><label class="form-label" for="admin_name">Administrator full name</label><input class="form-control" id="admin_name" name="admin_name" maxlength="100" value="<?=e($data['admin_name'])?>" required></div><div class="col-md-6"><label class="form-label" for="admin_email">Administrator email</label><input class="form-control" type="email" id="admin_email" name="admin_email" value="<?=e($data['admin_email'])?>" required></div><div class="col-md-6"><label class="form-label" for="admin_password">Initial password</label><input class="form-control" type="password" id="admin_password" name="admin_password" autocomplete="new-password" required></div><div class="col-md-6"><label class="form-label" for="admin_password2">Confirm password</label><input class="form-control" type="password" id="admin_password2" name="admin_password2" autocomplete="new-password" required></div></div>
<div class="mt-3"><button class="btn btn-primary" type="submit">Create organisation</button></div></form></div></div>
<?php render_footer(); ?>
