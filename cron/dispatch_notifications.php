<?php

/**
 * REGIS-TRACK — Notification email dispatcher (Phase 5 deliverable).
 *
 * Will read notifications WHERE channel='email' AND delivery_status IN
 * ('created','failed'), attempt SMTP delivery, and record sent/failed/exhausted
 * outcomes with retry backoff. Never runs inside a web request; schedule via
 * cron every few minutes.
 *
 * Intentionally a no-op until Phase 5 so the deployment layout is visible
 * without shipping unfinished logic.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script may only be run from the command line." . PHP_EOL);
    exit(1);
}

echo "[registrack] notification dispatcher: not implemented until Phase 5." . PHP_EOL;
exit(0);
