<?php
declare(strict_types=1);
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

function current_platform_user(): ?array
{
    static $loaded = false, $user = null;
    if ($loaded) return $user;
    $loaded = true;
    if (!empty($_SESSION['platform_uid']) && platform_registry_available()) {
        $st = platform_db()->prepare('SELECT platform_admin_id, full_name, email, is_active FROM platform_admins WHERE platform_admin_id=? AND is_active=1');
        $st->execute([(int) $_SESSION['platform_uid']]);
        $user = $st->fetch() ?: null;
        if (!$user) unset($_SESSION['platform_uid']);
    }
    return $user;
}

function require_platform_login(): array
{
    $u = current_platform_user();
    if (!$u) redirect('platform/login.php');
    return $u;
}

function platform_audit(string $action, ?int $organisationId = null, string $details = ''): void
{
    try {
        $u = current_platform_user();
        $st = platform_db()->prepare('INSERT INTO platform_audit_log (platform_admin_id, action, organisation_id, details) VALUES (?,?,?,?)');
        $st->execute([$u ? (int)$u['platform_admin_id'] : null, $action, $organisationId, mb_substr($details, 0, 1000)]);
    } catch (Throwable $e) { error_log('Platform audit failed: ' . $e->getMessage()); }
}

function run_tenant_schema(PDO $pdo): void
{
    $sql = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (preg_split('/;\s*\n/', $sql) as $statement) {
        if (trim($statement) !== '') $pdo->exec($statement);
    }
    $pdo->exec("INSERT IGNORE INTO roles (role_id, role_name) VALUES (10, 'Organisation Administrator')");
}

/** A problem the administrator can fix (duplicate code, capacity reached...). Its message is safe to show. */
class OrganisationException extends RuntimeException {}

function platform_server_connection(): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', cfg('db_host'), (int) cfg('db_port', 3306));
    return new PDO($dsn, (string) cfg('db_user'), (string) cfg('db_pass'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/** Database names that must never be handed to an organisation. */
function protected_database_name(string $name): bool
{
    $n = strtolower($name);
    return $n === strtolower((string) cfg('platform_db_name')) || $n === strtolower((string) cfg('db_name'));
}

/**
 * True when a database of this name already exists and holds tables. Used to avoid clashes:
 * an existing database with content is never reused or touched. An existing EMPTY database is
 * treated as free. A database the account cannot see counts as free only when it cannot be opened.
 */
function database_has_content(string $name): bool
{
    try {
        $pdo = connect_database($name);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1049) return false;   // does not exist
        throw $e;
    }
    return (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() > 0;
}

/**
 * Pick a database name for an organisation code that clashes with nothing: not the original or
 * platform database, not any name in the registry (case-insensitive), not any existing database
 * that holds tables. If the natural name is taken, _2, _3 ... are tried.
 */
function next_free_database_name(string $code): string
{
    $base = (string) cfg('db_prefix', '') . 'ngo_org_' . strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code));
    $registry = platform_db()->prepare('SELECT COUNT(*) FROM organisations WHERE LOWER(database_name) = ?');
    for ($n = 1; $n <= 50; $n++) {
        $candidate = $n === 1 ? $base : $base . '_' . $n;
        if (!preg_match('/^[A-Za-z0-9_]{3,64}$/', $candidate)) throw new OrganisationException('The organisation code produces an invalid database name.');
        if (protected_database_name($candidate)) continue;
        $registry->execute([strtolower($candidate)]);
        if ((int) $registry->fetchColumn() > 0) continue;
        if (database_has_content($candidate)) continue;
        return $candidate;
    }
    throw new OrganisationException('A free database name could not be found for that organisation code.');
}

/**
 * Create a clean organisation database: schema, standard roles and ONE administrator. It never
 * copies demonstration or existing records, and it refuses any database that already holds tables.
 * Returns true when it created the database itself, false when it reused an existing empty one.
 * On failure everything it created is removed again.
 */
function create_organisation_database(string $databaseName, string $adminName, string $adminEmail, string $adminPassword): bool
{
    if (!preg_match('/^[A-Za-z0-9_]{3,64}$/', $databaseName)) throw new RuntimeException('Generated database name is invalid.');
    if (protected_database_name($databaseName)) throw new RuntimeException('An organisation cannot use the original or platform database.');
    $server = null; $createdNew = false;
    try { $tenant = connect_database($databaseName); }
    catch (PDOException $connectError) {
        if ((int) ($connectError->errorInfo[1] ?? 0) !== 1049) throw $connectError;
        $server = platform_server_connection();
        $server->exec("CREATE DATABASE `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $createdNew = true;
        $tenant = connect_database($databaseName);
    }
    if ($tenant->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM)) throw new RuntimeException('The target organisation database is not empty.');
    try {
        run_tenant_schema($tenant);
        $st = $tenant->prepare('INSERT INTO users (full_name,email,password_hash,role_id,is_active) VALUES (?,?,?,?,1)');
        $st->execute([$adminName, strtolower($adminEmail), password_hash($adminPassword, PASSWORD_DEFAULT), 10]);
    } catch (Throwable $e) {
        discard_new_database($databaseName, $createdNew);
        throw $e;
    }
    return $createdNew;
}

