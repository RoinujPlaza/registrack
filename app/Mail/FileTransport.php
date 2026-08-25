<?php

declare(strict_types=1);

namespace RegisTrack\Mail;

use RegisTrack\Core\Config;

/**
 * Development/test transport: writes RFC 5322 messages to an outbox directory
 * instead of delivering them. Lets the dispatcher's full pipeline (queueing,
 * retries, status transitions, heartbeats) run without a real SMTP server.
 */
final class FileTransport implements MailTransportInterface
{
    private string $outboxDir;
    private string $fromAddress;
    private string $fromName;

    public function __construct(string $outboxDir, string $fromAddress, string $fromName)
    {
        $this->outboxDir = $outboxDir;
        $this->fromAddress = $fromAddress;
        $this->fromName = $fromName;
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            (string) $config->get('notifications.outbox_dir', dirname(__DIR__, 2) . '/storage/mail-outbox'),
            (string) $config->get('notifications.from_address', 'registrar@localhost'),
            (string) $config->get('notifications.from_name', 'REGIS-TRACK')
        );
    }

    public function send(string $to, string $subject, string $textBody): void
    {
        $dayDir = $this->outboxDir . '/' . gmdate('Y-m-d');
        if (!is_dir($dayDir) && !@mkdir($dayDir, 0777, true)) {
            throw new MailException("Cannot create mail outbox directory: $dayDir");
        }

        $message = MailMessage::build($this->fromAddress, $this->fromName, $to, $subject, $textBody);
        $file = $dayDir . '/' . gmdate('His') . '-' . bin2hex(random_bytes(4)) . '.eml';

        $bytes = @file_put_contents($file, $message['headers'] . "\r\n\r\n" . $message['body']);
        if ($bytes === false) {
            throw new MailException("Cannot write mail outbox file: $file");
        }
    }
}
