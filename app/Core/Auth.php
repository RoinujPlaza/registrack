<?php

declare(strict_types=1);

namespace RegisTrack\Core;

/**
 * Session-based authentication and role authorization (FR1).
 * Every guarded endpoint funnels through requireUser()/requireRole(), so the
 * authorization check cannot be forgotten or bypassed by a new endpoint.
 */
final class Auth
{
    /** @return array|null authenticated session user, or null */
    public static function user(): ?array
    {
        return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
    }

    /**
     * Require an authenticated session (enforces the idle timeout).
     * Emits a 401 error envelope and exits when unauthenticated.
     *
     * @return array session user {id, email, role, full_name}
     */
    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            Http::error('unauthenticated', 'Authentication required.', 401);
            exit;
        }

        $idleLimitMinutes = (int) AppContext::instance()->config()->get('auth.session_idle_minutes', 30);
        $lastActivity = (int) ($_SESSION['last_activity'] ?? time());
        if ($idleLimitMinutes > 0 && (time() - $lastActivity) > ($idleLimitMinutes * 60)) {
            self::destroySession();
            Http::error('session_expired', 'Session expired due to inactivity. Please log in again.', 401);
            exit;
        }

        // Absolute lifetime cap: even an active session must eventually die.
        $maxMinutes = (float) AppContext::instance()->config()->get('auth.session_max_minutes', 480);
        $createdAt = isset($_SESSION['session_created_at']) ? (int) $_SESSION['session_created_at'] : null;
        if ($maxMinutes > 0 && $createdAt !== null && (time() - $createdAt) > (int) ($maxMinutes * 60)) {
            self::destroySession();
            Http::error('session_expired', 'Session has reached its maximum lifetime. Please log in again.', 401);
            exit;
        }

        $_SESSION['last_activity'] = time();

        return $user;
    }

    /**
     * Require an authenticated session with one of the given roles.
     * Emits 401 (unauthenticated) or 403 (wrong role) and exits on failure.
     *
     * @param string[] $roles
     * @return array session user
     */
    public static function requireRole(array $roles): array
    {
        $user = self::requireUser();
        if (!in_array($user['role'], $roles, true)) {
            Http::error('forbidden', 'Your role is not permitted to perform this action.', 403);
            exit;
        }

        return $user;
    }

    /**
     * Establish an authenticated session (fixation-safe).
     *
     * @param array{id:int,email:string,role:string,full_name:string,student_number:?string,program:?string,status:string} $user
     */
    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        $_SESSION['last_activity'] = time();
        $_SESSION['session_created_at'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    /** Destroy the session and its cookie. */
    public static function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