/** Undo create_organisation_database(). Only ever called for a database that was empty before setup. */
function discard_new_database(string $databaseName, bool $createdNew): void
{
    if (protected_database_name($databaseName) || !preg_match('/^[A-Za-z0-9_]{3,64}$/', $databaseName)) return;
    try {
        if ($createdNew) {
            platform_server_connection()->exec("DROP DATABASE IF EXISTS `$databaseName`");
        } else {
            $pdo = connect_database($databaseName);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $t) $pdo->exec('DROP TABLE IF EXISTS `' . $t[0] . '`');
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    } catch (Throwable $e) { error_log('Could not clean up incomplete tenant database: ' . $e->getMessage()); }
}

/** Serialise organisation provisioning so two requests cannot claim the same code or database name. */
function with_provisioning_lock(callable $work)
{
    $platform = platform_db();
    if (!(int) $platform->query("SELECT GET_LOCK('ngo_org_provisioning', 15)")->fetchColumn()) {
        throw new OrganisationException('Another organisation change is in progress. Please try again in a moment.');
    }
    try { return $work(); }
    finally { try { $platform->query("SELECT RELEASE_LOCK('ngo_org_provisioning')"); } catch (Throwable $e) {} }
}

/** Add the uniqueness rules to older registries (only when the existing rows allow it). */
function ensure_registry_constraints(): void
{
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo = platform_db();
        $has = fn(string $idx) => (int) $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'organisations' AND index_name = '" . $idx . "'")->fetchColumn();
        $rules = [
            'uq_org_code'     => ['organisation_code', 'ALTER TABLE organisations ADD UNIQUE KEY uq_org_code (organisation_code)'],
            'uq_org_database' => ['database_name',     'ALTER TABLE organisations ADD UNIQUE KEY uq_org_database (database_name)'],
            'uq_org_name'     => ['organisation_name', 'ALTER TABLE organisations ADD UNIQUE KEY uq_org_name (organisation_name)'],
        ];
        foreach ($rules as $idx => [$col, $sql]) {
            if ($has($idx)) continue;
            $dups = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT $col FROM organisations GROUP BY $col HAVING COUNT(*) > 1) d")->fetchColumn();
            if ($dups === 0) $pdo->exec($sql);   // with duplicates present nothing is changed; the health panel reports them
        }
    } catch (Throwable $e) { error_log('Registry constraint check failed: ' . $e->getMessage()); }
}

/**
 * Create an organisation: a NEW, CLEAN database plus its registry record. Returns the organisation row.
 * Throws OrganisationException for problems the administrator can fix.
 */
function provision_organisation(string $name, string $code, string $adminName, string $adminEmail, string $adminPassword): array
{
    return with_provisioning_lock(function () use ($name, $code, $adminName, $adminEmail, $adminPassword) {
        $platform = platform_db();
        if ((int) $platform->query('SELECT COUNT(*) FROM organisations')->fetchColumn() >= (int) cfg('max_organisations', 6)) {
            throw new OrganisationException('The maximum number of organisations (' . (int) cfg('max_organisations', 6) . ') has been reached.');
        }
        $st = $platform->prepare('SELECT COUNT(*) FROM organisations WHERE organisation_code = ?'); $st->execute([$code]);
        if ((int) $st->fetchColumn()) throw new OrganisationException('That organisation code is already in use.');
        $st = $platform->prepare('SELECT COUNT(*) FROM organisations WHERE LOWER(organisation_name) = LOWER(?)'); $st->execute([$name]);
        if ((int) $st->fetchColumn()) throw new OrganisationException('An organisation with that name is already registered.');

        $database = next_free_database_name($code);
        $createdNew = create_organisation_database($database, $adminName, $adminEmail, $adminPassword);
        try {
            $st = $platform->prepare("INSERT INTO organisations (organisation_name,organisation_code,database_name,status) VALUES (?,?,?,'Active')");
            $st->execute([$name, $code, $database]);
            $id = (int) $platform->lastInsertId();
        } catch (Throwable $e) {
            discard_new_database($database, $createdNew);
            throw $e;
        }
        platform_audit('Organisation created', $id, 'Created organisation ' . $code . ' with its own clean database ' . $database . ' and an initial Organisation Administrator.');
        return ['organisation_id' => $id, 'organisation_name' => $name, 'organisation_code' => $code, 'database_name' => $database, 'status' => 'Active'];
    });
}

/**
 * Inspect one organisation's database assignment. Returns ['level' => ok|warn|error,
 * 'messages' => [...], 'repairable' => bool, 'records' => ?int]. Read-only.
 */
