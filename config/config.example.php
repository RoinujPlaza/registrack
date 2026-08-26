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
        // Institution-local timezone for date rules (target release dates).
        'timezone' => 'Asia/Manila',
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
        // Absolute session lifetime (0 = disabled). Defence against stolen cookies.
        'session_max_minutes' => 480,
        // Per-IP login throttle: block the IP after N failed attempts in the window (0 = disabled).
        'ip_throttle_max_failures' => 20,
        'ip_throttle_window_minutes' => 10,
    ],

    'requests' => [
        // Decision #5: same student + same document type within N days -> warning only.
        'duplicate_window_days' => 30,
        'max_quantity'          => 10,
    ],

    'notifications' => [
        // Decision #6: in-system is always on; email is optional and retried by cron.
        'email_enabled' => false,
        // Empty smtp_host selects the file outbox transport (dev/test).
        'smtp_host'     => '',
        'smtp_port'     => 587,
        'smtp_user'     => '',
        'smtp_password' => '',
        'from_address'  => 'registrar@tcgc.edu.ph',
        'from_name'     => 'TCGC Registrar - REGIS-TRACK',
        'max_attempts'  => 3,
        // Minutes before a 'failed' email is retried by the dispatcher.
        'retry_delay_minutes' => 5,
        // Where FileTransport writes .eml files (dev/test only).
        'outbox_dir' => __DIR__ . '/../storage/mail-outbox',
    ],
];
