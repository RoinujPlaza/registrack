<?php

declare(strict_types=1);

namespace RegisTrack\Services;

use RegisTrack\Core\Auth;
use RegisTrack\Core\Csrf;
use RegisTrack\Core\ErrorHandler;
use RegisTrack\Repositories\AuditRepository;
use RegisTrack\Repositories\UserRepository;

/**
 * Authentication business rules (FR1): login with account lockout
 * (use case Table 4a), password policy, password reset round-trip.
 * Services return result arrays; controllers translate them to HTTP.
 */
final class AuthService
{
    private const PASSWORD_MIN_LENGTH = 8;
    private const PASSWORD_MAX_LENGTH = 72; // bcrypt input limit
    private const RESET_TOKEN_TTL_MINUTES = 60;

    /**
     * Attempt a login. On failure the error is intentionally generic so the
     * endpoint cannot be used to enumerate accounts.
     *
     * @return array{ok:bool, data?:array, status?:int, code?:string, message?:string}
     */
    public static function login(string $email, string $password): array
    {
        $repository = new UserRepository();
        $config = \RegisTrack\Core\AppContext::instance()->config();

        // Per-IP throttle: too many recent failures from one client -> refuse
        // before touching credentials at all.
        $throttleMax = (int) $config->get('auth.ip_throttle_max_failures', 20);
        $throttleWindow = (int) $config->get('auth.ip_throttle_window_minutes', 10);
        if ($throttleMax > 0) {
            $ipHash = AuditService::ipHash();
            if ($ipHash !== null) {
                $recentFailures = (new AuditRepository())->countRecentFailuresByIp($ipHash, $throttleWindow);
                if ($recentFailures >= $throttleMax) {
                    return [
                        'ok' => false,
                        'status' => 429,
                        'code' => 'too_many_attempts',
                        'message' => "Too many failed login attempts. Try again in {$throttleWindow} minutes.",
                    ];
                }
            }
        }

        $user = $repository->findByEmail($email);
        $invalid = static fn (): array => [
            'ok' => false,
            'status' => 401,
            'code' => 'invalid_credentials',
            'message' => 'Email or password is incorrect.',
        ];

        if ($user === null || $user['status'] !== 'active') {
            return $invalid();
        }

        // Locked accounts are rejected before credential verification.
        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            // A locked-account attempt is still a failed attempt for the IP throttle.
            AuditService::record('login.failure', 'user', null, null, [
                'email' => mb_strtolower($email),
                'reason' => 'account_locked',
            ]);

            return [
                'ok' => false,
                'status' => 423,
                'code' => 'account_locked',
                'message' => 'Account temporarily locked after repeated failed attempts. Try again later.',
            ];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $attempts = (int) $user['failed_attempts'] + 1;
            $maxAttempts = (int) $config->get('auth.max_failed_attempts', 5);
            $lockoutMinutes = (int) $config->get('auth.lockout_minutes', 15);

            $fields = ['failed_attempts' => $attempts];
            if ($attempts >= $maxAttempts) {
                $fields['locked_until'] = gmdate('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));
            }
            $repository->updateFields((int) $user['id'], $fields);

            AuditService::record(
                $attempts >= $maxAttempts ? 'login.lockout' : 'login.failure',
                'user',
                null,
                null,
                ['email' => mb_strtolower($email)]
            );

            return $invalid();
        }

        $update = ['failed_attempts' => 0, 'locked_until' => null];
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $repository->updateFields((int) $user['id'], $update);

        $sessionUser = [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'full_name' => (string) $user['full_name'],
            'student_number' => $user['student_number'],
            'program' => $user['program'],
            'status' => (string) $user['status'],
        ];
        Auth::login($sessionUser);
        AuditService::record('login.success', 'user', null, null, ['email' => $sessionUser['email']]);

        return [
            'ok' => true,
            'data' => ['user' => $sessionUser, 'csrf_token' => Csrf::token()],
        ];
    }

    /**
     * Issue a password-reset token for an account. Always reports success to
     * the caller (no account enumeration); the token is emailed when the
     * email channel is enabled, otherwise logged for development.
     */
    public static function requestPasswordReset(string $email): void
    {
        $repository = new UserRepository();
        $user = $repository->findByEmail($email);

        if ($user !== null && $user['status'] === 'active') {
            $rawToken = bin2hex(random_bytes(32));
            $repository->createPasswordReset(
                (int) $user['id'],
                hash('sha256', $rawToken),
                gmdate('Y-m-d H:i:s', time() + (self::RESET_TOKEN_TTL_MINUTES * 60))
            );

            $emailEnabled = \RegisTrack\Core\AppContext::instance()->config()->get('notifications.email_enabled', false);
            if ((bool) $emailEnabled) {
                // Phase 5 wires real SMTP dispatch; until then the request is logged.
                ErrorHandler::log("password reset email queued for {$user['email']}");
            } else {
                ErrorHandler::log("password reset token for {$user['email']}: $rawToken");
            }

            AuditService::record('password.reset_requested', 'user', null, null, ['email' => mb_strtolower($email)]);
        }
    }

    /**
     * Consume a reset token and set a new password.
     *
     * @return array{ok:bool, status?:int, code?:string, message?:string, fields?:array}
     */
    public static function resetPassword(string $rawToken, string $newPassword): array
    {
        $policyError = self::validatePasswordPolicy($newPassword);
        if ($policyError !== null) {
            return [
                'ok' => false,
                'status' => 422,
                'code' => 'validation_failed',
                'message' => 'Please correct the highlighted fields.',
                'fields' => ['password' => $policyError],
            ];
        }

        $repository = new UserRepository();
        $reset = $repository->findValidPasswordReset(hash('sha256', $rawToken));
        if ($reset === null) {
            return [
                'ok' => false,
                'status' => 400,
                'code' => 'invalid_token',
                'message' => 'This reset link is invalid, expired, or already used.',
            ];
        }

        $repository->updateFields((int) $reset['user_id'], [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ]);
        $repository->markPasswordResetUsed((int) $reset['id']);
        AuditService::record('password.reset', 'user', null, null, ['user_id' => (int) $reset['user_id']]);

        return ['ok' => true];
    }

    /** @return string|null error message, or null when the password is acceptable */
    public static function validatePasswordPolicy(string $password): ?string
    {
        $length = strlen($password);
        if ($length < self::PASSWORD_MIN_LENGTH) {
            return 'Password must be at least ' . self::PASSWORD_MIN_LENGTH . ' characters.';
        }
        if ($length > self::PASSWORD_MAX_LENGTH) {
            return 'Password must be at most ' . self::PASSWORD_MAX_LENGTH . ' characters.';
        }

        return null;
    }
}
