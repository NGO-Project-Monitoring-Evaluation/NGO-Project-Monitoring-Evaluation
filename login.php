<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (!file_exists(APP_ROOT . '/config/install.lock') && !fetch_value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['users'])) {
    redirect('install.php');
}
if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
$email = '';
if (is_post()) {
    csrf_verify();
    $email = post('email');
    if ($email === '' || post('password') === '') {
        $error = 'Please enter both your email address and your password.';
    } elseif (attempt_login($email, (string) ($_POST['password'] ?? ''))) {
        $next = safe_next(get('next'));
        redirect($next !== '' ? $next : 'dashboard.php');
    } else {
        $error = 'Invalid email or password.';
    }
}

render_header('Sign in');
?>
<div class="card shadow-sm">
  <div class="card-body p-4">
    <h2 class="h4 text-center mb-1" style="color:#1F3864"><?= e(cfg('app_name')) ?></h2>
    <p class="text-center text-muted mb-4">Sign in to continue</p>
    <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="email">Email address</label>
        <input class="form-control" type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" autofocus required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required>
      </div>
      <button class="btn btn-primary w-100" type="submit">Log in</button>
    </form>
    <p class="small text-muted text-center mt-3 mb-0">Forgot your password? Contact your administrator.</p>
  </div>
</div>
<?php render_footer();
