<?php

declare(strict_types=1);

namespace RegisTrack\Core;

/**
 * CSRF protection for state-changing authenticated requests.
 * The per-session token is issued at login and must be echoed in the
 * X-CSRF-Token header on POST/PATCH/DELETE.
 */
final class Csrf
{
    /** Current (or freshly created) session token. */
    public static function token(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /** Validate the token on write methods; emits 403 and exits on failure. */
    public static function validate(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($sent) || $sent === '' || !hash_equals(self::token(), $sent)) {
            Http::error('csrf_failed', 'Missing or invalid CSRF token.', 403);
            exit;
        }
    }
}
