<?php

declare(strict_types=1);

namespace RegisTrack\Services;

use RegisTrack\Repositories\NotificationRepository;
use RegisTrack\Repositories\SettingsRepository;
use RegisTrack\Repositories\UserRepository;

/**
 * FR4 notification fan-out. The in-system channel is authoritative and always
 * written inside the caller's transaction; the email channel is optional
 * (institution-configured) and only *queued* here — delivery is asynchronous
 * via the cron dispatcher, so a mail outage can never roll back a status
 * change.
 */
final class NotificationService
{
    /**
     * Notify one user: in-system row always, plus a queued email row when the
     * institution has enabled the email channel.
     */
    public static function notifyUser(int $requestId, int $recipientId, string $subject, string $body): void
    {
        $repository = new NotificationRepository();

        $repository->insert([
            'request_id' => $requestId,
            'recipient_id' => $recipientId,
            'channel' => 'in_system',
            'subject' => $subject,
            'body' => $body,
        ]);

        if ((new SettingsRepository())->emailEnabled()) {
            $repository->insert([
                'request_id' => $requestId,
                'recipient_id' => $recipientId,
                'channel' => 'email',
                'subject' => $subject,
                'body' => $body,
            ]);
        }
    }

    /** Notify all active registrar staff (shared-queue model). */
    public static function notifyStaffQueue(int $requestId, string $subject, string $body): void
    {
        foreach ((new UserRepository())->activeStaffIds() as $staffId) {
            self::notifyUser($requestId, $staffId, $subject, $body);
        }
    }
}
