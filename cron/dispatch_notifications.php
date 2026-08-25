<?php

/**
 * REGIS-TRACK — Notification email dispatcher (FR4, use case Table 9).
 *
 * CLI-only worker: reads queued email notifications, attempts delivery via the
 * configured transport (SMTP, or file outbox in dev/test), and records the
 * outcome with retry/backoff:
 *
 *   created -> (attempt) -> sent
 *                       -> failed  -> (next run, after backoff) -> sent | exhausted
 *
 * Exhausted rows stay flagged for manual follow-up, exactly as the use case
 * requires. Schedule via cron every few minutes, e.g.:
 *     5 * * * * php /path/to/registrack/cron/dispatch_notifications.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'This script may only be run from the command line.' . PHP_EOL);
    exit(1);
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';

use RegisTrack\Core\AppContext;
use RegisTrack\Core\ErrorHandler;
use RegisTrack\Mail\MailException;
use RegisTrack\Mail\MailerFactory;
use RegisTrack\Repositories\NotificationRepository;
use RegisTrack\Repositories\SettingsRepository;

AppContext::init($config);
ErrorHandler::register((string) $config->get('log.file', dirname(__DIR__) . '/logs/app.log'));
date_default_timezone_set('UTC');

$maxAttempts = (int) $config->get('notifications.max_attempts', 3);
$retryDelayMinutes = (int) $config->get('notifications.retry_delay_minutes', 5);
$batchLimit = 50;

$repository = new NotificationRepository();
$due = $repository->findDispatchable($batchLimit, $retryDelayMinutes);

$sent = 0;
$failed = 0;

if ($due !== []) {
    $transport = MailerFactory::create($config);

    foreach ($due as $notification) {
        try {
            $transport->send(
                (string) $notification['recipient_email'],
                (string) $notification['subject'],
                (string) $notification['body']
            );
            $repository->markSent((int) $notification['id']);
            $sent++;
        } catch (MailException $e) {
            $repository->markFailed((int) $notification['id'], $e->getMessage(), $maxAttempts);
            $failed++;
            ErrorHandler::log(sprintf(
                'dispatch failed for notification %s (to %s): %s',
                $notification['id'],
                $notification['recipient_email'],
                $e->getMessage()
            ));
        }
    }
}

(new SettingsRepository())->set('cron_last_run_at', gmdate('Y-m-d H:i:s'));

echo sprintf(
    '[registrack] dispatcher: %d due, %d sent, %d failed.',
    count($due),
    $sent,
    $failed
) . PHP_EOL;
exit($failed > 0 ? 1 : 0);
