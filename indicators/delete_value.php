<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_perm('define_indicators');
if (!is_post()) { redirect('indicators/index.php'); }
csrf_verify();
$id = int_or_null(post('id'));
$v = $id ? fetch_one('SELECT indicator_id, period_label FROM indicator_values WHERE value_id = ?', [$id]) : null;
if (!$v) { flash('danger', 'The recorded value could not be found.'); redirect('indicators/index.php'); }
q('DELETE FROM indicator_values WHERE value_id = ?', [$id]);
audit('Delete', 'indicator_values', $id, 'Deleted value for period ' . $v['period_label']);
flash('success', 'The recorded value was deleted.');
redirect('indicators/view.php?id=' . $v['indicator_id']);
