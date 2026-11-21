<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$GLOBALS['NGO_CONFIG'] = require APP_ROOT . '/config/config.php';

function cfg(string $key, $default = null)
{
    return $GLOBALS['NGO_CONFIG'][$key] ?? $default;
}

date_default_timezone_set((string) cfg('timezone', 'Africa/Lagos'));

if (cfg('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

mb_internal_encoding('UTF-8');

require_once __DIR__ . '/helpers.php';

define('BASE_URL', compute_base_url());

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (session_status() === PHP_SESSION_NONE) {
    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $isPlatformArea = strpos($scriptPath, '/platform/') !== false && basename($scriptPath) !== 'upgrade.php';
    $sessionName = $isPlatformArea
        ? (string) cfg('platform_session_name', 'ngo_me_platform_session')
        : (string) cfg('session_name', 'ngo_me_session');
    session_name($sessionName);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Security headers. Scripts and styles are served from this application only.
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self'");

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    error_log('NGO M&E error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (cfg('debug')) {
        echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
            . '<div style="font-family:sans-serif;max-width:560px;margin:60px auto">'
            . '<h2>Something went wrong</h2><p>The request could not be completed. '
            . 'Please try again, or contact your administrator if the problem continues.</p></div>';
    }
    exit;
});

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/platform.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/charts.php';
