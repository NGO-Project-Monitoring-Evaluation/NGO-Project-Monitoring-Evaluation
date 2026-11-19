<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
$user = require_login();

$ops = can('view_operations'); $fin = can('view_finance'); $detail = can('view_finance_detail');
$mine = is_officer() ? ' AND a.assigned_to = ' . (int) $user['user_id'] : '';
[$psc, $psp] = project_scope('p.project_id');
$pc = fetch_one("SELECT COALESCE(SUM(p.status = 'Ongoing'), 0) AS active, COALESCE(SUM(p.status = 'Completed'), 0) AS done FROM projects p WHERE 1 = 1 $psc", $psp);
$activeProjects = (int) $pc['active']; $completedProjects = (int) $pc['done'];
$beneficiaries = can('view_beneficiaries') ? (int) fetch_value('SELECT COUNT(*) FROM beneficiaries') : null;

$inProgress = $flagged = 0; $followUp = [];
if ($ops) {
    $inProgress = (int) fetch_value("SELECT COUNT(*) FROM activities a WHERE a.status = 'In progress'$mine");
    $flagged    = (int) fetch_value("SELECT COUNT(*) FROM activities a WHERE a.status <> 'Completed' AND (a.status = 'Delayed' OR a.planned_end < ?)$mine", [today()]);
    $followUp   = fetch_all("SELECT a.*, p.title AS project_title FROM activities a JOIN projects p ON p.project_id = a.project_id
                              WHERE a.status <> 'Completed' AND (a.status = 'Delayed' OR a.planned_end < ?)$mine ORDER BY a.planned_end IS NULL, a.planned_end LIMIT 10", [today()]);
}
$fp = $ft = $byCat = $alerts = []; $pend = null;
if ($fin) {
    $fp = finance_projects(); $ft = finance_totals($fp);
    $byCat = fetch_all("SELECT l.category, SUM(e.amount) AS total FROM expenditures e JOIN budget_lines l ON l.line_id = e.line_id JOIN projects p ON p.project_id = l.project_id
                         WHERE e.status = 'Approved' $psc GROUP BY l.category ORDER BY total DESC", $psp);
    if ($detail) { $alerts = finance_alerts(); $pend = pending_approvals(); }
}
$indicators = [];
if ($ops || is_donor()) {
    $indicators = indicator_progress_rows(null, null, 'Ongoing');
    if (is_donor()) { $ids = donor_project_ids(); $indicators = array_values(array_filter($indicators, fn($i) => in_array((int) $i['project_id'], $ids, true))); }
    usort($indicators, fn($a, $b) => ($a['progress'] ?? 999) <=> ($b['progress'] ?? 999));
    $indicators = array_slice($indicators, 0, 8);
}

render_header('Dashboard', 'dashboard');
$cards = [['Active projects', (string) $activeProjects, ''], ['Completed projects', (string) $completedProjects, '']];
if ($beneficiaries !== null) { $cards[] = ['Total beneficiaries', number_format($beneficiaries), '']; }
if ($fin) {
    $cards[] = ['Total approved budget', money_short($ft['budget']), money($ft['budget'])];
    $cards[] = ['Total funds received', money_short($ft['received']), 'Awarded: ' . money_short($ft['granted'])];
    $cards[] = ['Total expenditure', money_short($ft['spent']), $ft['budget_spent_pct'] === null ? '' : fmt_number($ft['budget_spent_pct']) . '% of the approved budget'];
    $cards[] = ['Available project balance', money_short($ft['balance']), 'Funds received less expenditure'];
    $cards[] = ['Budget utilisation', $ft['utilisation'] === null ? '-' : fmt_number($ft['utilisation']) . '%', 'Expenditure / funds received'];
}
if ($ops) {
    $cards[] = ['Activities in progress' . (is_officer() ? ' (yours)' : ''), (string) $inProgress, ''];
    $cards[] = ['Delayed or overdue' . (is_officer() ? ' (yours)' : ''), (string) $flagged, 'Need follow-up'];
}
?>
<div class="row g-3 mb-3">
<?php foreach ($cards as [$l, $v, $s]): ?>
  <div class="col-6 col-xl-3"><div class="card kpi h-100"><div class="card-body"><div class="text-muted small"><?= e($l) ?></div><div class="value <?= $l === 'Delayed or overdue' && (int) $v > 0 ? 'text-danger' : '' ?>"><?= e($v) ?></div><div class="sub"><?= e($s) ?></div></div></div></div>
<?php endforeach; ?>
</div>

<?php if ($ops): ?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>Activities needing follow-up</span><span class="small text-muted">Delayed, or past the planned end date</span></div>
  <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
    <thead><tr><th>Activity</th><th>Project</th><th>Due</th><th>Status</th><th>Reason for delay</th><th></th></tr></thead><tbody>
    <?php foreach ($followUp as $a): $late = days_overdue($a['planned_end']); ?>
      <tr class="table-flagged">
        <td><?php if (can_update_activity($a)): ?><a href="<?= e(url('activities/update.php?id=' . $a['activity_id'] . '&from=dashboard')) ?>"><?= e($a['title']) ?></a><?php else: ?><?= e($a['title']) ?><?php endif; ?></td>
        <td><?= e($a['project_title']) ?></td>
        <td class="text-nowrap"><?= e(fmt_date($a['planned_end'])) ?><?= $late ? '<div class="small text-danger">' . $late . ' day' . ($late === 1 ? '' : 's') . ' overdue</div>' : '' ?></td>
        <td><?= activity_status_badge($a) ?></td>
        <td style="min-width:14rem"><?php if (!empty($a['delay_reason'])): ?><div class="reason-text"><?= e(mb_strimwidth($a['delay_reason'], 0, 110, '...')) ?></div><div class="small text-muted">Recorded <?= e(fmt_date($a['delay_reason_at'])) ?></div>
          <?php else: ?><span class="text-danger small fw-semibold">No reason recorded</span><?php endif; ?></td>
        <td class="text-nowrap no-print"><?php if (can_update_activity($a)): ?><a class="btn btn-sm <?= empty($a['delay_reason']) ? 'btn-danger' : 'btn-outline-secondary' ?>" href="<?= e(url('activities/update.php?id=' . $a['activity_id'] . '&from=dashboard#delay')) ?>"><?= empty($a['delay_reason']) ? 'Record reason' : 'Edit reason' ?></a><?php endif; ?></td></tr>
    <?php endforeach; if (!$followUp): ?><tr><td colspan="6" class="text-muted text-center py-3">Nothing needs follow-up right now.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php endif; ?>

<?php if ($fin): ?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>Field progress and financial records side by side</span>
  <a class="small" href="<?= e(url('finance/index.php')) ?>">Finance overview</a></div>
  <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
    <thead><tr><th>Project</th><th style="min-width:9rem">Activity progress</th><th style="min-width:9rem">Budget spent</th><th class="money">Funds received</th><th class="money">Spent</th><th class="money">Committed</th><th>Check</th></tr></thead><tbody>
    <?php foreach ($fp as $p): ?>
      <tr><td><a href="<?= e(url('projects/view.php?id=' . $p['project_id'])) ?>"><?= e($p['title']) ?></a></td>
        <td><?= progress_bar($p['progress'], progress_class($p['progress'])) ?></td>
        <td><?= $p['budget_spent_pct'] === null ? '<span class="small text-muted">No budget</span>' : progress_bar(min(100, $p['budget_spent_pct']), $p['budget_spent_pct'] > 100 ? 'danger' : 'primary') ?></td>
        <td class="money"><?= e(money_short($p['received'])) ?></td><td class="money"><?= e(money_short($p['spent'])) ?></td><td class="money"><?= e(money_short($p['committed'])) ?></td>
        <td><span class="badge text-bg-<?= $p['alignment'] === 'Aligned' ? 'success' : ($p['alignment'] === 'No budget' ? 'secondary' : 'warning') ?>"><?= e($p['alignment']) ?></span></td></tr>
    <?php endforeach; if (!$fp): ?><tr><td colspan="7" class="text-muted text-center py-3">No projects yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>

<div class="row g-3 mb-3">
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Expenditure by category</div><div class="card-body chart-box"><?= chart_donut(array_map(fn($r) => [$r['category'], (float) $r['total']], $byCat), money_short($ft['spent'])) ?></div></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Funds received and expenditure</div><div class="card-body chart-box"><?= chart_pair_bars(array_map(fn($p) => [$p['title'], $p['received'], $p['spent']], $fp), 'Funds received', 'Expenditure') ?></div></div></div>
  <?php if ($detail): ?>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header d-flex justify-content-between"><span>Financial alerts and approvals</span><a class="small" href="<?= e(url('finance/alerts.php')) ?>">All alerts</a></div><div class="card-body">
    <div class="d-flex gap-2 mb-2 flex-wrap">
      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('finance/expenditures.php?status=Pending+approval')) ?>">Expenditures waiting: <strong><?= (int) $pend['expenditures'] ?></strong></a>
      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('finance/commitments.php?status=Pending+approval')) ?>">Purchase orders waiting: <strong><?= (int) $pend['commitments'] ?></strong></a></div>
    <?php foreach (array_slice($alerts, 0, 5) as $al): ?>
      <div class="small mb-2"><span class="badge text-bg-<?= e($al['level']) ?>"><?= e($al['type']) ?></span> <?= e(mb_strimwidth($al['message'], 0, 120, '...')) ?></div>
    <?php endforeach; if (!$alerts): ?><p class="small text-muted mb-0">No alerts. Spending is within the expected limits.</p><?php endif; ?>
    <?php if (count($alerts) > 5): ?><a class="small" href="<?= e(url('finance/alerts.php')) ?>"><?= count($alerts) - 5 ?> more alert(s)</a><?php endif; ?>
  </div></div></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($indicators || $ops): ?>
<div class="card"><div class="card-header d-flex justify-content-between"><span>Indicator progress (active projects)</span><?php if ($ops): ?><a class="small" href="<?= e(url('indicators/index.php')) ?>">View all indicators</a><?php endif; ?></div>
  <div class="card-body"><div class="row g-3">
    <?php foreach ($indicators as $i): ?>
      <div class="col-lg-6"><div class="d-flex justify-content-between small"><span><strong><?= e($i['name']) ?></strong> <span class="text-muted">&middot; <?= e($i['project_title']) ?></span></span>
        <span class="text-muted"><?= $i['actual_value'] !== null ? e(fmt_number($i['actual_value'])) . ' of ' . e(fmt_with_unit($i['target'], $i['unit'])) : 'No value yet' ?></span></div>
        <?= indicator_progress_html($i['metrics']) ?></div>
    <?php endforeach; if (!$indicators): ?><p class="text-muted mb-0">No indicators have been defined for active projects yet.</p><?php endif; ?>
  </div></div></div>
<?php endif; ?>
<?php render_footer();
