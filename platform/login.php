<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
if (!platform_registry_available()) redirect('login.php');
if (current_platform_user()) redirect('platform/index.php');
$error=''; $email='';
if (is_post()) {
    csrf_verify(); $email=strtolower(post('email')); $password=(string)($_POST['password']??'');
    $st=platform_db()->prepare('SELECT platform_admin_id,full_name,email,password_hash,is_active FROM platform_admins WHERE email=?');
    $st->execute([$email]); $u=$st->fetch();
    $hash=$u['password_hash']??password_hash('not-a-real-password',PASSWORD_DEFAULT);
    $ok=password_verify($password,$hash);
    if ($u && $ok && (int)$u['is_active']===1) {
        session_regenerate_id(true);
        $_SESSION['platform_uid']=(int)$u['platform_admin_id']; $_SESSION['csrf']=bin2hex(random_bytes(32));
        redirect('platform/index.php');
    }
    $error='Invalid email or password.';
}
render_header('Platform administrator sign in'); ?>
<div class="card shadow-sm"><div class="card-body p-4"><h2 class="h4 text-center">NGO Monitoring & Evaluation System</h2><hr><h6 class="h5 text-center">Platform Administration</h6><p class="text-muted text-center">Sign in to manage registered organisations.</p>
<?php if($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif; ?>
<form method="post"><?=csrf_field()?>
<div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control" type="email" id="email" name="email" value="<?=e($email)?>" required></div>
<div class="mb-3"><label class="form-label" for="password">Password</label><input class="form-control" type="password" id="password" name="password" required></div>
<button class="btn btn-primary w-100">Sign in</button></form>
<p class="small text-center mt-3 mb-0"><a href="<?=e(url('login.php'))?>">Organisation user login</a></p></div></div>
<?php render_footer(); ?>
