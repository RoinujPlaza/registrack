<?php

declare(strict_types=1);

namespace RegisTrack\Controllers;

use RegisTrack\Core\Auth;
use RegisTrack\Core\Csrf;
use RegisTrack\Core\Http;
use RegisTrack\Repositories\UserRepository;
use RegisTrack\Services\AuditService;
use RegisTrack\Services\AuthService;

/**
 * Admin user management (FR1 provisioning): list, create, update accounts.
 * All endpoints are admin-only; every write is CSRF-protected and audited.
 */
final class AdminController
{
    private const ROLES = ['student', 'staff', 'admin'];
    private const STATUSES = ['active', 'disabled'];

    /** GET /api/v1/admin/users?page=&page_size=&role=&q= */
    public static function listUsers(array $params): void
    {
        Auth::requireRole(['admin']);

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($_GET['page_size'] ?? 20)));
        $role = (string) ($_GET['role'] ?? '');
        $q = trim((string) ($_GET['q'] ?? ''));

        $filters = [];
        if (in_array($role, self::ROLES, true)) {
            $filters['role'] = $role;
        }
        if ($q !== '') {
            $filters['q'] = $q;
        }

        $result = (new UserRepository())->listUsers($filters, $page, $pageSize);

        Http::json([
            'items' => array_map([UserRepository::class, 'sanitize'], $result['items']),
            'meta' => ['page' => $page, 'page_size' => $pageSize, 'total' => $result['total']],
        ]);
    }

    /** POST /api/v1/admin/users */
    public static function createUser(array $params): void
    {
        $actor = Auth::requireRole(['admin']);
        Csrf::validate();

        $body = Http::jsonBody();
        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $role = (string) ($body['role'] ?? '');
        $fullName = trim((string) ($body['full_name'] ?? ''));
        $studentNumber = isset($body['student_number']) ? trim((string) $body['student_number']) : null;
        $program = isset($body['program']) ? trim((string) $body['program']) : null;

        $fields = self::validateUserInput($email, $password, $role, $fullName, $studentNumber);
        if ($fields !== []) {
            Http::error('validation_failed', 'Please correct the highlighted fields.', 422, $fields);
            return;
        }

        $repository = new UserRepository();
        $duplicateFields = [];
        if ($repository->emailExists($email)) {
            $duplicateFields['email'] = 'An account with this email already exists.';
        }
        if ($studentNumber !== null && $studentNumber !== '' && $repository->studentNumberExists($studentNumber)) {
            $duplicateFields['student_number'] = 'An account with this student number already exists.';
        }
        if ($duplicateFields !== []) {
            Http::error('duplicate', 'Account already exists.', 409, $duplicateFields);
            return;
        }

        $id = $repository->create([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'full_name' => $fullName,
            'student_number' => $studentNumber !== '' ? $studentNumber : null,
            'program' => $program !== '' ? $program : null,
            'status' => 'active',
        ]);

        $created = $repository->findById($id) ?? [];
        AuditService::record('user.created', 'user', null, null, UserRepository::sanitize($created));

        Http::json(['user' => UserRepository::sanitize($created)], 201);
    }

    /** PATCH /api/v1/admin/users/{id} */
    public static function updateUser(array $params): void
    {
        $actor = Auth::requireRole(['admin']);
        Csrf::validate();

        $id = (int) $params['id'];
        $repository = new UserRepository();
        $target = $repository->findById($id);
        if ($target === null) {
            Http::error('not_found', 'User not found.', 404);
            return;
        }

        $body = Http::jsonBody();
        $updates = [];
        $before = UserRepository::sanitize($target);

        if (array_key_exists('full_name', $body)) {
            $fullName = trim((string) $body['full_name']);
            if ($fullName === '') {
                Http::error('validation_failed', 'Please correct the highlighted fields.', 422, [
                    'full_name' => 'Full name cannot be empty.',
                ]);
                return;
            }
            $updates['full_name'] = $fullName;
        }

        if (array_key_exists('program', $body)) {
            $updates['program'] = trim((string) $body['program']);
        }

        if (array_key_exists('student_number', $body)) {
            $studentNumber = trim((string) $body['student_number']);
            if ($studentNumber !== '' && $repository->studentNumberExists($studentNumber)) {
                Http::error('duplicate', 'Account already exists.', 409, [
                    'student_number' => 'An account with this student number already exists.',
                ]);
                return;
            }
            $updates['student_number'] = $studentNumber !== '' ? $studentNumber : null;
        }

        if (array_key_exists('password', $body)) {
            $policyError = AuthService::validatePasswordPolicy((string) $body['password']);
            if ($policyError !== null) {
                Http::error('validation_failed', 'Please correct the highlighted fields.', 422, [
                    'password' => $policyError,
                ]);
                return;
            }
            $updates['password_hash'] = password_hash((string) $body['password'], PASSWORD_DEFAULT);
        }

        $newStatus = array_key_exists('status', $body) ? (string) $body['status'] : null;
        if ($newStatus !== null) {
            if (!in_array($newStatus, self::STATUSES, true)) {
                Http::error('validation_failed', 'Please correct the highlighted fields.', 422, [
                    'status' => 'Status must be active or disabled.',
                ]);
                return;
            }
            if ($newStatus === 'disabled') {
                if ($id === (int) $actor['id']) {
                    Http::error('conflict', 'You cannot disable your own account.', 409);
                    return;
                }
                if ($target['role'] === 'admin' && $repository->countOtherActiveAdmins($id) === 0) {
                    Http::error('conflict', 'Cannot disable the last active administrator.', 409);
                    return;
                }
            }
            $updates['status'] = $newStatus;
        }

        $newRole = array_key_exists('role', $body) ? (string) $body['role'] : null;
        if ($newRole !== null) {
            if (!in_array($newRole, self::ROLES, true)) {
                Http::error('validation_failed', 'Please correct the highlighted fields.', 422, [
                    'role' => 'Role must be student, staff, or admin.',
                ]);
                return;
            }
            if ($target['role'] === 'admin' && $newRole !== 'admin'
                && $target['status'] === 'active'
                && $repository->countOtherActiveAdmins($id) === 0) {
                Http::error('conflict', 'Cannot demote the last active administrator.', 409);
                return;
            }
            $updates['role'] = $newRole;
        }

        if ($updates === []) {
            Http::error('validation_failed', 'No supported fields to update were provided.', 422, [
                'body' => 'Provide at least one of: full_name, program, student_number, password, status, role.',
            ]);
            return;
        }

        $repository->updateFields($id, $updates);
        $after = UserRepository::sanitize($repository->findById($id) ?? []);
        AuditService::record('user.updated', 'user', null, $before, $after);

        Http::json(['user' => $after]);
    }

    /**
     * Shared field validation for account creation.
     *
     * @return array<string, string> field => error (empty when valid)
     */
    private static function validateUserInput(
        string $email,
        string $password,
        string $role,
        string $fullName,
        ?string $studentNumber
    ): array {
        $errors = [];

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'A valid email address is required.';
        }

        $policyError = AuthService::validatePasswordPolicy($password);
        if ($policyError !== null) {
            $errors['password'] = $policyError;
        }

        if (!in_array($role, self::ROLES, true)) {
            $errors['role'] = 'Role must be student, staff, or admin.';
        }

        if ($fullName === '') {
            $errors['full_name'] = 'Full name is required.';
        }

        if ($role === 'student' && ($studentNumber === null || $studentNumber === '')) {
            $errors['student_number'] = 'Student accounts require a student number.';
        }

        return $errors;
    }
}
