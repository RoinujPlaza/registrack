<?php

/**
 * REGIS-TRACK configuration template.
 * Copy to config/config.php (gitignored) and fill in real values.
 * Any value can be overridden by an environment variable, e.g. DB_PASSWORD.
 */

return [
    'app' => [
        'env'  => 'development',
        // Never enable in production; leaks exception details to clients.
        'debug' => false,
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => '3306',
        'name'     => 'registrack',
        // Local XAMPP dev default; use registrack_app credentials in deployment.
        'user'     => 'root',
        'password' => '',
    ],

    'log' => [
        'file' => __DIR__ . '/../logs/app.log',
    ],

    'auth' => [
        // Use case Table 4a: temporary lock after repeated failed attempts.
        'max_failed_attempts' => 5,
        'lockout_minutes'     => 15,
        'session_idle_minutes' => 30,
    ],

    'requests' => [
        // Decision #5: same student + same document type within N days -> warning only.
        'duplicate_window_days' => 30,
        'max_quantity'          => 10,
    ],

    'notifications' => [
        // Decision #6: in-system is always on; email is optional and retried by cron.
        'email_enabled' => false,
        'smtp_host'     => '',
        'smtp_port'     => 587,
        'smtp_user'     => '',
        'smtp_password' => '',
        'from_address'  => 'registrar@tcg.edu.ph',
        'from_name'     => 'TCGC Registrar - REGIS-TRACK',
        'max_attempts'  => 3,
    ],
];
