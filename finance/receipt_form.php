<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('manage_funds');
$gid = int_or_null(get('grant_id') ?: post('grant_id'));
$g = $gid ? fetch_one('SELECT g.*, d.name AS donor, p.title AS project_title, (SELECT COALESCE(SUM(r.amount), 0) FROM fund_receipts r WHERE r.grant_id = g.grant_id) AS received FROM grants g JOIN donors d ON d.donor_id = g.donor_id JOIN projects p ON p.project_id = g.project_id WHERE g.grant_id = ?', [$gid]) : null;
if (!$g) { not_found('grant'); }
$data = ['amount' => '', 'received_on' => today(), 'reference' => '']; $errors = [];
if (is_post()) {
    csrf_verify();
    $data = ['amount' => post('amount'), 'received_on' => post('received_on'), 'reference' => post('reference')];
    if (!valid_money($data['amount'])) $errors[] = 'The amount must be a positive number with at most two decimal places.';
    if (!valid_date($data['received_on']) || $data['received_on'] > today()) $errors[] = 'Enter a valid receipt date that is not in the future.';
    if (mb_strlen($data['reference']) > 60) $errors[] = 'The reference is too long.';
    if (!$errors && (float) $g['received'] + (float) $data['amount'] > (float) $g['amount'] + 0.004) $errors[] = 'This receipt would take the funds received above the grant amount (' . money($g['amount']) . '; already received ' . money($g['received']) . ').';
    if (!$errors) {
        q('INSERT INTO fund_receipts (grant_id, amount, received_on, reference, recorded_by) VALUES (?,?,?,?,?)', [$gid, $data['amount'], $data['received_on'], $data['reference'] ?: null, $user['user_id']]);
        audit('Create', 'fund_receipts', (int) db()->lastInsertId(), 'Funds received: ' . money($data['amount']) . ' for grant ' . $g['reference']);
        flash('success', 'The receipt was recorded.'); redirect('finance/funds.php');
    }
}
render_header('Record funds received', 'funds', [['Donors and funds', 'finance/funds.php'], ['Record funds received']]); show_errors($errors);
?>
<div class="card mb-3" style="max-width:700px"><div class="card-body small"><strong><?= e($g['reference']) ?></strong> &middot; <?= e($g['donor']) ?> &middot; <?= e($g['project_title']) ?><br>Grant <?= e(money($g['amount'])) ?>, received so far <?= e(money($g['received'])) ?>, outstanding <?= e(money((float) $g['amount'] - (float) $g['received'])) ?></div></div>
<div class="card" style="max-width:700px"><div class="card-body"><form method="post" novalidate><?= csrf_field() ?><input type="hidden" name="grant_id" value="<?= (int) $gid ?>"><div class="row g-3">
  <div class="col-md-4"><label class="form-label required" for="amount">Amount received (<?= e(cfg('currency')) ?>)</label><input class="form-control" id="amount" name="amount" inputmode="decimal" value="<?= e($data['amount']) ?>" required></div>
  <div class="col-md-4"><label class="form-label required" for="received_on">Date received</label><input class="form-control" type="date" id="received_on" name="received_on" max="<?= e(today()) ?>" value="<?= e($data['received_on']) ?>" required></div>
  <div class="col-md-4"><label class="form-label" for="reference">Bank or transfer reference</label><input class="form-control" id="reference" name="reference" maxlength="60" value="<?= e($data['reference']) ?>"></div>
</div><div class="mt-4"><button class="btn btn-primary" type="submit">Save receipt</button> <a class="btn btn-outline-secondary" href="funds.php">Cancel</a></div></form></div></div>
<?php render_footer();
