<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Stop the request unless a valid CSRF token was sent with the POST form. */
function csrf_verify(): void
{
    $sent  = $_POST['csrf_token'] ?? '';
    $known = (string) ($_SESSION['csrf'] ?? '');
    if ($known === '' || !is_string($sent) || $sent === '' || !hash_equals($known, $sent)) {
        http_response_code(400);
        echo '<!doctype html><meta charset="utf-8"><title>Invalid request</title>'
            . '<div style="font-family:sans-serif;max-width:560px;margin:60px auto"><h2>Invalid or expired form</h2>'
            . '<p>The form could not be verified. Go back, reload the page and try again.</p></div>';
        exit;
    }
}
