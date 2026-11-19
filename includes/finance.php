<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

/**
 * Financial calculations. Every figure is worked out from the stored records when a page is opened,
 * so the programme and finance teams always see the same, current numbers.
 *
 *  budget      = sum of the planned amounts of the budget lines
 *  received    = sum of the fund receipts recorded against the grants
 *  spent       = sum of APPROVED expenditures (pending and rejected ones do not count)
 *  committed   = sum of APPROVED purchase orders not yet paid (encumbrances)
 *  balance     = received - spent
 *  utilisation = spent / received x 100
 *  available   = planned - spent - committed   (for a budget line)
 */
const EXPENSE_CATEGORIES = ['Personnel', 'Travel', 'Materials and supplies', 'Equipment', 'Training and workshops', 'Administration', 'Other'];

function elapsed_fraction(?string $start, ?string $end): float
{
    if (!$start || !$end || !valid_date($start) || !valid_date($end) || $end <= $start) {
        return 0.0;
    }
    $t = today();
    if ($t <= $start) return 0.0;
    if ($t >= $end)   return 1.0;
    $total = (new DateTime($start))->diff(new DateTime($end))->days;
    $done  = (new DateTime($start))->diff(new DateTime($t))->days;
    return $total > 0 ? $done / $total : 1.0;
}

