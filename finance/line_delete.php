<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_perm('manage_budget');
if (!is_post()) { redirect('finance/budget.php'); }
csrf_verify();
$id = int_or_null(post('id'));
$l = $id ? fetch_one('SELECT description, project_id FROM budget_lines WHERE line_id = ?', [$id]) : null;
if (!$l) { flash('danger', 'The budget line could not be found.'); redirect('finance/budget.php'); }
$n = (int) fetch_value('SELECT (SELECT COUNT(*) FROM expenditures WHERE line_id = ?) + (SELECT COUNT(*) FROM commitments WHERE line_id = ?)', [$id, $id]);
if ($n) { flash('danger', "This budget line cannot be deleted because $n expenditure(s) or purchase order(s) refer to it."); redirect('finance/budget.php?project=' . $l['project_id']); }
q('DELETE FROM budget_lines WHERE line_id = ?', [$id]);
audit('Delete', 'budget_lines', $id, 'Budget line deleted: ' . $l['description']);
flash('success', 'The budget line was deleted.');
redirect('finance/budget.php?project=' . $l['project_id']);
