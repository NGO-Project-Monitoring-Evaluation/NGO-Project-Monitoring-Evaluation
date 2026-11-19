<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_perm('approve_finance');
if (!is_post()) { redirect('finance/expenditures.php'); }
csrf_verify();
$type = post('type'); $id = int_or_null(post('id')); $decision = post('decision'); $note = post('note');
$back = $type === 'commitment' ? 'finance/commitments.php' : 'finance/expenditures.php';
if (!in_array($type, ['expenditure', 'commitment'], true) || !$id || !in_array($decision, ['approve', 'reject'], true)) { flash('danger', 'That request is not valid.'); redirect($back); }
$table = $type === 'commitment' ? 'commitments' : 'expenditures'; $pk = $type === 'commitment' ? 'commitment_id' : 'expenditure_id';
$row = fetch_one("SELECT * FROM $table WHERE $pk = ?", [$id]);
if (!$row || $row['status'] !== 'Pending approval') { flash('danger', 'That item is not waiting for approval.'); redirect($back); }
if ((int) $row['submitted_by'] === (int) $user['user_id']) { flash('danger', 'You cannot approve or reject something you submitted yourself. Another approver must decide.'); redirect($back); }
if ($decision === 'reject') {
    if ($note === '' || mb_strlen($note) > 255) { flash('danger', 'Give a reason for the rejection (255 characters at most).'); redirect($back); }
    q("UPDATE $table SET status = 'Rejected', approved_by = ?, approved_at = NOW(), decision_note = ? WHERE $pk = ?", [$user['user_id'], $note, $id]);
    audit('Reject', $table, $id, ucfirst($type) . ' rejected: ' . $note);
    flash('success', 'The ' . $type . ' was rejected.'); redirect($back);
}
if ($type === 'commitment') {
    $avail = line_available((int) $row['line_id'], $id);
    if ((float) $row['amount'] > $avail + 0.004) { flash('danger', 'This purchase order cannot be approved: it is ' . money($row['amount']) . ' but only ' . money($avail) . ' is available on the budget line.'); redirect($back); }
}
q("UPDATE $table SET status = 'Approved', approved_by = ?, approved_at = NOW(), decision_note = ? WHERE $pk = ?", [$user['user_id'], $note ?: null, $id]);
audit('Approve', $table, $id, ucfirst($type) . ' approved: ' . money($row['amount']));
flash('success', 'The ' . $type . ' was approved.' . ($type === 'commitment' ? ' Its amount is now committed and reduces the available funds.' : ''));
redirect($back);
