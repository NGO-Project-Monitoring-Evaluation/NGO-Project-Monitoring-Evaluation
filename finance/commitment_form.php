<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('record_expenditure');
$errors = []; $data = ['line_id' => int_or_null(get('line_id')), 'po_number' => '', 'vendor' => '', 'description' => '', 'amount' => ''];
$lines = finance_lines();
if (is_post()) {
    csrf_verify();
    $data = ['line_id' => post('line_id'), 'po_number' => strtoupper(post('po_number')), 'vendor' => post('vendor'), 'description' => post('description'), 'amount' => post('amount')];
    $lid = int_or_null($data['line_id']);
    $lineProject = $lid ? fetch_value('SELECT project_id FROM budget_lines WHERE line_id = ?', [$lid]) : null;
    if (!$lid || !$lineProject || !can_view_project((int) $lineProject)) $errors[] = 'Choose a budget line in a project assigned to you.';
    if ($data['po_number'] === '' || mb_strlen($data['po_number']) > 40) $errors[] = 'Enter the purchase order number (40 characters at most).';
    elseif (fetch_value('SELECT 1 FROM commitments WHERE po_number = ?', [$data['po_number']])) $errors[] = 'That purchase order number is already in use.';
    if ($data['vendor'] === '' || mb_strlen($data['vendor']) > 150) $errors[] = 'Enter the vendor (150 characters at most).';
    if (mb_strlen($data['description']) > 255) $errors[] = 'The description is too long.';
    if (!valid_money($data['amount'])) $errors[] = 'The amount must be a positive number with at most two decimal places.';
    if (!$errors) {
        $avail = line_available($lid);
        q("INSERT INTO commitments (line_id, po_number, vendor, description, amount, status, submitted_by) VALUES (?,?,?,?,?, 'Pending approval', ?)", [$lid, $data['po_number'], $data['vendor'], $data['description'] ?: null, $data['amount'], $user['user_id']]);
        audit('Create', 'commitments', (int) db()->lastInsertId(), 'Purchase order ' . $data['po_number'] . ' submitted: ' . money($data['amount']) . ' to ' . $data['vendor']);
        $over = (float) $data['amount'] > $avail;
        flash($over ? 'warning' : 'success', 'The purchase order was submitted for approval.' . ($over ? ' It is larger than the ' . money($avail) . ' available on the budget line and cannot be approved unless the budget changes.' : ''));
        redirect('finance/commitments.php?status=Pending+approval');
    }
}
render_header('New purchase order', 'commitments', [['Commitments', 'finance/commitments.php'], ['New purchase order']]);
show_errors($errors);
?>
<div class="card" style="max-width:760px"><div class="card-body"><form method="post" novalidate><?= csrf_field() ?><div class="row g-3">
  <div class="col-12"><label class="form-label required" for="line_id">Budget line</label><select class="form-select" id="line_id" name="line_id"><option value="">Select a budget line</option>
    <?php foreach ($lines as $l): ?><option value="<?= (int) $l['line_id'] ?>" <?= (string) $data['line_id'] === (string) $l['line_id'] ? 'selected' : '' ?>><?= e($l['project_title'] . ' / ' . $l['category'] . ' / ' . $l['description'] . '  (available ' . money($l['available']) . ')') ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label required" for="po_number">PO number</label><input class="form-control" id="po_number" name="po_number" maxlength="40" value="<?= e($data['po_number']) ?>" required></div>
  <div class="col-md-5"><label class="form-label required" for="vendor">Vendor</label><input class="form-control" id="vendor" name="vendor" maxlength="150" value="<?= e($data['vendor']) ?>" required></div>
  <div class="col-md-3"><label class="form-label required" for="amount">Amount (<?= e(cfg('currency')) ?>)</label><input class="form-control" id="amount" name="amount" inputmode="decimal" value="<?= e($data['amount']) ?>" required></div>
  <div class="col-12"><label class="form-label" for="description">Description</label><input class="form-control" id="description" name="description" maxlength="255" value="<?= e($data['description']) ?>"></div>
</div><div class="alert alert-light border mt-3 small mb-0">Once approved, the purchase order is a <strong>commitment</strong>: its amount is set aside and no longer counts as available on the budget line, even though no money has been paid yet. It can only be approved if the budget line has enough available funds.</div>
<div class="mt-4"><button class="btn btn-primary" type="submit">Submit for approval</button> <a class="btn btn-outline-secondary" href="commitments.php">Cancel</a></div></form></div></div>
<?php render_footer();
