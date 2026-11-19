<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('manage_users');

$users = fetch_all('SELECT u.*, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id ORDER BY u.is_active DESC, u.full_name');
render_header('Users', 'users');
?>
<div class="mb-3 text-end no-print"><a class="btn btn-primary" href="user_form.php">+ New user</a></div>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>
  <?php foreach ($users as $u): $self = (int) $u['user_id'] === (int) $user['user_id']; ?>
    <tr class="<?= $u['is_active'] ? '' : 'text-muted' ?>"><td><strong><?= e($u['full_name']) ?></strong><?= $self ? ' <span class="badge text-bg-light border">You</span>' : '' ?></td>
      <td><?= e($u['email']) ?></td><td><?= e($u['role_name']) ?></td>
      <td><?= $u['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Deactivated</span>' ?></td>
      <td class="small"><?= e(fmt_date($u['created_at'])) ?></td>
      <td class="text-nowrap text-end"><a class="btn btn-sm btn-outline-secondary" href="user_form.php?id=<?= (int) $u['user_id'] ?>">Edit</a>
        <?php if (!$self): ?>
        <form method="post" action="user_toggle.php" class="d-inline" data-confirm="<?= $u['is_active'] ? 'Deactivate this account? The person will not be able to log in.' : 'Activate this account?' ?>">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['user_id'] ?>"><button class="btn btn-sm btn-outline-warning" type="submit"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
        <form method="post" action="user_delete.php" class="d-inline" data-confirm="Delete this account permanently?">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['user_id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form>
        <?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<p class="small text-muted mt-2">A user who has created records can be deactivated but not deleted, so that the record keeps its author. A deactivated account or a changed role takes effect the next time the person opens a page.</p>
<?php render_footer();
