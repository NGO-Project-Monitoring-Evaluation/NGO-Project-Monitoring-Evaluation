<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_perm('record_expenditure');
if (!is_post()) { redirect('finance/commitments.php'); }
csrf_verify();
$id = int_or_null(post('id')); $action = post('action');
$c = $id ? fetch_one('SELECT * FROM commitments WHERE commitment_id = ?', [$id]) : null;
if (!$c || !in_array($action, ['pay', 'cancel'], true)) { flash('danger', 'That request is not valid.'); redirect('finance/commitments.php'); }
if ($action === 'cancel') {
    if (!in_array($c['status'], ['Pending approval', 'Approved'], true)) { flash('danger', 'Only a pending or approved purchase order can be cancelled.'); redirect('finance/commitments.php'); }
    q("UPDATE commitments SET status = 'Cancelled' WHERE commitment_id = ?", [$id]);
    audit('Update', 'commitments', $id, 'Purchase order ' . $c['po_number'] . ' cancelled; the committed funds were released.');
    flash('success', 'The purchase order was cancelled and its funds are available again.'); redirect('finance/commitments.php');
}
if ($c['status'] !== 'Approved') { flash('danger', 'Only an approved purchase order can be paid.'); redirect('finance/commitments.php'); }
$amount = post('amount', (string) $c['amount']);
if (!valid_money($amount)) { flash('danger', 'Enter the amount paid as a positive number.'); redirect('finance/commitments.php'); }
$pdo = db(); $pdo->beginTransaction();
q("INSERT INTO expenditures (line_id, spent_on, amount, payee, reference, description, status, commitment_id, submitted_by, approved_by, approved_at) VALUES (?,?,?,?,?,?, 'Approved', ?, ?, ?, NOW())",
    [$c['line_id'], today(), $amount, $c['vendor'], $c['po_number'], 'Payment against purchase order ' . $c['po_number'], $id, $user['user_id'], $c['approved_by']]);
$eid = (int) $pdo->lastInsertId();
q("UPDATE commitments SET status = 'Paid' WHERE commitment_id = ?", [$id]);
$pdo->commit();
audit('Create', 'expenditures', $eid, 'Payment of ' . money($amount) . ' against purchase order ' . $c['po_number'] . ' (approved earlier by the PO approver)');
flash('success', 'The payment was recorded. The commitment is now actual expenditure.');
redirect('finance/commitments.php');
