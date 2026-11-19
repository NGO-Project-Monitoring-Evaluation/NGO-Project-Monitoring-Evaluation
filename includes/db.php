<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

/** Shared database connection (PDO). All queries use prepared statements. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', cfg('db_host'), (int) cfg('db_port', 3306), cfg('db_name'));
    try {
        $pdo = new PDO($dsn, (string) cfg('db_user'), (string) cfg('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $ex) {
        http_response_code(500);
        error_log('NGO M&E database connection failed: ' . $ex->getMessage());
        $hint = cfg('debug') ? '<p><code>' . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8') . '</code></p>' : '';
        echo '<!doctype html><meta charset="utf-8"><title>Database error</title>'
            . '<div style="font-family:sans-serif;max-width:620px;margin:60px auto"><h2>Cannot connect to the database</h2>'
            . '<p>Check that MySQL is running and that the settings in <code>config/config.php</code> are correct. '
            . 'If this is a new installation, open <a href="' . htmlspecialchars(url('install.php'), ENT_QUOTES, 'UTF-8') . '">install.php</a>.</p>' . $hint . '</div>';
        exit;
    }
    return $pdo;
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
    if (!empty($_SESSION['schema_checked_v3'])) {
        return;
    }
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
    $_SESSION['schema_checked_v3'] = true;
}
