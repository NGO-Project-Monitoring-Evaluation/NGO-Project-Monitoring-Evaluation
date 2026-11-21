<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

const ROLE_ADMIN    = 'Super Administrator';
const ROLE_ORG_ADMIN = 'Organisation Administrator';
const ROLE_MANAGER  = 'Project Manager';
const ROLE_FIELD    = 'Field Officer';
const ROLE_FINANCE  = 'Finance Officer';
const ROLE_ME       = 'M&E Officer';
const ROLE_AUDITOR  = 'Auditor';
const ROLE_DONOR    = 'Donor/Partner';

/** Who may do what (Chapter Three access-control table). */
function permission_map(): array
{
    $all    = [ROLE_ADMIN, ROLE_FINANCE, ROLE_MANAGER, ROLE_ME, ROLE_FIELD, ROLE_AUDITOR, ROLE_DONOR];
    $ops    = [ROLE_ADMIN, ROLE_MANAGER, ROLE_ME, ROLE_FIELD, ROLE_AUDITOR];   // everyone except donors
    $fin    = [ROLE_ADMIN, ROLE_FINANCE, ROLE_AUDITOR];
    $finSummary = [ROLE_ADMIN, ROLE_FINANCE, ROLE_MANAGER, ROLE_AUDITOR, ROLE_DONOR];
    $opsReports = [ROLE_ADMIN, ROLE_MANAGER, ROLE_ME, ROLE_FIELD, ROLE_AUDITOR, ROLE_DONOR];
    $financeReports = [ROLE_ADMIN, ROLE_FINANCE, ROLE_AUDITOR];
    return [
        'manage_users'         => [ROLE_ADMIN],
        'view_audit'           => [ROLE_ADMIN, ROLE_AUDITOR],
        'view_projects'        => $all,
        'manage_projects'      => [ROLE_ADMIN],
        'view_operations'      => $ops,                                                   // activities and indicators
        'manage_activities'    => [ROLE_ADMIN, ROLE_MANAGER],
        'update_progress'      => [ROLE_ADMIN, ROLE_MANAGER, ROLE_FIELD],                  // field officers: assigned activities only
        'define_indicators'    => [ROLE_ADMIN, ROLE_MANAGER, ROLE_ME],
        'record_values'        => [ROLE_ADMIN, ROLE_MANAGER, ROLE_ME, ROLE_FIELD],
        'view_beneficiaries'   => [ROLE_ADMIN, ROLE_MANAGER, ROLE_ME, ROLE_FIELD, ROLE_AUDITOR],
        'manage_beneficiaries' => [ROLE_ADMIN, ROLE_MANAGER, ROLE_FIELD],
        'view_reports'         => $all,
        'view_operational_reports' => $opsReports,
        'view_financial_reports'   => $financeReports,
        'beneficiary_report'   => [ROLE_ADMIN, ROLE_MANAGER, ROLE_AUDITOR],
        'view_finance'         => $finSummary,                         // summaries (donors: assigned projects only)
        'view_finance_detail'  => $fin,                                                     // Finance Officer manages; Auditor reviews read-only
        'view_budget_lines'    => [ROLE_ADMIN, ROLE_FINANCE, ROLE_MANAGER, ROLE_AUDITOR], // Project Managers view budget allocations and line-level spend summaries only
        'view_finance_alerts'  => $fin,                                                    // internal control alerts are not client/donor-facing
        'manage_budget'        => [ROLE_ADMIN, ROLE_FINANCE],
        'manage_funds'         => [ROLE_ADMIN, ROLE_FINANCE],                              // donors, grants, receipts
        'record_expenditure'   => [ROLE_ADMIN, ROLE_FINANCE],                               // Finance Officer records transactions and purchase orders
        'approve_finance'      => [ROLE_ADMIN],                                                 // Organisation Administrator only
    ];
}

/**
 * The signed-in user, read from the database on every request, so that a
 * deactivated account or a changed role takes effect on the next page opened.
 */
function current_user(): ?array
{
    static $loaded = false, $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (!empty($_SESSION['uid'])) {
        ensure_schema();
        $user = fetch_one(
            'SELECT u.user_id, u.full_name, u.email, u.is_active, u.role_id, r.role_name
               FROM users u JOIN roles r ON r.role_id = u.role_id
              WHERE u.user_id = ? AND u.is_active = 1',
            [(int) $_SESSION['uid']]
        );
        if (!$user) {
            unset($_SESSION['uid']);
        }
    }
    return $user;
}

function role_name(): string
{
    $u = current_user();
    return $u ? (string) $u['role_name'] : '';
}

function is_admin(): bool   { return in_array(role_name(), [ROLE_ADMIN, ROLE_ORG_ADMIN], true); }
function is_manager(): bool { return role_name() === ROLE_MANAGER; }
function is_officer(): bool { return role_name() === ROLE_FIELD; }   // field officers see and update only their own activities
function is_donor(): bool   { return role_name() === ROLE_DONOR; }
function is_auditor(): bool { return role_name() === ROLE_AUDITOR; }
function is_finance_officer(): bool { return role_name() === ROLE_FINANCE; }

/** Administrators and project managers may update any activity; a field officer only the ones assigned to them. */
function can_update_activity(array $activity): bool
{
    $u = current_user();
    if (!$u || !can('update_progress')) {
        return false;
    }
    return !is_officer() || (int) ($activity['assigned_to'] ?? 0) === (int) $u['user_id'];
}

