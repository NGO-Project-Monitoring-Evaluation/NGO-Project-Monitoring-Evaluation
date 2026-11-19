<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('record_expenditure');
$errors = []; $data = ['line_id' => int_or_null(get('line_id')), 'spent_on' => today(), 'amount' => '', 'payee' => '', 'reference' => '', 'description' => ''];
$lines = finance_lines();
if (is_post()) {
    csrf_verify();
    $data = ['line_id' => post('line_id'), 'spent_on' => post('spent_on'), 'amount' => post('amount'), 'payee' => post('payee'), 'reference' => post('reference'), 'description' => post('description')];
    $lid = int_or_null($data['line_id']);
    if (!$lid || !fetch_value('SELECT 1 FROM budget_lines WHERE line_id = ?', [$lid])) $errors[] = 'Choose the budget line.';
    if (!valid_date($data['spent_on']) || $data['spent_on'] > today()) $errors[] = 'Enter a valid payment date that is not in the future.';
    if (!valid_money($data['amount'])) $errors[] = 'The amount must be a positive number with at most two decimal places.';
    if ($data['payee'] === '' || mb_strlen($data['payee']) > 150) $errors[] = 'Enter the payee (150 characters at most).';
    if (mb_strlen($data['reference']) > 60 || mb_strlen($data['description']) > 255) $errors[] = 'The reference or description is too long.';
    if (!$errors) {
        $avail = line_available($lid);
        q("INSERT INTO expenditures (line_id, spent_on, amount, payee, reference, description, status, submitted_by) VALUES (?,?,?,?,?,?, 'Pending approval', ?)",
            [$lid, $data['spent_on'], $data['amount'], $data['payee'], $data['reference'] ?: null, $data['description'] ?: null, $user['user_id']]);
        $newId = (int) db()->lastInsertId();
        audit('Create', 'expenditures', $newId, 'Expenditure submitted: ' . money($data['amount']) . ' to ' . $data['payee'] . ' (pending approval)');
        $over = (float) $data['amount'] > $avail;
        flash($over ? 'warning' : 'success', 'The expenditure was submitted for approval.' . ($over ? ' Note: it is larger than the ' . money($avail) . ' still available on this budget line.' : ''));
        redirect('finance/expenditures.php?status=Pending+approval');
    }
}
render_header('Record expenditure', 'expenditures', [['Expenditures', 'finance/expenditures.php'], ['Record expenditure']]);
show_errors($errors);
?>
<div class="card" style="max-width:760px"><div class="card-body"><form method="post" novalidate><?= csrf_field() ?><div class="row g-3">
  <div class="col-12"><label class="form-label required" for="line_id">Budget line</label><select class="form-select" id="line_id" name="line_id"><option value="">Select a budget line</option>
    <?php foreach ($lines as $l): ?><option value="<?= (int) $l['line_id'] ?>" <?= (string) $data['line_id'] === (string) $l['line_id'] ? 'selected' : '' ?>><?= e($l['project_title'] . ' / ' . $l['category'] . ' / ' . $l['description'] . '  (available ' . money($l['available']) . ')') ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label required" for="spent_on">Payment date</label><input class="form-control" type="date" id="spent_on" name="spent_on" max="<?= e(today()) ?>" value="<?= e($data['spent_on']) ?>" required></div>
  <div class="col-md-4"><label class="form-label required" for="amount">Amount (<?= e(cfg('currency')) ?>)</label><input class="form-control" id="amount" name="amount" inputmode="decimal" value="<?= e($data['amount']) ?>" required></div>
  <div class="col-md-4"><label class="form-label" for="reference">Voucher or receipt number</label><input class="form-control" id="reference" name="reference" maxlength="60" value="<?= e($data['reference']) ?>"></div>
  <div class="col-md-6"><label class="form-label required" for="payee">Payee</label><input class="form-control" id="payee" name="payee" maxlength="150" value="<?= e($data['payee']) ?>" required></div>
  <div class="col-md-6"><label class="form-label" for="description">Description</label><input class="form-control" id="description" name="description" maxlength="255" value="<?= e($data['description']) ?>"></div>
</div><div class="alert alert-light border mt-3 small mb-0">The expenditure is saved as <strong>Pending approval</strong>. It counts as actual spending only after the Executive Director or Super Administrator approves it, and the person who submits it cannot approve it.</div>
<div class="mt-4"><button class="btn btn-primary" type="submit">Submit for approval</button> <a class="btn btn-outline-secondary" href="expenditures.php">Cancel</a></div></form></div></div>
<?php render_footer();
