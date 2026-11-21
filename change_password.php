<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
$user = require_login();

$errors = [];
if (is_post()) {
    csrf_verify();
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $hash = fetch_value('SELECT password_hash FROM users WHERE user_id = ?', [$user['user_id']]);
    if (!$hash || !password_verify($current, $hash)) {
        $errors[] = 'Your current password is not correct.';
    }
    if ($p = password_problem($new)) {
        $errors[] = $p;
    }
    if ($new !== $confirm) {
        $errors[] = 'The new password and its confirmation do not match.';
    }
    if (!$errors) {
        q('UPDATE users SET password_hash = ? WHERE user_id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['user_id']]);
        audit('Update', 'users', (int) $user['user_id'], 'Changed own password');
        flash('success', 'Your password has been changed.');
        redirect('dashboard.php');
    }
}

render_header('Change password', 'account');
show_errors($errors);
?>
<div class="card" style="max-width:520px">
  <div class="card-body">
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label required" for="current_password">Current password</label>
        <input class="form-control" type="password" id="current_password" name="current_password" autocomplete="current-password" required></div>
      <div class="mb-3"><label class="form-label required" for="new_password">New password</label>
        <input class="form-control" type="password" id="new_password" name="new_password" autocomplete="new-password" required>
        <div class="form-text">At least 8 characters, with at least one letter and one number.</div></div>
      <div class="mb-3"><label class="form-label required" for="confirm_password">Confirm new password</label>
        <input class="form-control" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required></div>
      <button class="btn btn-primary" type="submit">Change password</button>
    </form>
  </div>
</div>
<?php render_footer();
