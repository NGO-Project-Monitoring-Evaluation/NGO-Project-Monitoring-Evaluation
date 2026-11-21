<?php
// Opens the platform owner's own dashboard/modules in the original organisation.
// The platform session is not touched; only the separate organisation session is set.
require dirname(__DIR__) . '/includes/bootstrap.php';
$pu = require_platform_login();
if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), get('t'))) { http_response_code(400); exit('Invalid or expired link. Go back to Platform Administration and try again.'); }
$to = get('to', 'dashboard.php');
if (!preg_match('#^[A-Za-z0-9_]+(/[A-Za-z0-9_]+)*\.php$#', $to) || preg_match('#^(platform|includes|config|database|assets)/#', $to) || $to === 'install.php' || !is_file(APP_ROOT . '/' . $to)) $to = 'dashboard.php';
$org = original_organisation();
$tu = $org ? platform_owner_tenant_user($pu) : null;
if (!$org || !$tu) { flash('danger', 'Your Super Administrator account in the original organisation could not be found or is inactive.'); redirect('platform/index.php'); }

// Switch to the organisation session (its own cookie), leaving the platform session intact.
session_write_close();
session_name((string) cfg('session_name', 'ngo_me_session'));
$sid = (string) ($_COOKIE[session_name()] ?? '');
session_id(preg_match('/^[A-Za-z0-9,-]{22,128}$/', $sid) ? $sid : '');
session_start();
if ((int) ($_SESSION['org_id'] ?? 0) !== (int) $org['organisation_id'] || (int) ($_SESSION['uid'] ?? 0) !== (int) $tu['user_id']) {
    session_regenerate_id(true);
    unset($_SESSION['schema_checked_v6'], $_SESSION['client_project_id'], $_SESSION['client_org_code']);
    $_SESSION['org_id'] = (int) $org['organisation_id'];
    $_SESSION['org_code'] = (string) $org['organisation_code'];
    $_SESSION['uid'] = (int) $tu['user_id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    audit('Login', 'users', (int) $tu['user_id'], 'Signed in from Platform Administration', (int) $tu['user_id']);
}
redirect($to);
