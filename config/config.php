<?php
/**
 * Application settings.
 * Edit the database values below to match your server.
 *   - XAMPP on your own computer: leave the defaults (user "root", empty password).
 *   - Shared hosting: copy the host name, database name, user and password
 *     shown in your hosting control panel.
 */
return [
    'db_host'      => '127.0.0.1',
    'db_port'      => 3306,
    'db_name'      => 'ngo_me',
    'db_user'      => 'root',
    'db_pass'      => '',

    'app_name'     => 'NGO Project Monitoring and Evaluation System',
    'app_full_name' => 'NGO Project Monitoring and Evaluation System',
    'currency'     => '₦',
    // Spending is flagged when it differs from the expected spend to date by more than this percentage.
    'variance_threshold' => 10,
    'timezone'     => 'Africa/Lagos',
    'session_name' => 'ngo_me_session',

    // Leave empty to detect the address automatically (for example /ngo-me-system).
    // Set it only if links do not work, e.g. 'https://example.com/app'.
    'base_url'     => '',

    // Set to true only while developing, to see detailed error messages.
    'debug'        => false,
];
