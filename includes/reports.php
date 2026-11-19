<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

const ALL_REPORT_TYPES = ['activity', 'indicator', 'beneficiary', 'bva', 'variance', 'commitments', 'funds'];

function report_types(): array
{
    $t = [];
    if (can('view_operations')) { $t['activity'] = 'Activity report'; $t['indicator'] = 'Indicator report'; }
    if (is_donor())             { $t['activity'] = 'Activity report'; $t['indicator'] = 'Indicator report'; }   // donors: assigned projects only
    if (can('beneficiary_report')) { $t['beneficiary'] = 'Beneficiary report'; }
    if (can('view_finance'))    { $t['bva'] = 'Budget versus actual (BVA) report'; $t['variance'] = 'Cost variance analysis'; $t['funds'] = 'Donor funds report'; }
    if (can('view_finance_detail')) { $t['commitments'] = 'Commitments report'; }
    return $t;
}

/** Read and check the report filters from the query string. */
function report_filters(): array
{
    $f = ['type' => get('type'), 'project' => int_or_null(get('project')), 'status' => get('status'), 'from' => get('from'), 'to' => get('to'), 'period' => get('period')];
    $errors = [];
    if (!in_array($f['type'], ALL_REPORT_TYPES, true)) { $errors[] = 'Choose a report type.'; }
    elseif (!isset(report_types()[$f['type']])) { $errors[] = 'Your role cannot run that report.'; }
    if ($f['project'] && !can_view_project($f['project'])) { $errors[] = 'That project is not assigned to you.'; }
    if ($f['from'] !== '' && !valid_date($f['from'])) { $errors[] = 'The "from" date is not valid.'; }
    if ($f['to'] !== '' && !valid_date($f['to']))     { $errors[] = 'The "to" date is not valid.'; }
    if ($f['from'] !== '' && $f['to'] !== '' && valid_date($f['from']) && valid_date($f['to']) && $f['to'] < $f['from']) { $errors[] = 'The "to" date cannot be before the "from" date.'; }
    if ($f['status'] !== '' && !in_array($f['status'], ['Not started', 'In progress', 'Completed', 'Delayed', 'Pending approval', 'Approved', 'Paid', 'Cancelled', 'Rejected'], true)) { $errors[] = 'The status is not valid.'; }
    if ($f['period'] !== '' && !preg_match('/^[0-9]{4}(-[0-9]{2}|-Q[1-4])?$/', $f['period'])) { $errors[] = 'The reporting period is not valid.'; }
    return [$f, $errors];
}

function report_filter_text(array $f): string
{
    $parts = ['Project: ' . ($f['project'] ? (fetch_value('SELECT title FROM projects WHERE project_id = ?', [$f['project']]) ?: 'unknown') : (is_donor() ? 'your assigned projects' : 'all projects'))];
    if (in_array($f['type'], ['activity', 'commitments'], true) && $f['status'] !== '') { $parts[] = 'Status: ' . $f['status']; }
    if ($f['type'] === 'indicator') { $parts[] = 'Period: ' . ($f['period'] !== '' ? $f['period'] : 'latest recorded'); }
    if (in_array($f['type'], ['activity', 'beneficiary'], true) && ($f['from'] !== '' || $f['to'] !== '')) {
        $parts[] = ($f['type'] === 'beneficiary' ? 'Registered: ' : 'Planned dates: ') . ($f['from'] !== '' ? fmt_date($f['from']) : 'start') . ' to ' . ($f['to'] !== '' ? fmt_date($f['to']) : 'today');
    }
    if (in_array($f['type'], ['bva', 'variance'], true)) { $parts[] = 'Figures as at ' . fmt_date(today()); }
    return implode('  |  ', $parts);
}

