<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$admin = require_perm('manage_users');

$id = int_or_null(get('id'));
$target = $id ? fetch_one('SELECT * FROM users WHERE user_id = ?', [$id]) : null;
if ($id && !$target) { not_found('user'); }
$roles = fetch_all('SELECT role_id, role_name FROM roles ORDER BY role_id');
$data = $target ?: ['full_name' => '', 'email' => '', 'role_id' => 3, 'is_active' => 1];
$errors = [];

if (is_post()) {
    csrf_verify();
    $data = ['full_name' => post('full_name'), 'email' => strtolower(post('email')), 'role_id' => post('role_id'), 'is_active' => isset($_POST['is_active']) ? 1 : 0];
    $password = (string) ($_POST['password'] ?? '');
    if ($data['full_name'] === '' || mb_strlen($data['full_name']) > 100) $errors[] = 'Enter the full name (100 characters at most).';
    if (!valid_email($data['email'])) $errors[] = 'Enter a valid email address.';
    $roleId = int_or_null($data['role_id']);
    if (!$roleId || !fetch_value('SELECT 1 FROM roles WHERE role_id = ?', [$roleId])) $errors[] = 'Choose a role.';
    if (!$errors && fetch_value('SELECT 1 FROM users WHERE email = ? AND user_id <> ?', [$data['email'], $id ?? 0])) $errors[] = 'Another account already uses that email address.';
    if (!$target || $password !== '') { if ($p = password_problem($password)) $errors[] = $p; }
    if ($target && !$errors) {
        $isSelf = (int) $target['user_id'] === (int) $admin['user_id'];
        $losesAdmin = ($target['role_id'] == 1 && $target['is_active']) && ($roleId != 1 || !$data['is_active']);
        if ($isSelf && $losesAdmin) $errors[] = 'You cannot remove your own administrator access or deactivate your own account.';
        elseif ($losesAdmin && (int) fetch_value('SELECT COUNT(*) FROM users WHERE role_id = 1 AND is_active = 1') <= 1) $errors[] = 'At least one active administrator is required.';
    }
    if (!$errors) {
        if ($target) {
            q('UPDATE users SET full_name=?, email=?, role_id=?, is_active=? WHERE user_id=?', [$data['full_name'], $data['email'], $roleId, $data['is_active'], $id]);
            if ($password !== '') { q('UPDATE users SET password_hash=? WHERE user_id=?', [password_hash($password, PASSWORD_DEFAULT), $id]); }
            audit('Update', 'users', $id, 'Updated account ' . $data['email'] . ($password !== '' ? ' (password reset)' : ''));
            flash('success', 'The account was updated.');
        } else {
            q('INSERT INTO users (full_name, email, password_hash, role_id, is_active) VALUES (?,?,?,?,?)', [$data['full_name'], $data['email'], password_hash($password, PASSWORD_DEFAULT), $roleId, $data['is_active']]);
            $id = (int) db()->lastInsertId();
            audit('Create', 'users', $id, 'Created account ' . $data['email']);
            flash('success', 'The account was created.');
        }
        q('DELETE FROM user_projects WHERE user_id = ?', [$id]);
        if ((int) $roleId === 8) {                       // Donor/Partner: only the projects ticked are visible
            foreach (array_unique(array_map('intval', (array) ($_POST['assigned'] ?? []))) as $pid) {
                if ($pid && fetch_value('SELECT 1 FROM projects WHERE project_id = ?', [$pid])) { q('INSERT INTO user_projects (user_id, project_id) VALUES (?, ?)', [$id, $pid]); }
            }
            audit('Update', 'users', $id, 'Assigned projects for donor/partner ' . $data['email']);
        }
        redirect('admin/users.php');
    }
}
$assigned = $id ? array_map('intval', array_column(fetch_all('SELECT project_id FROM user_projects WHERE user_id = ?', [$id]), 'project_id')) : [];

render_header($target ? 'Edit user' : 'New user', 'users', [['Users', 'admin/users.php'], [$target ? 'Edit ' . $target['full_name'] : 'New user']]);
show_errors($errors);
?>
<div class="card" style="max-width:700px"><div class="card-body">
<form method="post" novalidate autocomplete="off">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label required" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" maxlength="100" value="<?= e($data['full_name']) ?>" required></div>
    <div class="col-md-6"><label class="form-label required" for="email">Email address (used to log in)</label><input class="form-control" type="email" id="email" name="email" maxlength="100" value="<?= e($data['email']) ?>" required></div>
    <div class="col-md-6"><label class="form-label required" for="role_id">Role</label><select class="form-select" id="role_id" name="role_id"><?= options($roles, 'role_id', 'role_name', $data['role_id']) ?></select></div>
    <div class="col-md-6"><label class="form-label <?= $target ? '' : 'required' ?>" for="password"><?= $target ? 'New password (leave blank to keep the current one)' : 'Temporary password' ?></label>
      <input class="form-control" type="password" id="password" name="password" autocomplete="new-password"><div class="form-text">At least 8 characters, with a letter and a number.</div></div>
    <div class="col-12"><label class="form-label">Projects assigned (Donor/Partner role only)</label>
      <div class="border rounded p-2" style="max-height:11rem;overflow:auto">
      <?php foreach (projects_list() as $pr): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="assigned[]" id="as<?= (int) $pr['project_id'] ?>" value="<?= (int) $pr['project_id'] ?>" <?= in_array((int) $pr['project_id'], $assigned, true) ? 'checked' : '' ?>><label class="form-check-label" for="as<?= (int) $pr['project_id'] ?>"><?= e($pr['title']) ?></label></div><?php endforeach; ?></div>
      <div class="form-text">A donor or partner sees only these projects and their reports.</div></div>
    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= $data['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Account is active</label></div></div>
  </div>
  <div class="mt-4"><button class="btn btn-primary" type="submit">Save user</button> <a class="btn btn-outline-secondary" href="users.php">Cancel</a></div>
</form></div></div>
<?php render_footer();
