<?php

declare(strict_types=1);

namespace RegisTrack\Mail;

use RegisTrack\Core\Config;

/**
 * Chooses the mail transport from configuration:
 * an empty smtp_host selects the FileTransport (dev/test); otherwise SMTP.
 */
final class MailerFactory
{
    public static function create(Config $config): MailTransportInterface
    {
        if ((string) $config->get('notifications.smtp_host', '') === '') {
            return FileTransport::fromConfig($config);
        }

        return SmtpTransport::fromConfig($config);
    }
}