function organisation_health(array $org): array
{
    $messages = []; $level = 'ok'; $repairable = false; $records = null;
    $isOriginal = strtolower((string) $org['database_name']) === strtolower((string) cfg('db_name'));
    $conflict = organisation_database_conflict($org);
    if ($conflict !== null) { $level = 'error'; $repairable = true; $messages[] = 'This organisation ' . $conflict . '. Its sign-in is blocked so it cannot show another organisation\'s data.'; }
    if ($conflict === null || !$isOriginal) {
        try {
            $pdo = connect_database((string) $org['database_name']);
            $hasUsers = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn();
            if (!$hasUsers) {
                $level = 'error'; $repairable = true; $messages[] = 'The database exists but has no organisation tables.';
            } elseif ($conflict === null) {
                $records = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
                if (!$isOriginal) {
                    $demo = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE '%@demo.org'")->fetchColumn();
                    if ($demo > 0) { $level = $level === 'error' ? 'error' : 'warn'; $repairable = true; $messages[] = 'This database contains demonstration accounts that do not belong to a new organisation.'; }
                }
            }
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1049) { $level = 'error'; $repairable = true; $messages[] = 'The assigned database does not exist.'; }
            else { $level = 'error'; $messages[] = 'The assigned database could not be opened.'; }
        }
    }
    // The owner of a database (the first-registered organisation, e.g. the original one) never has a conflict, so it is never offered a repair.
    return ['level' => $level, 'messages' => $messages, 'repairable' => $repairable, 'records' => $records];
}

/**
 * Give an organisation with an incorrect database assignment its own new, clean database and
 * re-point its registry record at it. The previously assigned database is NOT modified or dropped:
 * its records stay exactly as they were (and keep belonging to whichever organisation owns it).
 * Returns [oldDatabase, newDatabase].
 */
function repair_organisation_database(int $organisationId, string $adminName, string $adminEmail, string $adminPassword): array
{
    return with_provisioning_lock(function () use ($organisationId, $adminName, $adminEmail, $adminPassword) {
        $platform = platform_db();
        $org = organisation_by_id($organisationId, false);
        if (!$org) throw new OrganisationException('Organisation not found.');
        $health = organisation_health($org);
        if (!$health['repairable']) throw new OrganisationException('This organisation\'s database assignment is sound, so nothing needs repairing.');
        $old = (string) $org['database_name'];
        $new = next_free_database_name((string) $org['organisation_code']);
        $createdNew = create_organisation_database($new, $adminName, $adminEmail, $adminPassword);
        try {
            $st = $platform->prepare('UPDATE organisations SET database_name = ? WHERE organisation_id = ? AND database_name = ?');
            $st->execute([$new, $organisationId, $old]);
            if ($st->rowCount() !== 1) throw new RuntimeException('The organisation record changed while it was being repaired.');
        } catch (Throwable $e) {
            discard_new_database($new, $createdNew);
            throw $e;
        }
        platform_audit('Organisation database repaired', $organisationId, 'Re-assigned from ' . $old . ' to new clean database ' . $new . '. The previous database was left untouched.');
        return [$old, $new];
    });
}

function drop_organisation_database(string $databaseName): void
{
    if (!preg_match('/^[A-Za-z0-9_]{3,64}$/', $databaseName) || protected_database_name($databaseName)) {
        throw new RuntimeException('Refusing to drop an invalid or protected database name.');
    }
    platform_server_connection()->exec("DROP DATABASE IF EXISTS `$databaseName`");
}


/** The first-registered organisation (the original one), if it is active and has a sound database assignment. */
function original_organisation(): ?array
{
    static $loaded = false, $org = null;
    if ($loaded) return $org;
    $loaded = true;
    try {
        if (!platform_registry_available()) return null;
        $id = (int) platform_db()->query('SELECT MIN(organisation_id) FROM organisations')->fetchColumn();
        $org = $id ? organisation_by_id($id, true) : null;
    } catch (Throwable $e) { $org = null; }
    return $org;
}

/** The platform owner's own Super Administrator account inside the original organisation (matched by email). */
function platform_owner_tenant_user(array $platformUser): ?array
{
    $org = original_organisation();
    if (!$org) return null;
    try {
        $st = connect_database((string) $org['database_name'])->prepare("SELECT u.user_id, u.full_name, u.email FROM users u JOIN roles r ON r.role_id = u.role_id WHERE LOWER(u.email) = LOWER(?) AND u.is_active = 1 AND r.role_name = 'Super Administrator' LIMIT 1");
        $st->execute([(string) $platformUser['email']]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/** True when the signed-in organisation user is the platform owner using the original organisation. */
function is_platform_owner_user(): bool
{
    static $v = null;
    if ($v !== null) return $v;
    $v = false;
    try {
        $u = current_user();
        $org = current_organisation();
        $orig = original_organisation();
        if ($u && $org && $orig && (int) $org['organisation_id'] === (int) $orig['organisation_id'] && $u['role_name'] === ROLE_ADMIN) {
            $st = platform_db()->prepare('SELECT COUNT(*) FROM platform_admins WHERE LOWER(email) = LOWER(?) AND is_active = 1');
            $st->execute([(string) $u['email']]);
            $v = (int) $st->fetchColumn() > 0;
        }
    } catch (Throwable $e) { $v = false; }
    return $v;
}
