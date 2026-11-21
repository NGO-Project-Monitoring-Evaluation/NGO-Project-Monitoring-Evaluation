<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

/** Create a PDO connection to a named database using the configured MySQL account. */
function connect_database(string $database): PDO
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        throw new RuntimeException('Invalid database name in the organisation registry.');
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', cfg('db_host'), (int) cfg('db_port', 3306), $database);
    return new PDO($dsn, (string) cfg('db_user'), (string) cfg('db_pass'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** Central registry connection. It can be initialised by the one-time upgrade wizard. */
function platform_db(bool $create = false): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $name = (string) cfg('platform_db_name', 'ngo_me_platform');
    if ($name === (string)cfg('db_name')) throw new RuntimeException('The platform registry database must be different from the existing organisation database.');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) throw new RuntimeException('Invalid platform database name.');
    try {
        $pdo = connect_database($name);
    } catch (PDOException $ex) {
        $driverCode = (int)($ex->errorInfo[1] ?? 0);
        if (!$create || ($driverCode !== 1049 && $driverCode !== 0)) throw $ex;
        $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', cfg('db_host'), (int) cfg('db_port', 3306));
        $server = new PDO($serverDsn, (string) cfg('db_user'), (string) cfg('db_pass'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = connect_database($name);
    }
    return $pdo;
}

function ensure_platform_schema(bool $createDatabase = false): void
{
    $pdo = platform_db($createDatabase);
    $pdo->exec("CREATE TABLE IF NOT EXISTS organisations (
        organisation_id INT AUTO_INCREMENT PRIMARY KEY,
        organisation_name VARCHAR(150) NOT NULL,
        organisation_code VARCHAR(24) NOT NULL,
        database_name VARCHAR(64) NOT NULL,
        status ENUM('Active','Suspended') NOT NULL DEFAULT 'Active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_org_code (organisation_code),
        UNIQUE KEY uq_org_database (database_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_admins (
        platform_admin_id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_platform_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_audit_log (
        log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
        platform_admin_id INT NULL,
        action VARCHAR(60) NOT NULL,
        organisation_id INT NULL,
        details TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_platform_audit_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function platform_registry_available(): bool
{
    $platformLock = APP_ROOT . '/config/platform.lock';
    try {
        $pdo = platform_db(false);
        $available = (bool) $pdo->query("SHOW TABLES LIKE 'organisations'")->fetchColumn();
        if (!$available && is_file($platformLock)) {
            throw new RuntimeException('The platform registry is missing its organisations table. Tenant routing has been stopped to protect organisation data.');
        }
        if ($available && !is_file($platformLock)) {
            if (@file_put_contents($platformLock, 'Multi-organisation mode enabled on ' . date('c')) === false) {
                throw new RuntimeException('Could not establish the platform-mode safety lock. Tenant routing has been stopped.');
            }
        }
        return $available;
    } catch (PDOException $e) {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        if ($driverCode === 1049 && !is_file($platformLock)) return false;
        throw new RuntimeException('The central organisation registry is unavailable. Tenant access has been stopped to prevent cross-organisation data exposure.', 0, $e);
    } catch (Throwable $e) {
        throw $e;
    }
}

/**
 * Describes why an organisation's database assignment is unsafe, or null when it is sound.
 * A database may belong to exactly one organisation: the one registered first (lowest id).
 * Any later organisation that points at the same database - for example at the original
 * ngo_me database - is blocked from tenant routing, so it can never display another
 * organisation's records. Nothing is deleted or changed by this check.
 */
function organisation_database_conflict(array $org): ?string
{
    $name = strtolower((string) $org['database_name']);
    if ($name === strtolower((string) cfg('platform_db_name'))) {
        return 'is assigned the platform registry database';
    }
    $st = platform_db()->prepare('SELECT organisation_id, organisation_name FROM organisations WHERE LOWER(database_name) = ? ORDER BY organisation_id LIMIT 1');
    $st->execute([$name]);
    $owner = $st->fetch();
    if ($owner && (int) $owner['organisation_id'] !== (int) $org['organisation_id']) {
        return 'shares its database with ' . $owner['organisation_name'];
    }
    return null;
}

function organisation_by_code(string $code, bool $activeOnly = true): ?array
{
    if (!platform_registry_available()) return null;
    $sql = 'SELECT organisation_id, organisation_name, organisation_code, database_name, status FROM organisations WHERE organisation_code = ?';
    if ($activeOnly) $sql .= " AND status='Active'";
    $st = platform_db()->prepare($sql);
    $st->execute([strtoupper(trim($code))]);
    $row = $st->fetch();
    if (!$row) return null;
    // Tenant routing (activeOnly) refuses organisations with an unsafe database assignment.
    if ($activeOnly && organisation_database_conflict($row) !== null) return null;
    return $row;
}

function organisation_by_id(int $id, bool $activeOnly = true): ?array
{
    if (!platform_registry_available()) return null;
    $sql = 'SELECT organisation_id, organisation_name, organisation_code, database_name, status FROM organisations WHERE organisation_id = ?';
    if ($activeOnly) $sql .= " AND status='Active'";
    $st = platform_db()->prepare($sql); $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    if ($activeOnly && organisation_database_conflict($row) !== null) return null;
    return $row;
}

/** Select a tenant by a registry lookup, never by a database name supplied by the browser. */
function set_organisation_context(string $code): bool
{
    $org = organisation_by_code($code);
    if (!$org) return false;
    $newId = (int) $org['organisation_id'];
    $oldId = (int) ($_SESSION['org_id'] ?? 0);
    if ($oldId !== $newId && ($oldId !== 0 || !empty($_SESSION['uid']))) {
        // Never carry a user's identity or cached schema/client state into another tenant.
        unset($_SESSION['uid'], $_SESSION['schema_checked_v6'], $_SESSION['client_project_id'], $_SESSION['client_org_code']);
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    $_SESSION['org_id'] = $newId;
    $_SESSION['org_code'] = (string) $org['organisation_code'];
    return true;
}

function current_organisation(): ?array
{
    if (empty($_SESSION['org_id'])) return null;
    return organisation_by_id((int) $_SESSION['org_id']);
}

function organisation_name(): string
{
    $org = current_organisation();
    return $org ? (string) $org['organisation_name'] : '';
}

/** Shared tenant database connection. The database name always comes from the trusted registry. */
function db(): PDO
{
    static $connections = [];
    $database = (string) cfg('db_name');
    if (platform_registry_available()) {
        $org = current_organisation();
        if (!$org) {
            unset($_SESSION['uid'], $_SESSION['org_id'], $_SESSION['org_code']);
            redirect('login.php');
        }
        $database = (string) $org['database_name'];
    }
    if (!isset($connections[$database])) {
        try { $connections[$database] = connect_database($database); }
        catch (PDOException $ex) {
            http_response_code(500);
            error_log('NGO M&E database connection failed: ' . $ex->getMessage());
            $hint = cfg('debug') ? '<p><code>' . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8') . '</code></p>' : '';
            echo '<!doctype html><meta charset="utf-8"><title>Database error</title><div style="font-family:sans-serif;max-width:620px;margin:60px auto"><h2>Cannot connect to the organisation database</h2><p>Check the database settings or contact the platform administrator.</p>' . $hint . '</div>';
            exit;
        }
    }
    return $connections[$database];
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function fetch_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function fetch_one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function fetch_value(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}


/**
 * Brings an older installation up to date (safe to run repeatedly): runs database/schema.sql again
 * (every statement is CREATE IF NOT EXISTS or an upsert), adds columns that older versions lacked,
 * and converts old beneficiary codes (BEN-0001) to the NGBN001 format. Checked once per login session.
 */
function ensure_schema(): void
{
    if (!empty($_SESSION['schema_checked_v6'])) { return; }
    $pdo = db();
    if (!(int) fetch_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'activities'")) {
        return;
    }
    $tables = (int) fetch_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('budget_lines','commitments','expenditures','donors','grants','fund_receipts','user_projects')");
    $needs = $tables < 7 || (int) fetch_value('SELECT COUNT(*) FROM roles') < 8;
    $col = fn(string $t, string $c) => (int) fetch_value('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
    if (!$col('activities', 'delay_reason'))    { $pdo->exec('ALTER TABLE activities ADD COLUMN delay_reason TEXT NULL AFTER completion_notes'); }
    if (!$col('activities', 'delay_reason_at')) { $pdo->exec('ALTER TABLE activities ADD COLUMN delay_reason_at DATETIME NULL AFTER delay_reason'); }
    if ($needs) {
        $sql = preg_replace('/^--.*$/m', '', (string) file_get_contents(APP_ROOT . '/database/schema.sql'));
        foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
            if (trim($stmt) !== '') { $pdo->exec($stmt); }
        }
    }
    $pdo->exec("UPDATE beneficiaries SET beneficiary_code = CONCAT('NGBN', LPAD(beneficiary_id, 3, '0')) WHERE beneficiary_code LIKE 'BEN-%'");
    // Project accountability, approval workflow and client tracking additions.
    $add = [
        'projects' => [
            'allocated_amount' => 'DECIMAL(16,2) NOT NULL DEFAULT 0 AFTER manager_id',
            'client_code' => 'VARCHAR(32) NULL AFTER allocated_amount',
        ],
        'activities' => [
            'weight_percent' => 'DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER progress_percent',
            'approval_status' => "ENUM('Not submitted','Pending','Approved','Rejected') NOT NULL DEFAULT 'Not submitted' AFTER weight_percent",
            'submitted_by' => 'INT NULL AFTER approval_status',
            'submitted_at' => 'DATETIME NULL AFTER submitted_by',
            'reviewed_by' => 'INT NULL AFTER submitted_at',
            'reviewed_at' => 'DATETIME NULL AFTER reviewed_by',
            'review_note' => 'TEXT NULL AFTER reviewed_at',
        ],
    ];
    foreach ($add as $table => $columns) {
        foreach ($columns as $name => $definition) {
            if (!$col($table, $name)) { $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$name` $definition"); }
        }
    }
    q("INSERT IGNORE INTO roles (role_name) VALUES ('Client'), ('Organisation Administrator')");
    // Remove the Executive Director role completely. Existing accounts are deactivated and
    // reassigned to Finance Officer (not elevated) before the role row is removed.
    $edRole = fetch_value("SELECT role_id FROM roles WHERE role_name='Executive Director' LIMIT 1");
    if ($edRole !== null) {
        $financeRole = fetch_value("SELECT role_id FROM roles WHERE role_name='Finance Officer' LIMIT 1");
        if ($financeRole !== null) {
            q('UPDATE users SET role_id=?, is_active=0 WHERE role_id=?', [(int)$financeRole, (int)$edRole]);
            q('DELETE FROM roles WHERE role_id=?', [(int)$edRole]);
        }
    }
    // Give existing projects a stable, non-sequential public code.
    $missingCodes = fetch_all("SELECT project_id FROM projects WHERE client_code IS NULL OR client_code = ''");
    foreach ($missingCodes as $row) {
        q('UPDATE projects SET client_code = ? WHERE project_id = ?', ['PRJ-' . strtoupper(bin2hex(random_bytes(14))), (int) $row['project_id']]);
    }
    $hasClientIndex = (int) fetch_value("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='projects' AND index_name='uq_projects_client_code'");
    if (!$hasClientIndex) { $pdo->exec('ALTER TABLE projects ADD UNIQUE KEY uq_projects_client_code (client_code)'); }
    $_SESSION['schema_checked_v6'] = true;
}
