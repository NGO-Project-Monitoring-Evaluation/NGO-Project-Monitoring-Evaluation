<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
$user = require_perm('view_finance_detail');
$donors = fetch_all("SELECT d.*, (SELECT COUNT(*) FROM grants g WHERE g.donor_id = d.donor_id) AS grants,
        (SELECT COALESCE(SUM(g.amount), 0) FROM grants g WHERE g.donor_id = d.donor_id) AS awarded,
        (SELECT COALESCE(SUM(r.amount), 0) FROM fund_receipts r JOIN grants g ON g.grant_id = r.grant_id WHERE g.donor_id = d.donor_id) AS received FROM donors d ORDER BY d.name");
$grants = fetch_all("SELECT g.*, d.name AS donor, p.title AS project_title, (SELECT COALESCE(SUM(r.amount), 0) FROM fund_receipts r WHERE r.grant_id = g.grant_id) AS received
        FROM grants g JOIN donors d ON d.donor_id = g.donor_id JOIN projects p ON p.project_id = g.project_id ORDER BY d.name, g.reference");
$receipts = fetch_all("SELECT r.*, g.reference AS grant_ref, d.name AS donor, u.full_name AS recorder FROM fund_receipts r JOIN grants g ON g.grant_id = r.grant_id JOIN donors d ON d.donor_id = g.donor_id LEFT JOIN users u ON u.user_id = r.recorded_by ORDER BY r.received_on DESC, r.receipt_id DESC LIMIT 15");
$manage = can('manage_funds');
render_header('Donors and funds', 'funds');
?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>Donors</span><?php if ($manage): ?><a class="small" href="donor_form.php">+ New donor</a><?php endif; ?></div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Donor</th><th>Type</th><th>Contact</th><th>Grants</th><th class="money">Awarded</th><th class="money">Received</th><th></th></tr></thead><tbody>
  <?php foreach ($donors as $d): ?><tr><td><strong><?= e($d['name']) ?></strong></td><td><?= e($d['donor_type']) ?></td><td class="small"><?= e($d['contact_person']) ?><?= $d['email'] ? '<br>' . e($d['email']) : '' ?></td><td><?= (int) $d['grants'] ?></td><td class="money"><?= e(money($d['awarded'])) ?></td><td class="money"><?= e(money($d['received'])) ?></td>
    <td class="no-print"><?php if ($manage): ?><a class="btn btn-sm btn-outline-secondary" href="donor_form.php?id=<?= (int) $d['donor_id'] ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$donors): ?><tr><td colspan="7" class="text-center text-muted py-3">No donors yet.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>Grants and funds received</span><?php if ($manage): ?><a class="small" href="grant_form.php">+ New grant</a><?php endif; ?></div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Reference</th><th>Donor</th><th>Project</th><th class="money">Grant</th><th class="money">Received</th><th class="money">Outstanding</th><th style="min-width:110px">Received</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($grants as $g): $pr = pct($g['received'], $g['amount']); ?><tr><td><strong><?= e($g['reference']) ?></strong></td><td><?= e($g['donor']) ?></td><td><?= e($g['project_title']) ?></td><td class="money"><?= e(money($g['amount'])) ?></td><td class="money"><?= e(money($g['received'])) ?></td><td class="money"><?= e(money((float) $g['amount'] - (float) $g['received'])) ?></td>
    <td><?= $pr === null ? '' : progress_bar(min(100, $pr), 'primary') ?></td><td><?= finance_status_badge($g['status']) ?></td>
    <td class="text-nowrap no-print"><?php if ($manage): ?><a class="btn btn-sm btn-outline-primary" href="receipt_form.php?grant_id=<?= (int) $g['grant_id'] ?>">Record receipt</a> <a class="btn btn-sm btn-outline-secondary" href="grant_form.php?id=<?= (int) $g['grant_id'] ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$grants): ?><tr><td colspan="9" class="text-center text-muted py-3">No grants yet.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><div class="card-header">Recent receipts</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Donor</th><th>Grant</th><th>Reference</th><th class="money">Amount</th><th>Recorded by</th></tr></thead><tbody>
  <?php foreach ($receipts as $r): ?><tr><td><?= e(fmt_date($r['received_on'])) ?></td><td><?= e($r['donor']) ?></td><td><?= e($r['grant_ref']) ?></td><td><?= e($r['reference']) ?></td><td class="money"><?= e(money($r['amount'])) ?></td><td class="small"><?= e($r['recorder']) ?></td></tr><?php endforeach; ?>
  <?php if (!$receipts): ?><tr><td colspan="6" class="text-center text-muted py-3">No receipts yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php render_footer();
