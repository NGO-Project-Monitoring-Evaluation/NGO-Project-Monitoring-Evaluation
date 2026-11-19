<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

/**
 * Optional sample data for demonstrations and testing. Loaded only when ticked in the installer.
 * All names and amounts are invented. Dates are set relative to today so that some activities are overdue
 * and some approvals are waiting.
 */
function demo_password(): string
{
    return 'Demo@1234';
}

function quarter_label(DateTime $d): string
{
    return $d->format('Y') . '-Q' . (int) ceil((int) $d->format('n') / 3);
}

function load_demo_data(int $adminId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $hash = password_hash(demo_password(), PASSWORD_DEFAULT);
        $mkUser = function (string $name, string $email, int $role) use ($hash, $pdo): int {
            q('INSERT INTO users (full_name, email, password_hash, role_id) VALUES (?,?,?,?)', [$name, $email, $hash, $role]);
            return (int) $pdo->lastInsertId();
        };
        $pm  = $mkUser('Amina Yusuf', 'manager@demo.org', 2);
        $of  = $mkUser('Chidi Okafor', 'officer@demo.org', 3);
        $ed  = $mkUser('Dr Ngozi Eze', 'director@demo.org', 4);
        $fo  = $mkUser('Tunde Balogun', 'finance@demo.org', 5);
        $me  = $mkUser('Hauwa Sani', 'mande@demo.org', 6);
        $au  = $mkUser('Ibrahim Danladi', 'auditor@demo.org', 7);
        $dn  = $mkUser('Grace Adeyemi (Hope Foundation)', 'donor@demo.org', 8);

        $d  = fn(int $days) => date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));
        $dt = fn(int $days) => date('Y-m-d H:i:s', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));

        $mkProject = function (string $title, string $desc, string $loc, int $s, int $e, string $st) use ($d, $pm, $adminId): int {
            q('INSERT INTO projects (title, description, location, start_date, end_date, status, manager_id, created_by) VALUES (?,?,?,?,?,?,?,?)', [$title, $desc, $loc, $d($s), $d($e), $st, $pm, $adminId]);
            return (int) db()->lastInsertId();
        };
        $water = $mkProject('Community Water and Sanitation Project', 'Provides safe drinking water and hygiene education to rural communities.', 'Kuje and Gwagwalada Area Councils, FCT', -240, 120, 'Ongoing');
        $edu   = $mkProject('Girls\' Education Support Project', 'Helps girls enrol in school and stay in school through learning kits and mentoring.', 'Bwari Area Council, FCT', -150, 200, 'Ongoing');
        q('INSERT INTO user_projects (user_id, project_id) VALUES (?, ?)', [$dn, $water]);

        $act = [];
        $mkAct = function (int $p, string $title, int $who, int $s, int $e, string $st, int $pr, ?string $notes = null) use ($d, &$act): void {
            q('INSERT INTO activities (project_id, title, assigned_to, planned_start, planned_end, status, progress_percent, completion_notes, updated_at) VALUES (?,?,?,?,?,?,?,?,NOW())', [$p, $title, $who, $d($s), $d($e), $st, $pr, $notes]);
            $act[$title] = (int) db()->lastInsertId();
        };
        $mkAct($water, 'Community needs assessment', $of, -240, -200, 'Completed', 100, 'Assessment report shared with community leaders.');
        $mkAct($water, 'Borehole site surveys', $of, -200, -150, 'Completed', 100, 'Fourteen sites surveyed and approved.');
        $mkAct($water, 'Drill boreholes (phase 1)', $of, -150, 20, 'In progress', 70);
        $mkAct($water, 'Hygiene education sessions', $of, -120, 45, 'In progress', 55);
        $mkAct($water, 'Install hand pumps (phase 1)', $of, -100, -10, 'In progress', 40, 'Waiting for the second delivery of pumps.');
        $mkAct($water, 'Procure drilling materials', $pm, -90, -20, 'Delayed', 60, 'Supplier delay reported.');
        q("UPDATE activities SET delay_reason = 'The supplier delivered the drilling materials two weeks late.', delay_reason_at = ? WHERE activity_id = ?", [$dt(-12), $act['Procure drilling materials']]);
        $mkAct($water, 'Water committee training', $of, 10, 60, 'Not started', 0);
        $mkAct($edu, 'Enrol girls in school', $of, -150, -60, 'Completed', 100, 'Enrolment target met in all communities.');
        $mkAct($edu, 'Distribute learning kits', $of, -90, 30, 'In progress', 50);
        $mkAct($edu, 'Mentoring sessions', $pm, 15, 150, 'Not started', 0);

        $mkInd = function (int $p, string $name, string $unit, float $base, float $target, string $freq): int {
            q('INSERT INTO indicators (project_id, name, unit, baseline, target, reporting_period) VALUES (?,?,?,?,?,?)', [$p, $name, $unit, $base, $target, $freq]);
            return (int) db()->lastInsertId();
        };
        $mkVal = function (int $ind, string $label, float $v) use ($me): void {
            q('INSERT INTO indicator_values (indicator_id, period_label, actual_value, recorded_by) VALUES (?,?,?,?)', [$ind, $label, $v, $me]);
        };
        $q = []; for ($k = 2; $k >= 0; $k--) { $q[] = quarter_label(new DateTime('-' . ($k * 3) . ' months')); }
        $m = []; for ($k = 2; $k >= 0; $k--) { $m[] = (new DateTime('first day of this month'))->modify("-$k months")->format('Y-m'); }
        $i = $mkInd($water, 'Boreholes completed', 'boreholes', 0, 20, 'Quarterly'); foreach ([4, 9, 14] as $n => $v) { $mkVal($i, $q[$n], $v); }
        $i = $mkInd($water, 'Hygiene sessions held', 'sessions', 0, 60, 'Monthly'); foreach ([12, 27, 46] as $n => $v) { $mkVal($i, $m[$n], $v); }
        $i = $mkInd($water, 'Households with access to safe water', 'households', 35, 80, 'Quarterly'); foreach ([44, 52, 62] as $n => $v) { $mkVal($i, $q[$n], $v); }
        $i = $mkInd($water, 'Women in water committee leadership', '%', 0, 40, 'Annual'); $mkVal($i, date('Y'), 46);
        $i = $mkInd($edu, 'Girls enrolled in school', 'girls', 0, 150, 'Quarterly'); foreach ([60, 95, 120] as $n => $v) { $mkVal($i, $q[$n], $v); }
        $i = $mkInd($edu, 'Learning kits distributed', 'kits', 0, 200, 'Quarterly'); foreach ([50, 80] as $n => $v) { $mkVal($i, $q[$n + 1], $v); }

        $first = ['Fatima', 'Ibrahim', 'Grace', 'Musa', 'Blessing', 'Abdullahi', 'Ngozi', 'Samuel', 'Aisha', 'Emmanuel', 'Hauwa', 'Peter', 'Zainab', 'Joseph', 'Rose', 'Sani', 'Mary', 'Danjuma', 'Esther', 'Yakubu'];
        $last  = ['Abubakar', 'Okoro', 'Danladi', 'Bello', 'Adeyemi', 'Garba', 'Eze', 'Lawal', 'Musa', 'Ojo'];
        $comm  = ['Kuje', 'Gwagwalada', 'Bwari', 'Abaji', 'Kwali', 'Dutse Alhaji'];
        $n = 0;
        foreach ($first as $fi => $fn) {
            for ($li = 0; $li < 2; $li++) {
                $name = $fn . ' ' . $last[($fi * 3 + $li * 4) % count($last)];
                $sex = in_array($fn, ['Fatima', 'Grace', 'Blessing', 'Ngozi', 'Aisha', 'Hauwa', 'Zainab', 'Rose', 'Mary', 'Esther'], true) ? 'Female' : 'Male';
                $dob = date('Y-m-d', strtotime('-' . (4 + (($n * 7) % 56)) . ' years -' . (($n * 29) % 300) . ' days'));
                $phone = '0803' . str_pad((string) (1000000 + $n * 7919), 7, '0', STR_PAD_LEFT);
                q('INSERT INTO beneficiaries (beneficiary_code, full_name, sex, date_of_birth, phone, community, state, consent_given, consent_date, registered_by) VALUES (?,?,?,?,?,?,?,1,?,?)',
                    ['T' . bin2hex(random_bytes(5)), $name, $sex, $dob, $phone, $comm[$n % count($comm)], 'Federal Capital Territory', $d(-100 + $n), $of]);
                $bid = (int) $pdo->lastInsertId();
                q('UPDATE beneficiaries SET beneficiary_code = ? WHERE beneficiary_id = ?', [beneficiary_code_from_id($bid), $bid]);
                if ($n % 4 !== 3) { q('INSERT INTO project_beneficiaries (project_id, beneficiary_id, enrolled_on) VALUES (?,?,?)', [$water, $bid, $d(-90 + $n)]); }
                if ($sex === 'Female' || $n % 5 === 0) { q('INSERT INTO project_beneficiaries (project_id, beneficiary_id, enrolled_on) VALUES (?,?,?)', [$edu, $bid, $d(-60 + $n)]); }
                $n++;
            }
        }

        // ---------------- donors, grants and receipts ----------------
        $mkDonor = function (string $name, string $type, string $contact, string $email) use ($pdo): int {
            q('INSERT INTO donors (name, donor_type, contact_person, email) VALUES (?,?,?,?)', [$name, $type, $contact, $email]);
            return (int) $pdo->lastInsertId();
        };
        $d1 = $mkDonor('Nigeria Water Trust Fund', 'Government', 'Mr Bala Mohammed', 'grants@nwtf.example.org');
        $d2 = $mkDonor('Hope Foundation', 'Foundation', 'Mrs Grace Adeyemi', 'programmes@hope.example.org');
        $d3 = $mkDonor('Brightfuture Education Fund', 'Foundation', 'Dr Sade Coker', 'info@brightfuture.example.org');
        $mkGrant = function (int $donor, int $proj, string $ref, float $amt, int $s, int $e) use ($d, $fo, $pdo): int {
            q('INSERT INTO grants (donor_id, project_id, reference, amount, start_date, end_date, status, created_by) VALUES (?,?,?,?,?,?,?,?)', [$donor, $proj, $ref, $amt, $d($s), $d($e), 'Active', $fo]);
            return (int) $pdo->lastInsertId();
        };
        $g1 = $mkGrant($d1, $water, 'NWTF-2026-001', 30000000, -240, 120);
        $g2 = $mkGrant($d2, $water, 'HOPE-2026-014', 15000000, -240, 120);
        $g3 = $mkGrant($d3, $edu, 'BFEF-2026-007', 25000000, -150, 200);
        $mkRec = function (int $g, float $amt, int $days, string $ref) use ($d, $fo): void {
            q('INSERT INTO fund_receipts (grant_id, amount, received_on, reference, recorded_by) VALUES (?,?,?,?,?)', [$g, $amt, $d($days), $ref, $fo]);
        };
        $mkRec($g1, 18000000, -230, 'TRF-0001'); $mkRec($g1, 6000000, -100, 'TRF-0042'); $mkRec($g1, 6000000, -30, 'TRF-0077');
        $mkRec($g2, 9000000, -220, 'HOPE-RCPT-01');
        $mkRec($g3, 12000000, -140, 'BFEF-RCPT-01'); $mkRec($g3, 5000000, -60, 'BFEF-RCPT-02');

        // ---------------- budget lines ----------------
        $line = [];
        $mkLine = function (string $key, int $proj, string $cat, string $desc, ?string $actTitle, float $planned) use (&$line, &$act, $fo, $pdo): void {
            q('INSERT INTO budget_lines (project_id, category, description, activity_id, planned_amount, created_by) VALUES (?,?,?,?,?,?)', [$proj, $cat, $desc, $actTitle ? ($act[$actTitle] ?? null) : null, $planned, $fo]);
            $line[$key] = (int) $pdo->lastInsertId();
        };
        $mkLine('w_pers', $water, 'Personnel', 'Project staff salaries', null, 12000000);
        $mkLine('w_trav', $water, 'Travel', 'Field travel and transport', null, 4500000);
        $mkLine('w_mat',  $water, 'Materials and supplies', 'Drilling materials and casing', 'Procure drilling materials', 14000000);
        $mkLine('w_eq',   $water, 'Equipment', 'Hand pumps and installation kits', 'Install hand pumps (phase 1)', 8500000);
        $mkLine('w_trn',  $water, 'Training and workshops', 'Water committee training', 'Water committee training', 2500000);
        $mkLine('w_adm',  $water, 'Administration', 'Office and administration', null, 3500000);
        $mkLine('e_pers', $edu, 'Personnel', 'Mentors and coordinator', null, 7000000);
        $mkLine('e_mat',  $edu, 'Materials and supplies', 'Learning kits and books', 'Distribute learning kits', 9000000);
        $mkLine('e_trn',  $edu, 'Training and workshops', 'Mentoring sessions', 'Mentoring sessions', 3500000);
        $mkLine('e_trav', $edu, 'Travel', 'School visits', null, 2000000);
        $mkLine('e_adm',  $edu, 'Administration', 'Office and administration', null, 1500000);
        $mkLine('e_oth',  $edu, 'Other', 'Scholarship top-ups', null, 2000000);

        // ---------------- purchase orders (commitments) ----------------
        $com = [];
        $mkCom = function (string $key, string $ln, string $po, string $vendor, string $desc, float $amt, string $status, int $ago, ?int $by) use (&$line, &$com, $fo, $pdo, $dt): void {
            q('INSERT INTO commitments (line_id, po_number, vendor, description, amount, status, submitted_by, approved_by, approved_at, decision_note, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$line[$ln], $po, $vendor, $desc, $amt, $status, $fo, in_array($status, ['Approved', 'Paid', 'Rejected'], true) ? $by : null, in_array($status, ['Approved', 'Paid', 'Rejected'], true) ? $dt(-$ago + 1) : null,
                 $status === 'Cancelled' ? 'Cancelled: the item was bought locally.' : null, $dt(-$ago)]);
            $com[$key] = (int) $pdo->lastInsertId();
        };
        $mkCom('c1', 'w_mat', 'PO-WASH-001', 'Borehole Drilling Supplies Ltd', 'Casing pipes and screens, second delivery', 3500000, 'Approved', 20, $ed);
        $mkCom('c2', 'w_eq', 'PO-WASH-002', 'Aqua Pumps Nigeria', 'Forty India Mark II hand pumps', 5000000, 'Approved', 15, $ed);
        $mkCom('c3', 'w_trn', 'PO-WASH-003', 'Community Training Partners', 'Facilitators and venue for committee training', 800000, 'Pending approval', 9, null);
        $mkCom('c4', 'w_adm', 'PO-WASH-004', 'Quick Print Abuja', 'Printing of hygiene posters', 150000, 'Cancelled', 30, null);
        $mkCom('c5', 'e_mat', 'PO-EDU-001', 'Bright Print Ltd', 'Printing of learning kit booklets', 1200000, 'Paid', 70, $ed);
        $mkCom('c6', 'e_mat', 'PO-EDU-002', 'Lagos Books Supplier', 'Second batch of reading books', 2000000, 'Approved', 12, $ed);

        // ---------------- expenditures ----------------
        $mkExp = function (string $ln, int $days, float $amt, string $payee, string $ref, string $desc, string $status, ?int $by = null, ?string $note = null, ?int $commit = null, ?int $created = null) use (&$line, $fo, $pm, $d, $dt, $pdo): void {
            q('INSERT INTO expenditures (line_id, spent_on, amount, payee, reference, description, status, commitment_id, submitted_by, approved_by, approved_at, decision_note, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$line[$ln], $d($days), $amt, $payee, $ref, $desc, $status, $commit, $fo, $status === 'Pending approval' ? null : $by, $status === 'Pending approval' ? null : $dt($days + 1), $note, $dt($created ?? $days)]);
        };
        // water: personnel 9.0m, travel 5.1m (over its 4.5m budget), materials 9.8m, equipment 2.25m, admin 1.85m
        foreach ([-200, -170, -140, -110, -80] as $i => $day) { $mkExp('w_pers', $day, 1800000, 'Project staff payroll', 'PAY-W' . ($i + 1), 'Monthly salaries', 'Approved', $ed); }
        foreach ([[-190, 1200000], [-150, 1350000], [-95, 1100000], [-40, 1450000]] as $i => [$day, $amt]) { $mkExp('w_trav', $day, (float) $amt, 'Field transport and fuel', 'TRV-W' . ($i + 1), 'Field visits to boreholes', 'Approved', $ed); }
        $mkExp('w_trav', -6, 640000, 'Grand Palace Hotel', 'TRV-W5', 'Hotel for visiting consultant', 'Rejected', $ed, 'Rejected: the cost is above the travel rate card.');
        $mkExp('w_mat', -85, 6000000, 'Borehole Drilling Supplies Ltd', 'MAT-W1', 'First delivery of drilling rods and bits', 'Approved', $ed);
        $mkExp('w_mat', -45, 3800000, 'Borehole Drilling Supplies Ltd', 'MAT-W2', 'Drilling fluid and consumables', 'Approved', $ed);
        $mkExp('w_eq', -60, 2250000, 'Aqua Pumps Nigeria', 'EQP-W1', 'Deposit for pump installation kits', 'Approved', $ed);
        $mkExp('w_adm', -60, 900000, 'Abuja Office Supplies Ltd', 'ADM-W1', 'Stationery and office rent contribution', 'Approved', $ed);
        $mkExp('w_adm', -5, 950000, 'Abuja Office Supplies Ltd', 'ADM-W2', 'Stationery and printer maintenance', 'Approved', $ed);
        $mkExp('w_adm', -3, 950000, 'Abuja Office Supplies Ltd', 'ADM-W3', 'Stationery and printer maintenance', 'Pending approval', null, null, null, -3);
        $mkExp('w_pers', -10, 1800000, 'Project staff payroll', 'PAY-W6', 'Monthly salaries', 'Pending approval', null, null, null, -10);
        // education: personnel 3.5m, materials 5.4m, travel 0.6m (underspending), admin 0.45m
        foreach ([[-120, 1750000], [-60, 1750000]] as $i => [$day, $amt]) { $mkExp('e_pers', $day, (float) $amt, 'Mentors and coordinator payroll', 'PAY-E' . ($i + 1), 'Mentor allowances', 'Approved', $ed); }
        $mkExp('e_mat', -80, 3000000, 'Lagos Books Supplier', 'MAT-E1', 'First batch of reading books', 'Approved', $ed);
        $mkExp('e_mat', -68, 1200000, 'Bright Print Ltd', 'MAT-E2', 'Printing of learning kit booklets (PO-EDU-001)', 'Approved', $ed, null, $com['c5']);
        $mkExp('e_mat', -30, 1200000, 'Kano Stationery Mart', 'MAT-E3', 'Exercise books and pens', 'Approved', $ed);
        $mkExp('e_trav', -50, 600000, 'Field transport and fuel', 'TRV-E1', 'School visits in Bwari', 'Approved', $ed);
        $mkExp('e_adm', -45, 450000, 'Abuja Office Supplies Ltd', 'ADM-E1', 'Office supplies', 'Approved', $ed);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    audit('Create', 'demo', null, 'Sample data loaded');
}
