<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if (!file_exists(APP_ROOT . '/config/install.lock') && !platform_registry_available()) {
    try {
        if (!(int) fetch_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'")) redirect('install.php');
    } catch (Throwable $e) { redirect('install.php'); }
}

$error = ''; $email = ''; $orgCode = strtoupper(trim(get('organisation_code')));
$multiTenant = platform_registry_available();
$GLOBALS['NGO_PUBLIC_CHROME'] = true;   // the sign-in page always uses the public layout
// Viewing this page never selects an organisation or touches the current session: a link from Platform
// Administration only pre-fills the organisation code. The session changes only when someone signs in.
$signedInOrg = ($multiTenant && !empty($_SESSION['uid'])) ? current_organisation() : null;
if (!is_post() && current_user()) {
    if (!$multiTenant || $orgCode === '' || ($signedInOrg && strcasecmp((string) $signedInOrg['organisation_code'], $orgCode) === 0)) redirect('dashboard.php');
}
if (is_post()) {
    csrf_verify();
    $email = strtolower(post('email')); $orgCode = strtoupper(post('organisation_code'));
    if ($email === '' || post('password') === '' || ($multiTenant && $orgCode === '')) {
        $error = $multiTenant ? 'Enter your organisation code, email address and password.' : 'Please enter both your email address and your password.';
    } else {
        $keep = [];
        if ($multiTenant) foreach (['uid', 'org_id', 'org_code', 'schema_checked_v6', 'client_project_id', 'client_org_code'] as $k) if (isset($_SESSION[$k])) $keep[$k] = $_SESSION[$k];
        $validOrg = !$multiTenant || set_organisation_context($orgCode);
        if ($validOrg && attempt_login($email, (string) ($_POST['password'] ?? ''))) {
            $next = safe_next(get('next'));
            redirect($next !== '' ? $next : 'dashboard.php');
        }
        // A failed attempt (wrong password, unknown code) must not sign the person out of an organisation
        // they are already using in this browser.
        if ($multiTenant && $keep) { foreach (['uid', 'org_id', 'org_code', 'schema_checked_v6', 'client_project_id', 'client_org_code'] as $k) unset($_SESSION[$k]); $_SESSION = array_merge($_SESSION, $keep); }
        $signedInOrg = ($multiTenant && !empty($_SESSION['uid'])) ? current_organisation() : null;
        $error = 'The organisation code, email address or password was not recognised, or the account is inactive.';
    }
}
render_header('Sign in');
?>
<div class="card shadow-sm"><div class="card-body p-4">
  <h2 class="h4 text-center mb-1" style="color:#1F3864"><?= e(cfg('app_name')) ?></h2>
  <p class="text-center text-muted mb-4">Sign in to continue</p>
  <?php if ($signedInOrg): ?><div class="alert alert-info py-2 small">You are signed in to <strong><?= e($signedInOrg['organisation_name']) ?></strong> in this browser. <a href="<?= e(url('dashboard.php')) ?>">Return to its dashboard</a>. Signing in to a different organisation here will sign you out of it &mdash; to use two organisations at the same time, open the other one in a private window.</div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
  <form method="post" novalidate><?= csrf_field() ?>
    <?php if ($multiTenant): ?><div class="mb-3"><label class="form-label" for="organisation_code">Organisation code</label><input class="form-control text-uppercase" id="organisation_code" name="organisation_code" value="<?= e($orgCode) ?>" maxlength="24" autocomplete="organization" required></div><?php endif; ?>
    <div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control" type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" autofocus required></div>
    <div class="mb-3"><label class="form-label" for="password">Password</label><input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required></div>
    <button class="btn btn-primary w-100" type="submit">Log in</button>
  </form>
  <p class="mt-3 small">Client? <a href="<?= e(url('client_portal.php')) ?>">Track a project</a>.</p>
  <?php if ($multiTenant): ?><p class="small text-center mb-0"><a href="<?= e(url('platform/login.php')) ?>">Platform administrator login</a></p><?php endif; ?>
  <p class="small text-muted text-center mt-3 mb-0">Forgot your password? Contact your administrator.</p>
</div></div>
<?php render_footer(); ?>
