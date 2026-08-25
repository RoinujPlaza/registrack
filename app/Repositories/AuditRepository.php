<?php

declare(strict_types=1);

namespace RegisTrack\Repositories;

use PDO;
use RegisTrack\Core\AppContext;

/**
 * Read access to the append-only audit trail (FR5).
 * Writes happen exclusively through AuditService inside business transactions;
 * the runtime DB user cannot UPDATE or DELETE audit rows at all.
 */
final class AuditRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = AppContext::instance()->db();
    }

    /** Chronological audit entries for one request (staff/admin detail view). */
    public function listForRequest(int $requestId, int $limit = 200): array
    {
        $statement = $this->db->prepare(
            'SELECT actor_name, actor_role, action, entity, before_value, after_value, created_at
             FROM audit_events
             WHERE request_id = ?
             ORDER BY created_at ASC, id ASC
             LIMIT ?'
        );
        $statement->execute([$requestId, $limit]);

        return $statement->fetchAll();
    }

    /** Recent failed logins from one client fingerprint (per-IP login throttle). */
    public function countRecentFailuresByIp(string $ipHash, int $windowMinutes): int
    {
        $statement = $this->db->prepare(
            "SELECT COUNT(*) FROM audit_events
             WHERE action = 'login.failure'
               AND ip_hash = ?
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)"
        );
        $statement->execute([$ipHash, $windowMinutes]);

        return (int) $statement->fetchColumn();
    }
}
