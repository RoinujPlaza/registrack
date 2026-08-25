<?php

declare(strict_types=1);

namespace RegisTrack\Repositories;

use PDO;
use PDOStatement;
use RegisTrack\Core\AppContext;

/**
 * All SQL for document requests, their processing history, and the
 * student-facing queries around them. Status transitions themselves live in
 * the workflow engine (Phase 4); this repository only persists.
 */
final class RequestRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = AppContext::instance()->db();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT r.*, t.name AS document_type_name
             FROM requests r
             JOIN document_types t ON t.id = r.document_type_id
             WHERE r.id = ?
             LIMIT 1'
        );
        $statement->execute([$id]);

        return $this->one($statement);
    }

    public function findByTrackingNumber(string $trackingNumber): ?array
    {
        $statement = $this->db->prepare(
            'SELECT r.*, t.name AS document_type_name
             FROM requests r
             JOIN document_types t ON t.id = r.document_type_id
             WHERE r.tracking_number = ?
             LIMIT 1'
        );
        $statement->execute([$trackingNumber]);

        return $this->one($statement);
    }

    /** Ownership-enforced lookup: returns the request only when it belongs to the student. */
    public function findByTrackingForStudent(string $trackingNumber, int $studentId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT r.*, t.name AS document_type_name
             FROM requests r
             JOIN document_types t ON t.id = r.document_type_id
             WHERE r.tracking_number = ? AND r.student_id = ?
             LIMIT 1'
        );
        $statement->execute([$trackingNumber, $studentId]);

        return $this->one($statement);
    }

    /** Idempotent-replay lookup: the request created earlier with this key. */
    public function findIdempotent(int $studentId, string $idempotencyKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT r.*, t.name AS document_type_name
             FROM requests r
             JOIN document_types t ON t.id = r.document_type_id
             WHERE r.student_id = ? AND r.idempotency_key = ?
             LIMIT 1'
        );
        $statement->execute([$studentId, $idempotencyKey]);

        return $this->one($statement);
    }

    /**
     * Most recent active request of the same type inside the duplicate window
     * (Decision #5: warning only, cancelled/rejected requests do not count).
     */
    public function duplicateWithinWindow(int $studentId, int $documentTypeId, int $windowDays): ?array
    {
        $statement = $this->db->prepare(
            "SELECT tracking_number, DATE(submitted_at) AS submitted_date
             FROM requests
             WHERE student_id = ? AND document_type_id = ?
               AND status NOT IN ('cancelled', 'rejected')
               AND submitted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
             ORDER BY submitted_at DESC
             LIMIT 1"
        );
        $statement->execute([$studentId, $documentTypeId, $windowDays]);

        return $this->one($statement);
    }

    /**
     * Paginated list of one student's own requests.
     *
     * @return array{items: array<int, array>, total: int}
     */
    public function listForStudent(int $studentId, ?string $status, int $page, int $pageSize): array
    {
        $where = ['student_id = ?'];
        $params = [$studentId];
        if ($status !== null && $status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countStatement = $this->db->prepare("SELECT COUNT(*) FROM requests $whereSql");
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $listStatement = $this->db->prepare(
            "SELECT r.*, t.name AS document_type_name
             FROM requests r
             JOIN document_types t ON t.id = r.document_type_id
             $whereSql
             ORDER BY r.submitted_at DESC, r.id DESC
             LIMIT ? OFFSET ?"
        );
        $listStatement->execute(array_merge($params, [$pageSize, ($page - 1) * $pageSize]));

        return ['items' => $listStatement->fetchAll(), 'total' => $total];
    }

    /**
     * Insert a request row. Must be called inside an open transaction so the
     * sibling history/audit/notification rows commit atomically with it.
     */
    public function insertRequest(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO requests
                (tracking_number, student_id, document_type_id, quantity, purpose,
                 target_release_date, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $data['tracking_number'],
            $data['student_id'],
            $data['document_type_id'],
            $data['quantity'],
            $data['purpose'],
            $data['target_release_date'],
            $data['idempotency_key'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Append a processing-history row (same transaction as the status write). */
    public function insertHistory(array $data): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO request_history
                (request_id, prev_status, new_status, actor_id, actor_name, actor_role, remark)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $data['request_id'],
            $data['prev_status'],
            $data['new_status'],
            $data['actor_id'],
            $data['actor_name'],
            $data['actor_role'],
            $data['remark'],
        ]);
    }

    /** Chronological processing history for a request (FR3). */
    public function historyForRequest(int $requestId): array
    {
        $statement = $this->db->prepare(
            'SELECT prev_status, new_status, actor_name, actor_role, remark, created_at
             FROM request_history
             WHERE request_id = ?
             ORDER BY created_at ASC, id ASC'
        );
        $statement->execute([$requestId]);

        return $statement->fetchAll();
    }

    /**
     * Optimistic-concurrency cancel: only succeeds while the row is still
     * Pending at the version the actor saw. Returns affected row count.
     */
    public function cancelPending(int $requestId, int $studentId, int $version, string $remark): int
    {
        $statement = $this->db->prepare(
            "UPDATE requests
             SET status = 'cancelled', current_remark = ?, version = version + 1
             WHERE id = ? AND student_id = ? AND status = 'pending' AND version = ?"
        );
        $statement->execute([$remark, $requestId, $studentId, $version]);

        return $statement->rowCount();
    }

    /**
     * Optimistic-concurrency status transition (Phase 4 workflow engine).
     * The version guard fails safely when the row changed since it was read.
     * Sets released_at automatically when the target status is 'released'.
     * Returns affected row count (0 = concurrent modification).
     */
    public function transitionUpdate(int $requestId, int $version, string $newStatus, ?string $remark): int
    {
        $statement = $this->db->prepare(
            "UPDATE requests
             SET status = ?, current_remark = ?, version = version + 1,
                 released_at = CASE WHEN ? = 'released' THEN UTC_TIMESTAMP() ELSE released_at END
             WHERE id = ? AND version = ?"
        );
        $statement->execute([$newStatus, $remark, $newStatus, $requestId, $version]);

        return $statement->rowCount();
    }

    /**
     * Paginated staff queue with filters (basis of FR6 search).
     * Oldest first: the registrar works a FIFO queue.
     *
     * @param array{status?:string, q?:string, document_type_id?:int, date_from?:string, date_to?:string} $filters
     * @return array{items: array<int, array>, total: int}
     */
    public function listForStaff(array $filters, int $page, int $pageSize): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(r.tracking_number LIKE ? OR s.full_name LIKE ? OR s.student_number LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like);
        }
        if (!empty($filters['document_type_id'])) {
            $where[] = 'r.document_type_id = ?';
            $params[] = $filters['document_type_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'r.submitted_at >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'r.submitted_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $filters['date_to'];
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $countStatement = $this->db->prepare(
            "SELECT COUNT(*)
             FROM requests r
             JOIN users s ON s.id = r.student_id
             $whereSql"
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $listStatement = $this->db->prepare(
            "SELECT r.id, r.tracking_number, r.status, r.quantity, r.purpose,
                    r.target_release_date, r.current_remark, r.version, r.submitted_at,
                    t.name AS document_type_name,
                    s.full_name AS student_name, s.student_number, s.email AS student_email
             FROM requests r
             JOIN document_types t ON t.id = r.document_type_id
             JOIN users s ON s.id = r.student_id
             $whereSql
             ORDER BY r.submitted_at ASC, r.id ASC
             LIMIT ? OFFSET ?"
        );
        $listStatement->execute(array_merge($params, [$pageSize, ($page - 1) * $pageSize]));

        return ['items' => $listStatement->fetchAll(), 'total' => $total];
    }

    /** @return array|null */
    private function one(PDOStatement $statement): ?array
    {
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }
}