/** Build a report as plain data, used by the screen, the Excel file and the PDF file. */
function build_report(array $f): array
{
    $user = current_user();
    $base = ['generated' => date('d M Y, H:i'), 'by' => $user['full_name'], 'filters' => report_filter_text($f)];
    $cur = '(' . cfg('currency', '₦') . ')';

    if ($f['type'] === 'activity') {
        [$sc, $sp] = project_scope('a.project_id');
        $where = ['1=1']; $params = [];
        if ($f['project']) { $where[] = 'a.project_id = ?'; $params[] = $f['project']; }
        if ($f['status'] === 'Delayed') { $where[] = flagged_sql('a'); $params[] = today(); }
        elseif ($f['status'] !== '') {
            $where[] = 'a.status = ?'; $params[] = $f['status'];
            if ($f['status'] !== 'Completed') { $where[] = 'NOT ' . flagged_sql('a'); $params[] = today(); }
        }
        if ($f['from'] !== '') { $where[] = 'a.planned_end >= ?'; $params[] = $f['from']; }
        if ($f['to'] !== '')   { $where[] = 'a.planned_start <= ?'; $params[] = $f['to']; }
        $acts = fetch_all('SELECT a.*, p.title AS project_title, u.full_name AS assignee FROM activities a JOIN projects p ON p.project_id = a.project_id
                            LEFT JOIN users u ON u.user_id = a.assigned_to WHERE ' . implode(' AND ', $where) . $sc . ' ORDER BY p.title, a.planned_end', array_merge($params, $sp));
        $n = count($acts); $counts = ['Not started' => 0, 'In progress' => 0, 'Completed' => 0]; $flag = 0; $sum = 0; $rows = [];
        foreach ($acts as $a) {
            $isFlag = activity_is_flagged($a);
            if ($isFlag) { $flag++; } else { $counts[$a['status']]++; }
            $sum += (int) $a['progress_percent'];
            $label = $a['status'] . (($isFlag && $a['status'] !== 'Delayed') ? ' (overdue)' : '');
            $rows[] = [$a['project_title'], $a['title'], (string) $a['assignee'], fmt_date($a['planned_start']), fmt_date($a['planned_end']), $label, (int) $a['progress_percent'], $isFlag ? (string) ($a['delay_reason'] ?? '') : ''];
        }
        return $base + ['title' => 'Activity report',
            'summary' => [['Total activities', $n], ['Not started', $counts['Not started']], ['In progress', $counts['In progress']], ['Completed', $counts['Completed']], ['Delayed or overdue', $flag], ['Average progress (%)', $n ? round($sum / $n, 1) : 0]],
            'columns' => ['Project', 'Activity', 'Assigned to', 'Planned start', 'Planned end', 'Status', 'Progress (%)', 'Reason for delay'], 'rows' => $rows];
    }

    if ($f['type'] === 'indicator') {
        $inds = indicator_progress_rows($f['project'], $f['period'] !== '' ? $f['period'] : null);
        if (is_donor()) { $ids = donor_project_ids(); $inds = array_values(array_filter($inds, fn($i) => in_array((int) $i['project_id'], $ids, true))); }
        $rows = []; $withValue = 0; $reached = 0; $sum = 0; $calc = 0;
        foreach ($inds as $i) {
            $mt = $i['metrics'];
            if ($mt['has_value']) { $withValue++; }
            if ($mt['headline'] !== null) { $calc++; $sum += $mt['headline']; if ($mt['headline'] >= 100) { $reached++; } }
            $rows[] = [$i['project_title'], $i['name'], $i['unit'], (float) $i['baseline'], (float) $i['target'], $i['actual_value'] === null ? '' : (float) $i['actual_value'], (string) $i['value_period'],
                       ($mt['has_value'] && $mt['basis'] === 'of target') ? (float) $mt['of_target'] : '', $mt['since_baseline'] === null ? '' : (float) $mt['since_baseline'], $mt['has_value'] ? $mt['status'] : 'No value'];
        }
        return $base + ['title' => 'Indicator report',
            'summary' => [['Indicators', count($inds)], ['With a recorded value', $withValue], ['Target reached or exceeded', $reached], ['Average result (%)', $calc ? round($sum / $calc, 1) : 0]],
            'columns' => ['Project', 'Indicator', 'Unit', 'Baseline', 'Target', 'Actual', 'Period', '% of target', 'Progress from baseline (%)', 'Status'], 'rows' => $rows];
    }

    if ($f['type'] === 'beneficiary') {
        // Personal contact details are deliberately left out (data minimisation).
        $where = ['1=1']; $params = [];
        if ($f['project']) { $where[] = 'EXISTS (SELECT 1 FROM project_beneficiaries pb WHERE pb.beneficiary_id = b.beneficiary_id AND pb.project_id = ?)'; $params[] = $f['project']; }
        if ($f['from'] !== '') { $where[] = 'DATE(b.created_at) >= ?'; $params[] = $f['from']; }
        if ($f['to'] !== '')   { $where[] = 'DATE(b.created_at) <= ?'; $params[] = $f['to']; }
        $bens = fetch_all('SELECT b.*, (SELECT GROUP_CONCAT(p.title ORDER BY p.title SEPARATOR "; ") FROM project_beneficiaries pb JOIN projects p ON p.project_id = pb.project_id WHERE pb.beneficiary_id = b.beneficiary_id) AS project_titles
                             FROM beneficiaries b WHERE ' . implode(' AND ', $where) . ' ORDER BY b.beneficiary_id', $params);
        $sex = ['Female' => 0, 'Male' => 0, 'Other or not recorded' => 0]; $groups = ['0-4' => 0, '5-17' => 0, '18-35' => 0, '36-59' => 0, '60+' => 0, 'Not recorded' => 0]; $rows = [];
        foreach ($bens as $b) {
            $sex[$b['sex'] === 'Female' || $b['sex'] === 'Male' ? $b['sex'] : 'Other or not recorded']++;
            $g = age_group(age_from_dob($b['date_of_birth'])); $groups[$g]++;
            $rows[] = [$b['beneficiary_code'], $b['full_name'], (string) $b['sex'], $g, (string) $b['community'], (string) $b['state'], (string) $b['project_titles'], fmt_date($b['created_at'])];
        }
        $summary = [['Total beneficiaries', count($bens)]];
        foreach ($sex as $k => $v) { $summary[] = [$k, $v]; }
        foreach ($groups as $k => $v) { if ($v) { $summary[] = ['Age ' . $k, $v]; } }
        return $base + ['title' => 'Beneficiary report', 'summary' => $summary, 'columns' => ['Code', 'Name', 'Sex', 'Age group', 'Community', 'State', 'Projects', 'Registered on'], 'rows' => $rows];
    }

    if ($f['type'] === 'bva') {
        $lines = finance_lines($f['project']); $rows = []; $t = ['p' => 0.0, 's' => 0.0, 'c' => 0.0]; $over = 0; $overc = 0;
        foreach ($lines as $l) {
            $t['p'] += $l['planned_amount']; $t['s'] += $l['spent']; $t['c'] += $l['committed'];
            if ($l['status'] === 'Overspent') { $over++; } if ($l['status'] === 'Over-committed') { $overc++; }
            $rows[] = [$l['project_title'], $l['category'], $l['description'], $l['planned_amount'], $l['spent'], $l['committed'], $l['available'], $l['pct_spent'] ?? '', $l['status']];
        }
        return $base + ['title' => 'Budget versus actual (BVA) report',
            'summary' => [['Planned budget ' . $cur, $t['p']], ['Actual spend ' . $cur, $t['s']], ['Committed (approved purchase orders) ' . $cur, $t['c']], ['Available ' . $cur, $t['p'] - $t['s'] - $t['c']],
                          ['Budget spent (%)', pct($t['s'], $t['p']) ?? 0], ['Lines overspent', $over], ['Lines over-committed', $overc]],
            'columns' => ['Project', 'Category', 'Budget line', 'Planned ' . $cur, 'Actual ' . $cur, 'Committed ' . $cur, 'Available ' . $cur, 'Spent (%)', 'Status'], 'rows' => $rows];
    }

    if ($f['type'] === 'variance') {
        $lines = finance_lines($f['project']);
        usort($lines, fn($a, $b) => abs((float) ($b['variance_pct'] ?? 0)) <=> abs((float) ($a['variance_pct'] ?? 0)));
        $rows = []; $cnt = ['Overspent' => 0, 'Over-committed' => 0, 'Overspending to date' => 0, 'Underspending to date' => 0, 'On track' => 0]; $cat = [];
        foreach ($lines as $l) {
            $cnt[$l['status']]++; $cat[$l['category']] = ($cat[$l['category']] ?? 0) + $l['variance'];
            $rows[] = [$l['project_title'], $l['category'], $l['description'], $l['planned_amount'], $l['expected'], $l['spent'], $l['variance'], $l['variance_pct'] ?? '', $l['status']];
        }
        $summary = [['Budget lines analysed', count($lines)], ['Overspent (above full budget)', $cnt['Overspent']], ['Over-committed', $cnt['Over-committed']], ['Overspending to date', $cnt['Overspending to date']], ['Underspending to date', $cnt['Underspending to date']], ['On track', $cnt['On track']]];
        ksort($cat);
        foreach ($cat as $k => $v) { $summary[] = ['Variance to date: ' . $k . ' ' . $cur, round($v, 2)]; }
        return $base + ['title' => 'Cost variance analysis', 'summary' => $summary,
            'columns' => ['Project', 'Category', 'Budget line', 'Planned ' . $cur, 'Expected to date ' . $cur, 'Actual ' . $cur, 'Variance to date ' . $cur, 'Variance (%)', 'Flag'], 'rows' => $rows];
    }

    if ($f['type'] === 'commitments') {
        $where = ['1=1']; $params = [];
        if ($f['project']) { $where[] = 'l.project_id = ?'; $params[] = $f['project']; }
        if ($f['status'] !== '') { $where[] = 'c.status = ?'; $params[] = $f['status']; }
        [$sc, $sp] = project_scope('l.project_id');
        $list = fetch_all("SELECT c.*, l.description AS line_desc, p.title AS project_title, s.full_name AS submitter, a.full_name AS approver
                             FROM commitments c JOIN budget_lines l ON l.line_id = c.line_id JOIN projects p ON p.project_id = l.project_id
                             LEFT JOIN users s ON s.user_id = c.submitted_by LEFT JOIN users a ON a.user_id = c.approved_by
                            WHERE " . implode(' AND ', $where) . $sc . ' ORDER BY c.created_at DESC', array_merge($params, $sp));
        $tot = ['Approved' => 0.0, 'Pending approval' => 0.0, 'Paid' => 0.0]; $other = 0; $rows = [];
        foreach ($list as $c) {
            if (isset($tot[$c['status']])) { $tot[$c['status']] += (float) $c['amount']; } else { $other++; }
            $rows[] = [$c['po_number'], $c['project_title'], $c['line_desc'], $c['vendor'], (float) $c['amount'], $c['status'], (string) $c['submitter'], (string) $c['approver'], fmt_date($c['approved_at'])];
        }
        return $base + ['title' => 'Commitments report',
            'summary' => [['Committed: approved, not yet paid ' . $cur, $tot['Approved']], ['Waiting for approval ' . $cur, $tot['Pending approval']], ['Paid ' . $cur, $tot['Paid']], ['Cancelled or rejected', $other], ['Purchase orders listed', count($list)]],
            'columns' => ['PO number', 'Project', 'Budget line', 'Vendor', 'Amount ' . $cur, 'Status', 'Submitted by', 'Approved by', 'Approved on'], 'rows' => $rows];
    }

    // funds: donor fund tracking
    [$sc, $sp] = project_scope('g.project_id');
    $w = '1=1'; $params = [];
    if ($f['project']) { $w .= ' AND g.project_id = ?'; $params[] = $f['project']; }
    $grants = fetch_all("SELECT g.*, d.name AS donor, p.title AS project_title,
                (SELECT COALESCE(SUM(r.amount), 0) FROM fund_receipts r WHERE r.grant_id = g.grant_id) AS received
              FROM grants g JOIN donors d ON d.donor_id = g.donor_id JOIN projects p ON p.project_id = g.project_id WHERE $w $sc ORDER BY d.name, g.reference", array_merge($params, $sp));
    $rows = []; $ta = 0.0; $tr = 0.0;
    foreach ($grants as $g) {
        $ta += (float) $g['amount']; $tr += (float) $g['received'];
        $rows[] = [$g['donor'], $g['reference'], $g['project_title'], (float) $g['amount'], (float) $g['received'], (float) $g['amount'] - (float) $g['received'], pct($g['received'], $g['amount']) ?? '', $g['status']];
    }
    $proj = finance_totals(finance_projects($f['project']));
    return $base + ['title' => 'Donor funds report',
        'summary' => [['Grants awarded ' . $cur, $ta], ['Funds received ' . $cur, $tr], ['Outstanding ' . $cur, $ta - $tr], ['Expenditure approved ' . $cur, $proj['spent']], ['Project balance ' . $cur, $proj['balance']], ['Budget utilisation (spent / received, %)', $proj['utilisation'] ?? 0]],
        'columns' => ['Donor', 'Grant reference', 'Project', 'Grant amount ' . $cur, 'Received ' . $cur, 'Outstanding ' . $cur, 'Received (%)', 'Status'], 'rows' => $rows];
}
