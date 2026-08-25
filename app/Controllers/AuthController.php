<?php

declare(strict_types=1);

namespace RegisTrack\Controllers;

use RegisTrack\Core\Auth;
use RegisTrack\Core\Csrf;
use RegisTrack\Core\Http;
use RegisTrack\Services\AuthService;

/**
 * Authentication endpoints (FR1): login, logout, session info, password reset.
 */
final class AuthController
{
    /** POST /api/v1/auth/login */
    public static function login(array $params): void
    {
        $body = Http::jsonBody();
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        $fields = [];
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $fields['email'] = 'A valid email address is required.';
        }
        if ($password === '') {
            $fields['password'] = 'Password is required.';
        }
        if ($fields !== []) {
            Http::error('validation_failed', 'Please correct the highlighted fields.', 422, $fields);
            return;
        }

        $result = AuthService::login($email, $password);
        if ($result['ok']) {
            Http::json($result['data']);
            return;
        }

        Http::error($result['code'], $result['message'], $result['status']);
    }

    /** POST /api/v1/auth/logout */
    public static function logout(array $params): void
    {
        Auth::requireUser();
        Csrf::validate();
        Auth::destroySession();
        Http::noContent();
    }

    /** GET /api/v1/me */
    public static function me(array $params): void
    {
        $user = Auth::requireUser();
        Http::json(['user' => $user, 'csrf_token' => Csrf::token()]);
    }

    /** POST /api/v1/auth/password/reset-request */
    public static function requestPasswordReset(array $params): void
    {
        $body = Http::jsonBody();
        $email = trim((string) ($body['email'] ?? ''));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Http::error('validation_failed', 'Please correct the highlighted fields.', 422, [
                'email' => 'A valid email address is required.',
            ]);
            return;
        }

        AuthService::requestPasswordReset($email);

        // Identical response whether or not the account exists (no enumeration).
        Http::json(['message' => 'If the account exists, password reset instructions have been issued.'], 202);
    }

    /** POST /api/v1/auth/password/reset */
    public static function resetPassword(array $params): void
    {
        $body = Http::jsonBody();
        $token = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($token === '') {
            Http::error('validation_failed', 'Please correct the highlighted fields.', 422, [
                'token' => 'Reset token is required.',
            ]);
            return;
        }

        $result = AuthService::resetPassword($token, $password);
        if ($result['ok']) {
            Http::json(['message' => 'Password updated. You may now log in with your new password.']);
            return;
        }

        Http::error($result['code'], $result['message'], $result['status'], $result['fields'] ?? []);
    }
}