/** One row per project with its money figures and operational progress side by side. */
function finance_projects(?int $projectId = null): array
{
    [$sc, $sp] = project_scope('p.project_id');
    $w = '1 = 1'; $params = [];
    if ($projectId) { $w .= ' AND p.project_id = ?'; $params[] = $projectId; }
    $rows = fetch_all(
        "SELECT p.project_id, p.title, p.status, p.start_date, p.end_date,
            (SELECT COALESCE(AVG(a.progress_percent), 0) FROM activities a WHERE a.project_id = p.project_id) AS progress,
            (SELECT COALESCE(SUM(l.planned_amount), 0) FROM budget_lines l WHERE l.project_id = p.project_id) AS budget,
            (SELECT COALESCE(SUM(g.amount), 0) FROM grants g WHERE g.project_id = p.project_id AND g.status <> 'Pending') AS granted,
            (SELECT COALESCE(SUM(r.amount), 0) FROM fund_receipts r JOIN grants g ON g.grant_id = r.grant_id WHERE g.project_id = p.project_id) AS received,
            (SELECT COALESCE(SUM(e.amount), 0) FROM expenditures e JOIN budget_lines l ON l.line_id = e.line_id WHERE l.project_id = p.project_id AND e.status = 'Approved') AS spent,
            (SELECT COALESCE(SUM(c.amount), 0) FROM commitments c JOIN budget_lines l ON l.line_id = c.line_id WHERE l.project_id = p.project_id AND c.status = 'Approved') AS committed
           FROM projects p WHERE $w $sc ORDER BY p.title", array_merge($params, $sp));
    foreach ($rows as &$r) {
        foreach (['progress', 'budget', 'granted', 'received', 'spent', 'committed'] as $k) { $r[$k] = (float) $r[$k]; }
        $r['balance']    = $r['received'] - $r['spent'];
        $r['available']  = $r['received'] - $r['spent'] - $r['committed'];
        $r['utilisation'] = pct($r['spent'], $r['received']);          // spent as a share of the funds received
        $r['budget_spent_pct'] = pct($r['spent'], $r['budget']);       // spent as a share of the approved budget
        $r['progress'] = round($r['progress'], 1);
        $gap = $r['budget_spent_pct'] === null ? null : $r['budget_spent_pct'] - $r['progress'];
        $r['gap'] = $gap === null ? null : round($gap, 1);
        $r['alignment'] = $gap === null ? 'No budget' : ($gap >= 25 ? 'Spending ahead of progress' : ($gap <= -25 ? 'Progress ahead of spending' : 'Aligned'));
    }
    return $rows;
}

function finance_totals(array $rows): array
{
    $t = ['budget' => 0.0, 'granted' => 0.0, 'received' => 0.0, 'spent' => 0.0, 'committed' => 0.0];
    foreach ($rows as $r) { foreach ($t as $k => $_) { $t[$k] += $r[$k]; } }
    $t['balance'] = $t['received'] - $t['spent'];
    $t['available'] = $t['received'] - $t['spent'] - $t['committed'];
    $t['utilisation'] = pct($t['spent'], $t['received']);
    $t['budget_spent_pct'] = pct($t['spent'], $t['budget']);
    return $t;
}

/** Budget lines with actuals, commitments, availability and the cost-variance flag. */
function finance_lines(?int $projectId = null, ?string $category = null): array
{
    [$sc, $sp] = project_scope('l.project_id');
    $w = '1 = 1'; $params = [];
    if ($projectId) { $w .= ' AND l.project_id = ?'; $params[] = $projectId; }
    if ($category)  { $w .= ' AND l.category = ?'; $params[] = $category; }
    $rows = fetch_all(
        "SELECT l.*, p.title AS project_title, p.start_date AS p_start, p.end_date AS p_end,
                a.title AS activity_title, a.planned_start AS a_start, a.planned_end AS a_end, a.progress_percent AS a_progress,
                (SELECT COALESCE(SUM(e.amount), 0) FROM expenditures e WHERE e.line_id = l.line_id AND e.status = 'Approved') AS spent,
                (SELECT COALESCE(SUM(c.amount), 0) FROM commitments c WHERE c.line_id = l.line_id AND c.status = 'Approved') AS committed
           FROM budget_lines l JOIN projects p ON p.project_id = l.project_id LEFT JOIN activities a ON a.activity_id = l.activity_id
          WHERE $w $sc ORDER BY p.title, l.category, l.description", array_merge($params, $sp));
    $thr = (float) cfg('variance_threshold', 10);
    foreach ($rows as &$r) {
        $r['planned_amount'] = (float) $r['planned_amount']; $r['spent'] = (float) $r['spent']; $r['committed'] = (float) $r['committed'];
        $r['available'] = $r['planned_amount'] - $r['spent'] - $r['committed'];
        $r['pct_spent'] = pct($r['spent'], $r['planned_amount']);
        $useAct = $r['a_start'] && $r['a_end'];
        $frac = elapsed_fraction($useAct ? $r['a_start'] : $r['p_start'], $useAct ? $r['a_end'] : $r['p_end']);
        $r['expected'] = round($r['planned_amount'] * $frac, 2);
        $r['variance'] = round($r['expected'] - $r['spent'], 2);                // positive = underspent against the forecast to date
        $r['variance_pct'] = pct($r['variance'], $r['expected']);
        if ($r['spent'] > $r['planned_amount'] + 0.004)                    { $r['status'] = 'Overspent'; }
        elseif ($r['spent'] + $r['committed'] > $r['planned_amount'] + 0.004) { $r['status'] = 'Over-committed'; }
        elseif ($r['expected'] <= 0 && $r['spent'] > 0)                     { $r['status'] = 'Overspending to date'; }
        elseif ($r['expected'] > 0 && $r['spent'] > $r['expected'] * (1 + $thr / 100)) { $r['status'] = 'Overspending to date'; }
        elseif ($r['expected'] > 0 && $r['spent'] < $r['expected'] * (1 - $thr / 100)) { $r['status'] = 'Underspending to date'; }
        else                                                                 { $r['status'] = 'On track'; }
    }
    return $rows;
}

function finance_status_badge(string $status): string
{
    $map = ['Overspent' => 'danger', 'Over-committed' => 'danger', 'Overspending to date' => 'warning', 'Underspending to date' => 'info', 'On track' => 'success',
            'Pending approval' => 'warning', 'Approved' => 'success', 'Rejected' => 'danger', 'Paid' => 'primary', 'Cancelled' => 'secondary',
            'Pending' => 'warning', 'Active' => 'success', 'Closed' => 'secondary'];
    return '<span class="badge text-bg-' . ($map[$status] ?? 'secondary') . '">' . e($status) . '</span>';
}

/** Money still free on a budget line: planned - approved spend - approved purchase orders. */
function line_available(int $lineId, ?int $excludeCommitment = null): float
{
    $l = fetch_one('SELECT planned_amount FROM budget_lines WHERE line_id = ?', [$lineId]);
    if (!$l) { return 0.0; }
    $spent = (float) fetch_value("SELECT COALESCE(SUM(amount), 0) FROM expenditures WHERE line_id = ? AND status = 'Approved'", [$lineId]);
    $com = (float) fetch_value("SELECT COALESCE(SUM(amount), 0) FROM commitments WHERE line_id = ? AND status = 'Approved' AND commitment_id <> ?", [$lineId, $excludeCommitment ?? 0]);
    return (float) $l['planned_amount'] - $spent - $com;
}

function pending_approvals(): array
{
    return ['expenditures' => (int) fetch_value("SELECT COUNT(*) FROM expenditures WHERE status = 'Pending approval'"),
            'commitments'  => (int) fetch_value("SELECT COUNT(*) FROM commitments WHERE status = 'Pending approval'")];
}

/**
 * Fraud and unusual-expenditure alerts. Rules (all worked out live from the records):
 *  1 budget line overspent or over-committed, 2 spending running ahead of the forecast to date,
 *  3 possible duplicate payments (same payee and amount within 7 days, or the same reference),
 *  4 a single payment above 40% of its budget line or above 3 times the category average,
 *  5 round-sum payments of NGN 1,000,000 or more, 6 payments dated outside the project period,
 *  7 approvals waiting more than 7 days.
 */
function finance_alerts(?int $projectId = null): array
{
    [$sc, $sp] = project_scope('l.project_id');
    $pw = $projectId ? ' AND l.project_id = ' . (int) $projectId : '';
    $out = [];
    $add = function (string $level, string $type, string $msg, string $link) use (&$out) { $out[] = ['level' => $level, 'type' => $type, 'message' => $msg, 'link' => $link]; };

    foreach (finance_lines($projectId) as $l) {
        $name = $l['project_title'] . ' / ' . $l['category'] . ' / ' . $l['description'];
        $link = 'finance/budget.php?project=' . $l['project_id'];
        if ($l['status'] === 'Overspent')            { $add('danger', 'Overspent line', "$name: spent " . money($l['spent']) . ' against a budget of ' . money($l['planned_amount']) . '.', $link); }
        elseif ($l['status'] === 'Over-committed')   { $add('danger', 'Over-committed line', "$name: spent plus approved purchase orders (" . money($l['spent'] + $l['committed']) . ') exceed the budget of ' . money($l['planned_amount']) . '.', $link); }
        elseif ($l['status'] === 'Overspending to date') { $add('warning', 'Spending ahead of forecast', "$name: spent " . money($l['spent']) . ' but only ' . money($l['expected']) . ' was expected by now.', $link); }
    }
    $dups = fetch_all("SELECT a.expenditure_id AS a_id, b.expenditure_id AS b_id, a.payee, a.amount, a.spent_on AS a_date, b.spent_on AS b_date, a.reference AS a_ref, b.reference AS b_ref, l.project_id
                         FROM expenditures a JOIN expenditures b ON a.expenditure_id < b.expenditure_id
                         JOIN budget_lines l ON l.line_id = a.line_id
                        WHERE a.status <> 'Rejected' AND b.status <> 'Rejected'
                          AND ((LOWER(a.payee) = LOWER(b.payee) AND a.amount = b.amount AND ABS(DATEDIFF(a.spent_on, b.spent_on)) <= 7)
                               OR (a.reference IS NOT NULL AND a.reference <> '' AND a.reference = b.reference)) $pw $sc", $sp);
    foreach ($dups as $d) {
        $add('danger', 'Possible duplicate payment', 'Expenditures #' . $d['a_id'] . ' and #' . $d['b_id'] . ' to ' . $d['payee'] . ' look alike (' . money($d['amount']) . ', ' . fmt_date($d['a_date']) . ' and ' . fmt_date($d['b_date']) . ').', 'finance/expenditures.php?project=' . $d['project_id']);
    }
    $exp = fetch_all("SELECT e.expenditure_id, e.amount, e.payee, e.spent_on, e.status, e.created_at, l.category, l.planned_amount, l.project_id, p.start_date, p.end_date, p.title
                        FROM expenditures e JOIN budget_lines l ON l.line_id = e.line_id JOIN projects p ON p.project_id = l.project_id
                       WHERE e.status <> 'Rejected' $pw $sc", $sp);
    $byCat = [];
    foreach (fetch_all("SELECT l.category, e.amount FROM expenditures e JOIN budget_lines l ON l.line_id = e.line_id WHERE e.status <> 'Rejected'") as $r) { $byCat[$r['category']][] = (float) $r['amount']; }
    foreach ($exp as $e) {
        $amt = (float) $e['amount']; $link = 'finance/expenditures.php?project=' . $e['project_id'];
        $ref = 'Expenditure #' . $e['expenditure_id'] . ' to ' . $e['payee'] . ' (' . money($amt) . ')';
        $reasons = [];
        if ((float) $e['planned_amount'] > 0 && $amt > 0.4 * (float) $e['planned_amount']) { $reasons[] = 'above 40% of its budget line'; }
        $others = $byCat[$e['category']] ?? []; $n = count($others);
        if ($n >= 4) { $avg = (array_sum($others) - $amt) / ($n - 1); if ($avg > 0 && $amt >= 3 * $avg) { $reasons[] = 'at least 3 times the average ' . strtolower($e['category']) . ' payment (' . money($avg) . ')'; } }
        if ($reasons) { $add('warning', 'Unusually large payment', "$ref is " . implode(' and ', $reasons) . '.', $link); }
        if ($amt >= 1000000 && fmod($amt, 100000.0) == 0.0) { $add('info', 'Round-sum payment', "$ref is a round sum; check the supporting documents.", $link); }
        if ($e['spent_on'] < $e['start_date'] || $e['spent_on'] > date('Y-m-d', strtotime($e['end_date'] . ' +30 days'))) { $add('warning', 'Outside project period', "$ref is dated " . fmt_date($e['spent_on']) . ', outside the dates of ' . $e['title'] . '.', $link); }
        if ($e['status'] === 'Pending approval' && strtotime((string) $e['created_at']) < strtotime('-7 days')) { $add('warning', 'Approval overdue', "$ref has waited more than 7 days for approval.", 'finance/expenditures.php?status=Pending+approval'); }
    }
    foreach (fetch_all("SELECT c.po_number, c.amount, c.created_at, l.project_id FROM commitments c JOIN budget_lines l ON l.line_id = c.line_id WHERE c.status = 'Pending approval' $pw $sc", $sp) as $c) {
        if (strtotime((string) $c['created_at']) < strtotime('-7 days')) { $add('warning', 'Approval overdue', 'Purchase order ' . $c['po_number'] . ' (' . money($c['amount']) . ') has waited more than 7 days for approval.', 'finance/commitments.php?status=Pending+approval'); }
    }
    $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
    usort($out, fn($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);
    return $out;
}