/** Project ids assigned to the signed-in Donor/Partner user. */
function donor_project_ids(): array
{
    static $ids = null;
    if ($ids === null) {
        $u = current_user();
        $ids = $u ? array_map('intval', array_column(fetch_all('SELECT project_id FROM user_projects WHERE user_id = ?', [$u['user_id']]), 'project_id')) : [];
    }
    return $ids;
}

/** Project ids managed by the signed-in Project Manager. */
function manager_project_ids(): array
{
    static $ids = null;
    if ($ids === null) {
        $u = current_user();
        $ids = $u ? array_map('intval', array_column(fetch_all('SELECT project_id FROM projects WHERE manager_id = ?', [$u['user_id']]), 'project_id')) : [];
    }
    return $ids;
}

/**
 * SQL scope for project-owned records. Project Managers are restricted to projects
 * whose manager_id is their own user id; Donor/Partner users are restricted to
 * the explicit user_projects assignments. Other operational/audit roles retain
 * their organisation-wide read scope, subject to their individual permissions.
 */
function project_scope(string $col = 'p.project_id'): array
{
    if (is_manager()) {
        $u = current_user();
        return $u ? [' AND ' . $col . ' IN (SELECT project_id FROM projects WHERE manager_id = ?)', [(int) $u['user_id']]] : [' AND 1 = 0', []];
    }
    if (!is_donor()) {
        return ['', []];
    }
    $ids = donor_project_ids();
    if (!$ids) {
        return [' AND 1 = 0', []];
    }
    return [' AND ' . $col . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

function can_view_project(int $projectId): bool
{
    if (is_admin() || (!is_manager() && !is_donor())) return true;
    if (is_manager()) return in_array($projectId, manager_project_ids(), true);
    return in_array($projectId, donor_project_ids(), true);
}


function can_view_beneficiary(int $beneficiaryId): bool
{
    if (is_admin()) return true;
    [$scope, $params] = project_scope('pb.project_id');
    if ($scope === '') return true;
    return (bool) fetch_value('SELECT 1 FROM project_beneficiaries pb WHERE pb.beneficiary_id=?' . $scope . ' LIMIT 1', array_merge([$beneficiaryId], $params));
}

function can(string $permission): bool
{
    $map = permission_map();

    // Platform administration is separate. The Organisation Administrator is
    // the unrestricted account inside its own organisation database.
    // Every other tenant role must pass the explicit role-to-permission check below.
    if (is_admin()) {
        return array_key_exists($permission, $map);
    }

    return isset($map[$permission]) && in_array(role_name(), $map[$permission], true);
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php' . ($next !== '' && is_post() === false ? '?next=' . rawurlencode($next) : ''));
    }
    return $u;
}

/** Every protected page calls this first; the check happens on the server for every request. */
function require_perm(string $permission): array
{
    $u = require_login();
    if (!can($permission)) {
        deny_access();
    }
    return $u;
}

function deny_access(): void
{
    http_response_code(403);
    require_once __DIR__ . '/layout.php';
    render_header('Access denied');
    echo '<div class="alert alert-danger"><h5 class="alert-heading">Access denied</h5>'
        . '<p class="mb-0">Your role does not allow you to open this page or perform this action.</p></div>'
        . '<a class="btn btn-outline-primary" href="' . e(url('dashboard.php')) . '">Back to the dashboard</a>';
    render_footer();
    exit;
}

function not_found(string $what = 'record'): void
{
    http_response_code(404);
    require_once __DIR__ . '/layout.php';
    render_header('Not found');
    echo '<div class="alert alert-warning">The ' . e($what) . ' you asked for could not be found.</div>'
        . '<a class="btn btn-outline-primary" href="' . e(url('dashboard.php')) . '">Back to the dashboard</a>';
    render_footer();
    exit;
}

/** Try to sign a user in. Returns true on success. Every attempt is written to the audit log. */
function attempt_login(string $email, string $password): bool
{
    $user = fetch_one('SELECT user_id, password_hash, is_active FROM users WHERE email = ?', [$email]);
    // Always run one password check, so a missing account takes about as long as a wrong password.
    $hash = $user['password_hash'] ?? password_hash('not-a-real-password', PASSWORD_DEFAULT);
    $ok   = password_verify($password, $hash);

    if ($user && $ok && (int) $user['is_active'] === 1) {
        session_regenerate_id(true);
        unset($_SESSION['client_project_id'], $_SESSION['client_org_code']);
        $_SESSION['uid'] = (int) $user['user_id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            q('UPDATE users SET password_hash = ? WHERE user_id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
        }
        audit('Login', 'users', (int) $user['user_id'], 'Signed in', (int) $user['user_id']);
        return true;
    }
    audit('Login failed', 'users', $user ? (int) $user['user_id'] : null, 'Failed sign-in for: ' . mb_substr($email, 0, 100), $user ? (int) $user['user_id'] : null);
    return false;
}

function do_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}

/** At least 8 characters, with at least one letter and one number. */
function password_problem(string $pw): ?string
{
    if (mb_strlen($pw) < 8)               return 'The password must be at least 8 characters long.';
    if (!preg_match('/[A-Za-z]/', $pw))   return 'The password must contain at least one letter.';
    if (!preg_match('/[0-9]/', $pw))      return 'The password must contain at least one number.';
    return null;
}

/** Active users for drop-down lists. */
function active_users(): array
{
    return fetch_all('SELECT u.user_id, u.full_name, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.is_active = 1 ORDER BY u.full_name');
}
