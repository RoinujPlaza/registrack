<?php

declare(strict_types=1);

namespace RegisTrack\Controllers;

use RegisTrack\Core\Auth;
use RegisTrack\Core\Csrf;
use RegisTrack\Core\Http;
use RegisTrack\Core\Time;
use RegisTrack\Repositories\NotificationRepository;

/**
 * In-system notification panel (FR4): list own notifications, mark read.
 * Ownership is enforced in every query (recipient_id = session user).
 */
final class NotificationController
{
    /** GET /api/v1/notifications?page=&page_size=&unread_only=1 */
    public static function list(array $params): void
    {
        $user = Auth::requireUser();
        $repository = new NotificationRepository();

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($_GET['page_size'] ?? 20)));
        $unreadOnly = (($_GET['unread_only'] ?? '') === '1');

        $result = $repository->listForRecipient((int) $user['id'], $unreadOnly, $page, $pageSize);

        Http::json([
            'items' => array_map(static fn (array $notification): array => [
                'id' => (int) $notification['id'],
                'request_id' => $notification['request_id'] !== null ? (int) $notification['request_id'] : null,
                'tracking_number' => isset($notification['tracking_number']) && $notification['tracking_number'] !== null
                    ? (string) $notification['tracking_number']
                    : null,
                'channel' => (string) $notification['channel'],
                'subject' => (string) $notification['subject'],
                'body' => (string) $notification['body'],
                'read_at' => Time::toManila(
                    isset($notification['read_at']) && $notification['read_at'] !== null
                        ? (string) $notification['read_at']
                        : null
                ),
                'created_at' => Time::toManila(
                    isset($notification['created_at']) ? (string) $notification['created_at'] : null
                ),
            ], $result['items']),
            'meta' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $result['total'],
                'unread' => $repository->countUnread((int) $user['id']),
            ],
        ]);
    }

    /** POST /api/v1/notifications/{id}/read */
    public static function markRead(array $params): void
    {
        $user = Auth::requireUser();
        Csrf::validate();

        $id = (int) $params['id'];
        $repository = new NotificationRepository();

        if ($repository->findForRecipient($id, (int) $user['id']) === null) {
            Http::error('not_found', 'Notification not found.', 404);
            return;
        }

        $repository->markRead($id, (int) $user['id']);

        Http::json([
            'id' => $id,
            'unread' => $repository->countUnread((int) $user['id']),
        ]);
    }

    /** POST /api/v1/notifications/read-all — auto-acknowledge the panel. */
    public static function markAllRead(array $params): void
    {
        $user = Auth::requireUser();
        Csrf::validate();

        $repository = new NotificationRepository();
        $marked = $repository->markAllRead((int) $user['id']);

        Http::json([
            'marked' => $marked,
            'unread' => $repository->countUnread((int) $user['id']),
        ]);
    }
}
