<?php

declare(strict_types=1);

namespace RegisTrack\Repositories;

use PDO;
use RegisTrack\Core\AppContext;

/**
 * All SQL for notifications (FR4). In-system rows are created inside the same
 * transaction as the business change; email rows are queued (delivery_status
 * 'created') and dispatched asynchronously by cron/dispatch_notifications.php.
 */
final class NotificationRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = AppContext::instance()->db();
    }

    /**
     * @param array{request_id:?int, recipient_id:int, channel:string,
     *              subject:string, body:string} $data
     */
    public function insert(array $data): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO notifications (request_id, recipient_id, channel, subject, body)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $data['request_id'],
            $data['recipient_id'],
            $data['channel'],
            $data['subject'],
            $data['body'],
        ]);
    }

    /**
     * Newest first, one recipient's notifications (ownership enforced).
     * The panel shows in-system items only — email rows are delivery records,
     * not panel content.
     */
    public function listForRecipient(int $recipientId, bool $unreadOnly, int $page, int $pageSize): array
    {
        $where = "recipient_id = ? AND channel = 'in_system'" . ($unreadOnly ? ' AND read_at IS NULL' : '');

        $countStatement = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE $where");
        $countStatement->execute([$recipientId]);
        $total = (int) $countStatement->fetchColumn();

        $listStatement = $this->db->prepare(
            "SELECT id, request_id, channel, subject, body, read_at, created_at
             FROM notifications
             WHERE $where
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?"
        );
        $listStatement->execute([$recipientId, $pageSize, ($page - 1) * $pageSize]);

        return ['items' => $listStatement->fetchAll(), 'total' => $total];
    }

    public function countUnread(int $recipientId): int
    {
        $statement = $this->db->prepare(
            "SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND channel = 'in_system' AND read_at IS NULL"
        );
        $statement->execute([$recipientId]);

        return (int) $statement->fetchColumn();
    }

    /** Ownership-checked lookup for the mark-read endpoint. */
    public function findForRecipient(int $id, int $recipientId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, read_at FROM notifications WHERE id = ? AND recipient_id = ? LIMIT 1'
        );
        $statement->execute([$id, $recipientId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** Idempotent mark-read; returns affected rows. */
    public function markRead(int $id, int $recipientId): int
    {
        $statement = $this->db->prepare(
            'UPDATE notifications SET read_at = UTC_TIMESTAMP()
             WHERE id = ? AND recipient_id = ? AND read_at IS NULL'
        );
        $statement->execute([$id, $recipientId]);

        return $statement->rowCount();
    }

    /**
     * Email queue: rows ready for a dispatch attempt. 'failed' rows are
     * retried after the backoff delay; 'exhausted' rows never return.
     *
     * @return array<int, array{id:string, subject:string, body:string, recipient_email:string}>
     */
    public function findDispatchable(int $limit, int $retryDelayMinutes): array
    {
        $statement = $this->db->prepare(
            "SELECT n.id, n.subject, n.body, u.email AS recipient_email
             FROM notifications n
             JOIN users u ON u.id = n.recipient_id
             WHERE n.channel = 'email'
               AND n.delivery_status IN ('created', 'failed')
               AND (n.last_attempt_at IS NULL
                    OR n.last_attempt_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE))
             ORDER BY n.created_at ASC
             LIMIT ?"
        );
        $statement->execute([$retryDelayMinutes, $limit]);

        return $statement->fetchAll();
    }

    public function markSent(int $id): void
    {
        $statement = $this->db->prepare(
            "UPDATE notifications
             SET delivery_status = 'sent', attempts = attempts + 1,
                 last_attempt_at = UTC_TIMESTAMP(), last_error = NULL
             WHERE id = ?"
        );
        $statement->execute([$id]);
    }

    /** Increments attempts; flips to 'exhausted' once the ceiling is reached (Table 9 flow). */
    public function markFailed(int $id, string $error, int $maxAttempts): void
    {
        $statement = $this->db->prepare(
            "UPDATE notifications
             SET delivery_status = CASE WHEN attempts + 1 >= ? THEN 'exhausted' ELSE 'failed' END,
                 attempts = attempts + 1,
                 last_attempt_at = UTC_TIMESTAMP(),
                 last_error = ?
             WHERE id = ?"
        );
        $statement->execute([$maxAttempts, mb_substr($error, 0, 500), $id]);
    }
}
