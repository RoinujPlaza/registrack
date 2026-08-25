<?php

declare(strict_types=1);

namespace RegisTrack\Repositories;

use PDO;
use PDOStatement;
use RegisTrack\Core\AppContext;

/**
 * All SQL for user accounts and password-reset tokens.
 * Prepared statements only; callers pass values, never SQL fragments.
 */
final class UserRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = AppContext::instance()->db();
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $statement->execute([mb_strtolower($email)]);

        return $this->one($statement);
    }

    public function findById(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $statement->execute([$id]);

        return $this->one($statement);
    }

    public function emailExists(string $email): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $statement->execute([mb_strtolower($email)]);

        return $statement->fetchColumn() !== false;
    }

    public function studentNumberExists(string $studentNumber): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM users WHERE student_number = ? LIMIT 1');
        $statement->execute([$studentNumber]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array{email:string, password_hash:string, role:string, full_name:string,
     *              student_number:?string, program:?string, status:string} $data
     */
    public function create(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO users (email, password_hash, role, status, student_number, full_name, program)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            mb_strtolower($data['email']),
            $data['password_hash'],
            $data['role'],
            $data['status'],
            $data['student_number'],
            $data['full_name'],
            $data['program'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a whitelist of columns. Column names come from code (never user input);
     * values are bound parameters.
     *
     * @param array<string, mixed> $fields e.g. ['full_name' => 'New Name', 'status' => 'disabled']
     */
    public function updateFields(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $sets = [];
        $params = [];
        foreach ($fields as $column => $value) {
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
        $params[] = $id;

        $statement = $this->db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $statement->execute($params);
    }

    /** Number of other active admins (last-admin guard for role/status changes). */
    public function countOtherActiveAdmins(int $excludeUserId): int
    {
        $statement = $this->db->prepare(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?"
        );
        $statement->execute([$excludeUserId]);

        return (int) $statement->fetchColumn();
    }

    /** IDs of active registrar staff (shared-queue notification recipients). */
    public function activeStaffIds(): array
    {
        $statement = $this->db->query(
            "SELECT id FROM users WHERE role = 'staff' AND status = 'active'"
        );

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Paginated, filterable user list for the admin console.
     *
     * @param array{role?:string, q?:string} $filters
     * @return array{items: array<int, array>, total: int}
     */
    public function listUsers(array $filters, int $page, int $pageSize): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['role'])) {
            $where[] = 'role = ?';
            $params[] = $filters['role'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(email LIKE ? OR full_name LIKE ? OR student_number LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like);
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $countStatement = $this->db->prepare("SELECT COUNT(*) FROM users $whereSql");
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $listStatement = $this->db->prepare(
            "SELECT id, email, role, status, student_number, full_name, program, created_at
             FROM users $whereSql
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?"
        );
        $listStatement->execute(array_merge($params, [$pageSize, ($page - 1) * $pageSize]));

        return ['items' => $listStatement->fetchAll(), 'total' => $total];
    }

    // -- Password reset tokens -------------------------------------------------

    public function createPasswordReset(int $userId, string $tokenHash, string $expiresAtUtc): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $statement->execute([$userId, $tokenHash, $expiresAtUtc]);
    }

    public function findValidPasswordReset(string $tokenHash): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM password_resets
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $statement->execute([$tokenHash]);

        return $this->one($statement);
    }

    public function markPasswordResetUsed(int $resetId): void
    {
        $statement = $this->db->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = ?');
        $statement->execute([$resetId]);
    }

    /** Strip secrets before a user row leaves the application. */
    public static function sanitize(array $user): array
    {
        unset($user['password_hash'], $user['failed_attempts'], $user['locked_until']);
        return $user;
    }

    /** @return array|null */
    private function one(PDOStatement $statement): ?array
    {
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }
}
