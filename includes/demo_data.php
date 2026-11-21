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
        $fo  = $mkUser('Tunde Balogun', 'finance@demo.org', 5);
        $me  = $mkUser('Hauwa Sani', 'mande@demo.org', 6);
        $au  = $mkUser('Ibrahim Danladi', 'auditor@demo.org', 7);
        $dn  = $mkUser('Grace Adeyemi (Hope Foundation)', 'donor@demo.org', 8);

        $d  = fn(int $days) => date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));
        $dt = fn(int $days) => date('Y-m-d H:i:s', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));

        $mkProject = function (string $title, string $desc, string $loc, int $s, int $e, string $st) use ($d, $pm, $adminId): int {
            q('INSERT INTO projects (title, description, location, start_date, end_date, status, manager_id, allocated_amount, client_code, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)', [$title, $desc, $loc, $d($s), $d($e), $st, $pm, $title === 'Community Water and Sanitation Project' ? 30000000 : 25000000, $title === 'Community Water and Sanitation Project' ? 'DEMO-WATER-2026' : 'DEMO-EDUCATION-2026', $adminId]);
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
        // Five representative activities across both projects.
        $mkAct($water, 'Community needs assessment', $of, -240, -200, 'Completed', 100, 'Assessment report shared with community leaders.');
        $mkAct($water, 'Drill boreholes (phase 1)', $of, -150, 20, 'In progress', 70);
        $mkAct($water, 'Hygiene education sessions', $of, -120, 45, 'In progress', 55);
        $mkAct($edu, 'Enrol girls in school', $of, -150, -60, 'Completed', 100, 'Enrolment target met in all communities.');
        $mkAct($edu, 'Distribute learning kits', $of, -90, 30, 'In progress', 50);
        $weights = [
            'Community needs assessment' => 20,
            'Drill boreholes (phase 1)' => 25,
            'Hygiene education sessions' => 15,
            'Enrol girls in school' => 20,
            'Distribute learning kits' => 20,
        ];
        foreach ($weights as $title => $weight) { q('UPDATE activities SET weight_percent=? WHERE activity_id=?', [$weight, $act[$title]]); }
        foreach (['Community needs assessment','Enrol girls in school'] as $title) {
            q("UPDATE activities SET approval_status='Approved', submitted_by=?, submitted_at=?, reviewed_by=?, reviewed_at=? WHERE activity_id=?", [$of, $dt(-20), $pm, $dt(-19), $act[$title]]);
        }
        q("UPDATE activities SET status='In progress', progress_percent=100, approval_status='Pending', submitted_by=?, submitted_at=?, completion_notes=?, updated_at=NOW() WHERE activity_id=?", [$of, $dt(-1), 'Work completed by the site team and submitted with supporting field notes for manager verification.', $act['Drill boreholes (phase 1)']]);
        q("UPDATE activities SET delay_reason = 'Community access arrangements delayed the field schedule.', delay_reason_at = ? WHERE activity_id = ?", [$dt(-12), $act['Hygiene education sessions']]);

        $mkInd = function (int $p, string $name, string $unit, float $base, float $target, string $freq): int {
            q('INSERT INTO indicators (project_id, name, unit, baseline, target, reporting_period) VALUES (?,?,?,?,?,?)', [$p, $name, $unit, $base, $target, $freq]);
            return (int) db()->lastInsertId();
        };
        $mkVal = function (int $ind, string $label, float $v) use ($me): void {
            q('INSERT INTO indicator_values (indicator_id, period_label, actual_value, recorded_by) VALUES (?,?,?,?)', [$ind, $label, $v, $me]);
        };
        $q = []; for ($k = 2; $k >= 0; $k--) { $q[] = quarter_label(new DateTime('-' . ($k * 3) . ' months')); }
        $m = []; for ($k = 2; $k >= 0; $k--) { $m[] = (new DateTime('first day of this month'))->modify("-$k months")->format('Y-m'); }
        // Fifteen indicators: nine for water/sanitation and six for education.
        $indicatorRows = [
            [$water,'Boreholes completed','boreholes',0,20,'Quarterly',14],
            [$water,'Hygiene sessions held','sessions',0,60,'Monthly',46],
            [$water,'Households with access to safe water','households',35,80,'Quarterly',62],
            [$water,'Women in water committee leadership','%',0,40,'Annual',46],
            [$water,'Water points tested for water quality','points',0,20,'Quarterly',12],
            [$water,'Community members trained on hygiene','people',0,500,'Quarterly',310],
            [$water,'Functional water points','points',0,18,'Quarterly',11],
            [$water,'Households reached by hygiene campaigns','households',0,600,'Monthly',420],
            [$water,'Water committees established','committees',0,10,'Annual',7],
            [$edu,'Girls enrolled in school','girls',0,150,'Quarterly',120],
            [$edu,'Learning kits distributed','kits',0,200,'Quarterly',130],
            [$edu,'Girls retained through term','girls',0,140,'Quarterly',108],
            [$edu,'Mentoring sessions delivered','sessions',0,48,'Monthly',18],
            [$edu,'Schools participating in the programme','schools',0,12,'Annual',8],
            [$edu,'Girls demonstrating improved attendance','girls',0,120,'Quarterly',92],
        ];
        foreach ($indicatorRows as $idx => [$projectId,$name,$unit,$baseline,$target,$frequency,$actual]) {
            $indicatorId = $mkInd($projectId, $name, $unit, (float)$baseline, (float)$target, $frequency);
            $period = $frequency === 'Monthly' ? $m[2] : ($frequency === 'Annual' ? date('Y') : $q[2]);
            $mkVal($indicatorId, $period, (float)$actual);
        }

        $beneficiarySamples = [
            ['Fatima Abubakar','Female','Kuje','08030001001'],
            ['Ibrahim Okoro','Male','Gwagwalada','08030001002'],
            ['Grace Danladi','Female','Bwari','08030001003'],
            ['Musa Bello','Male','Abaji','08030001004'],
        ];
        foreach ($beneficiarySamples as $n => [$name,$sex,$community,$phone]) {
            $dob = date('Y-m-d', strtotime('-' . (18 + $n * 7) . ' years'));
            q('INSERT INTO beneficiaries (beneficiary_code, full_name, sex, date_of_birth, phone, community, state, consent_given, consent_date, registered_by) VALUES (?,?,?,?,?,?,?,1,?,?)',
                ['T' . bin2hex(random_bytes(5)), $name, $sex, $dob, $phone, $community, 'Federal Capital Territory', $d(-30 + $n), $of]);
            $bid = (int) $pdo->lastInsertId();
            q('UPDATE beneficiaries SET beneficiary_code = ? WHERE beneficiary_id = ?', [beneficiary_code_from_id($bid), $bid]);
            // Exactly one enrolment row per sample beneficiary.
            $projectId = $n < 2 ? $water : $edu;
            q('INSERT INTO project_beneficiaries (project_id, beneficiary_id, enrolled_on) VALUES (?,?,?)', [$projectId, $bid, $d(-25 + $n)]);
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

        // ---------------- budget lines (four compact demo records) ----------------
        $line = [];
        $mkLine = function (string $key, int $proj, string $cat, string $desc, ?string $actTitle, float $planned) use (&$line, &$act, $fo, $pdo): void {
            q('INSERT INTO budget_lines (project_id, category, description, activity_id, planned_amount, created_by) VALUES (?,?,?,?,?,?)', [$proj, $cat, $desc, $actTitle ? ($act[$actTitle] ?? null) : null, $planned, $fo]);
            $line[$key] = (int) $pdo->lastInsertId();
        };
        $mkLine('w_mat', $water, 'Materials and supplies', 'Drilling materials and casing', 'Drill boreholes (phase 1)', 14000000);
        $mkLine('w_eq', $water, 'Equipment', 'Hand pumps and installation kits', 'Drill boreholes (phase 1)', 8500000);
        $mkLine('e_mat', $edu, 'Materials and supplies', 'Learning kits and books', 'Distribute learning kits', 9000000);
        $mkLine('e_trav', $edu, 'Travel', 'School visits and field transport', 'Enrol girls in school', 2000000);

        // ---------------- purchase orders (commitments) ----------------
        $com = [];
        $mkCom = function (string $key, string $ln, string $po, string $vendor, string $desc, float $amt, string $status, int $ago, ?int $by) use (&$line, &$com, $fo, $pdo, $dt): void {
            q('INSERT INTO commitments (line_id, po_number, vendor, description, amount, status, submitted_by, approved_by, approved_at, decision_note, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$line[$ln], $po, $vendor, $desc, $amt, $status, $fo, in_array($status, ['Approved', 'Paid', 'Rejected'], true) ? $by : null, in_array($status, ['Approved', 'Paid', 'Rejected'], true) ? $dt(-$ago + 1) : null, null, $dt(-$ago)]);
            $com[$key] = (int) $pdo->lastInsertId();
        };
        $mkCom('c1', 'w_mat', 'PO-WASH-001', 'Borehole Drilling Supplies Ltd', 'Drilling materials', 3500000, 'Approved', 20, $adminId);
        $mkCom('c2', 'w_eq', 'PO-WASH-002', 'Aqua Pumps Nigeria', 'Water project equipment', 2500000, 'Pending approval', 9, null);
        $mkCom('c3', 'e_mat', 'PO-EDU-001', 'Bright Print Ltd', 'Learning kit materials', 1200000, 'Paid', 70, $adminId);
        $mkCom('c4', 'e_trav', 'PO-EDU-002', 'Community Transport Services', 'School visit transport', 450000, 'Approved', 12, $adminId);

        // ---------------- expenditures (exactly four records) ----------------
        $mkExp = function (string $ln, int $days, float $amt, string $payee, string $ref, string $desc, string $status, ?int $by = null, ?string $note = null, ?int $commit = null, ?int $created = null) use (&$line, $fo, $pm, $d, $dt, $pdo): void {
            q('INSERT INTO expenditures (line_id, spent_on, amount, payee, reference, description, status, commitment_id, submitted_by, approved_by, approved_at, decision_note, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$line[$ln], $d($days), $amt, $payee, $ref, $desc, $status, $commit, $fo, $status === 'Pending approval' ? null : $by, $status === 'Pending approval' ? null : $dt($days + 1), $note, $dt($created ?? $days)]);
        };
        $mkExp('w_mat', -85, 6000000, 'Borehole Drilling Supplies Ltd', 'MAT-W1', 'First delivery of drilling materials', 'Approved', $adminId, null, $com['c1']);
        $mkExp('w_eq', -60, 2250000, 'Aqua Pumps Nigeria', 'EQP-W1', 'Water project equipment deposit', 'Pending approval', null, null, $com['c2']);
        $mkExp('e_mat', -68, 1200000, 'Bright Print Ltd', 'MAT-E1', 'Printing of learning kit booklets', 'Approved', $adminId, null, $com['c3']);
        $mkExp('e_trav', -50, 450000, 'Community Transport Services', 'TRV-E1', 'School visits in Bwari', 'Approved', $adminId, null, $com['c4']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    audit('Create', 'demo', null, 'Sample data loaded');
}
