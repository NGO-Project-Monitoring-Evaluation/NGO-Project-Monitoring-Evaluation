<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
require_platform_login();
$orgId=int_or_null(get('id')); $org=$orgId?organisation_by_id($orgId,false):null;
if(!$org) not_found('organisation');
if(organisation_database_conflict($org)!==null){ flash('danger','This organisation has an incorrect database assignment. Repair it on the Platform Dashboard first.'); redirect('platform/index.php'); }
$tenant=connect_database((string)$org['database_name']);
$userId=int_or_null(get('user_id')); $target=null;
if($userId){$st=$tenant->prepare('SELECT user_id,full_name,email,is_active FROM users WHERE user_id=? AND role_id=10');$st->execute([$userId]);$target=$st->fetch()?:null;if(!$target)not_found('organisation administrator');}
function fetch_tenant_value(PDO $pdo,string $sql){return $pdo->query($sql)->fetchColumn();}
$errors=[];$data=$target?:['full_name'=>'','email'=>'','is_active'=>1];
if(is_post()){
    csrf_verify(); $data=['full_name'=>post('full_name'),'email'=>strtolower(post('email')),'is_active'=>isset($_POST['is_active'])?1:0];
    $password=(string)($_POST['password']??'');
    if($data['full_name']===''||mb_strlen($data['full_name'])>100)$errors[]='Enter the administrator full name.';
    if(!valid_email($data['email']))$errors[]='Enter a valid email address.';
    if((!$target || $password!=='') && ($p=password_problem($password)))$errors[]=$p;
    if($target && !$data['is_active'] && (int)fetch_tenant_value($tenant,'SELECT COUNT(*) FROM users WHERE role_id=10 AND is_active=1')<=1 && (int)$target['is_active']===1)$errors[]='At least one active Organisation Administrator must remain.';
    $st=$tenant->prepare('SELECT COUNT(*) FROM users WHERE email=? AND user_id<>?');$st->execute([$data['email'],$userId??0]);if((int)$st->fetchColumn())$errors[]='Another user in this organisation already uses that email address.';
    if(!$errors){
        if($target){$st=$tenant->prepare('UPDATE users SET full_name=?,email=?,is_active=? WHERE user_id=? AND role_id=10');$st->execute([$data['full_name'],$data['email'],$data['is_active'],$userId]);if($password!==''){$tenant->prepare('UPDATE users SET password_hash=? WHERE user_id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$userId]);} $description='Updated organisation administrator '.$data['email'];}
        else{$st=$tenant->prepare('INSERT INTO users (full_name,email,password_hash,role_id,is_active) VALUES (?,?,?,10,1)');$st->execute([$data['full_name'],$data['email'],password_hash($password,PASSWORD_DEFAULT)]);$userId=(int)$tenant->lastInsertId();$description='Created organisation administrator '.$data['email'];}
        $platform=platform_db();$platform->prepare('INSERT INTO platform_audit_log (platform_admin_id,action,organisation_id,details) VALUES (?,?,?,?)')->execute([(int)current_platform_user()['platform_admin_id'],'Organisation administrator updated',$orgId,$description]);
        try{$tenant->prepare('INSERT INTO audit_log (user_id,action,table_name,record_id,details) VALUES (NULL,?,?,?,?)')->execute(['Platform administrator','users',$userId,$description]);}catch(Throwable $ignore){}
        flash('success','Organisation administrator details saved.');redirect('platform/index.php');
    }
}
render_header('Manage organisation administrator', 'platform');show_errors($errors);
?>
<div class="mb-3"><a class="btn btn-outline-secondary btn-sm" href="<?=e(url('platform/index.php'))?>">&larr; Back to Platform Dashboard</a></div>
<div class="card"><div class="card-header"><?=e($org['organisation_name'])?> — Organisation Administrator</div><div class="card-body"><form method="post"><?=csrf_field()?>
<div class="mb-3"><label class="form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" value="<?=e($data['full_name'])?>" required></div>
<div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control" type="email" id="email" name="email" value="<?=e($data['email'])?>" required></div>
<div class="mb-3"><label class="form-label" for="password"><?= $target?'New password (leave blank to keep current password)':'Initial password' ?></label><input class="form-control" type="password" id="password" name="password" <?= $target?'':'required' ?>></div>
<?php if($target): ?><div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?=$data['is_active']?'checked':''?>><label class="form-check-label" for="is_active">Account is active</label></div><?php endif; ?>
<button class="btn btn-primary" type="submit">Save administrator</button></form></div></div>
<?php render_footer(); ?>
