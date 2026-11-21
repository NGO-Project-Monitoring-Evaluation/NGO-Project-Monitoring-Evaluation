<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('view_audit');

$action = get('action'); $uid = int_or_null(get('user')); $from = get('from'); $to = get('to');
$where = ['1=1']; $params = [];
if ($action !== '') { $where[] = 'l.action = ?'; $params[] = $action; }
if ($uid)           { $where[] = 'l.user_id = ?'; $params[] = $uid; }
if (valid_date($from)) { $where[] = 'DATE(l.created_at) >= ?'; $params[] = $from; }
if (valid_date($to))   { $where[] = 'DATE(l.created_at) <= ?'; $params[] = $to; }
$w = implode(' AND ', $where);
$total = (int) fetch_value("SELECT COUNT(*) FROM audit_log l WHERE $w", $params);
$pg = paginate($total, 50);
$rows = fetch_all("SELECT l.*, u.full_name FROM audit_log l LEFT JOIN users u ON u.user_id = l.user_id WHERE $w ORDER BY l.log_id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
$actions = array_column(fetch_all('SELECT DISTINCT action FROM audit_log ORDER BY action'), 'action');

render_header('Audit log', 'audit');
?>
<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-md-2"><select class="form-select" name="action"><?= simple_options($actions, $action, 'All actions') ?></select></div>
  <div class="col-md-3"><select class="form-select" name="user"><?= options(fetch_all('SELECT user_id, full_name FROM users ORDER BY full_name'), 'user_id', 'full_name', $uid, 'All users') ?></select></div>
  <div class="col-md-2"><input class="form-control" type="date" name="from" value="<?= e($from) ?>" aria-label="From"></div>
  <div class="col-md-2"><input class="form-control" type="date" name="to" value="<?= e($to) ?>" aria-label="To"></div>
  <div class="col-auto"><button class="btn btn-primary">Filter</button> <a class="btn btn-outline-secondary" href="audit.php">Reset</a></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Table</th><th>Record</th><th>Details</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td class="text-nowrap small"><?= e(fmt_datetime($r['created_at'])) ?></td><td><?= e($r['full_name'] ?: 'Unknown') ?></td>
    <td><span class="badge text-bg-<?= $r['action'] === 'Login failed' ? 'danger' : 'secondary' ?>"><?= e($r['action']) ?></span></td>
    <td><?= e($r['table_name']) ?></td><td><?= e($r['record_id']) ?></td><td class="small"><?= e($r['details']) ?></td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No entries.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<div class="mt-3 d-flex justify-content-between align-items-center"><span class="small text-muted"><?= $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?>. The audit log can be read here but cannot be edited or deleted.</span><?= pagination_html($pg) ?></div>
<?php render_footer();
