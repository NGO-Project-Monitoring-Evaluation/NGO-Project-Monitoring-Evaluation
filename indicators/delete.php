<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_perm('define_indicators');
if (!is_post()) { redirect('indicators/index.php'); }
csrf_verify();
$id = int_or_null(post('id'));
$i = $id ? fetch_one('SELECT name, project_id FROM indicators WHERE indicator_id = ?', [$id]) : null;
if (!$i) { flash('danger', 'The indicator could not be found.'); redirect('indicators/index.php'); }
if (!can_view_project((int)$i['project_id'])) { deny_access(); }
$n = (int) fetch_value('SELECT COUNT(*) FROM indicator_values WHERE indicator_id = ?', [$id]);
if ($n) {
    flash('danger', "This indicator cannot be deleted because $n recorded value(s) depend on it. Delete the recorded values first.");
    redirect('indicators/view.php?id=' . $id);
}
q('DELETE FROM indicators WHERE indicator_id = ?', [$id]);
audit('Delete', 'indicators', $id, 'Deleted indicator: ' . $i['name']);
flash('success', 'The indicator was deleted.');
redirect('indicators/index.php');
