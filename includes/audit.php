<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }


function audit(string $action, ?string $table = null, ?int $recordId = null, string $details = '', ?int $userId = null): void
{
    if ($userId === null && function_exists('current_user') && ($u = current_user())) {
        $userId = (int) $u['user_id'];
    }
    try {
        q('INSERT INTO audit_log (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)',
            [$userId, $action, $table, $recordId, mb_substr($details, 0, 1000)]);
    } catch (Throwable $e) {
        error_log('Audit log write failed: ' . $e->getMessage());
    }
}



