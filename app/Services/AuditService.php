<?php

declare(strict_types=1);

namespace RegisTrack\Services;

use RegisTrack\Core\AppContext;
use RegisTrack\Core\Auth;
use RegisTrack\Core\Http;

/**
 * FR5 audit writer. Every auditable action goes through record(); rows are
 * append-only by design — the runtime DB user has no UPDATE/DELETE privilege
 * on audit_events (see database/grants.sql).
 */
final class AuditService
{
    /** Privacy-preserving client fingerprint shared by throttle and audit writes. */
    public static function ipHash(): ?string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return $ip === '' ? null : hash('sha256', $ip);
    }

    /**
     * @param string $action    dotted event name, e.g. login.failure, user.created
     * @param string $entity    entity type, e.g. user, request
     * @param int|null $requestId related registrar request, when applicable
     * @param array|null $before entity state before the change
     * @param array|null $after  entity state after the change (never include secrets)
     */
    public static function record(
        string $action,
        string $entity,
        ?int $requestId = null,
        ?array $before = null,
        ?array $after = null
    ): void {
        $user = Auth::user();

        $statement = AppContext::instance()->db()->prepare(
            'INSERT INTO audit_events
                (request_id, actor_id, actor_name, actor_role, action, entity,
                 before_value, after_value, ip_hash, correlation_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $requestId,
            $user['id'] ?? null,
            (string) ($user['full_name'] ?? 'anonymous'),
            (string) ($user['role'] ?? 'guest'),
            $action,
            $entity,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES),
            self::ipHash(),
            Http::correlationId(),
        ]);
    }
}
