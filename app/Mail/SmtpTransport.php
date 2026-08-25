<?php

declare(strict_types=1);

namespace RegisTrack\Mail;

use RegisTrack\Core\Config;

/**
 * Minimal SMTP client (RFC 5321) with STARTTLS and AUTH LOGIN — enough for an
 * institutional relay without external dependencies (zero-cost constraint).
 * Every protocol violation throws MailException so the dispatcher can record
 * and retry the notification.
 */
final class SmtpTransport implements MailTransportInterface
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromAddress;
    private string $fromName;
    private bool $startTls;
    private int $timeoutSeconds;

    public function __construct(array $options)
    {
        $this->host = (string) $options['host'];
        $this->port = (int) $options['port'];
        $this->username = (string) ($options['username'] ?? '');
        $this->password = (string) ($options['password'] ?? '');
        $this->fromAddress = (string) $options['from_address'];
        $this->fromName = (string) ($options['from_name'] ?? '');
        $this->startTls = (bool) ($options['start_tls'] ?? true);
        $this->timeoutSeconds = (int) ($options['timeout_seconds'] ?? 10);
    }

    public static function fromConfig(Config $config): self
    {
        return new self([
            'host' => (string) $config->get('notifications.smtp_host', ''),
            'port' => (int) $config->get('notifications.smtp_port', 587),
            'username' => (string) $config->get('notifications.smtp_user', ''),
            'password' => (string) $config->get('notifications.smtp_password', ''),
            'from_address' => (string) $config->get('notifications.from_address', 'registrar@localhost'),
            'from_name' => (string) $config->get('notifications.from_name', 'REGIS-TRACK'),
        ]);
    }

    public function send(string $to, string $subject, string $textBody): void
    {
        $socket = @stream_socket_client(
            'tcp://' . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeoutSeconds
        );
        if ($socket === false) {
            throw new MailException("SMTP connect to {$this->host}:{$this->port} failed: $errstr");
        }
        stream_set_timeout($socket, $this->timeoutSeconds);

        try {
            $this->expect($socket, [220], 'greeting');

            $capabilities = $this->ehlo($socket, 'registrack.local');

            if ($this->startTls && str_contains(strtolower($capabilities), 'starttls')) {
                $this->command($socket, 'STARTTLS', [220]);
                $tlsEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($tlsEnabled !== true) {
                    throw new MailException('STARTTLS negotiation failed.');
                }
                $this->ehlo($socket, 'registrack.local');
            }

            if ($this->username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($this->username), [334]);
                $this->command($socket, base64_encode($this->password), [235]);
            }

            $this->command($socket, 'MAIL FROM:<' . $this->fromAddress . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $message = MailMessage::build($this->fromAddress, $this->fromName, $to, $subject, $textBody);
            $payload = $message['headers'] . "\r\n\r\n" . $message['body'];
            fwrite($socket, $payload . "\r\n.\r\n");
            $this->expect($socket, [250], 'message acceptance');

            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    /** Send a command and validate the response code. */
    private function command($socket, string $command, array $expectedCodes): string
    {
        fwrite($socket, $command . "\r\n");

        return $this->expect($socket, $expectedCodes, $command);
    }

    /** EHLO returns multiline capabilities; returns the full response text. */
    private function ehlo($socket, string $hostname): string
    {
        fwrite($socket, 'EHLO ' . $hostname . "\r\n");
        return $this->expect($socket, [250], 'EHLO');
    }

    /** Read a (possibly multiline) response; throws when the code is unexpected. */
    private function expect($socket, array $expectedCodes, string $stage): string
    {
        $response = '';
        $code = 0;
        do {
            $line = fgets($socket, 1024);
            if ($line === false) {
                throw new MailException("SMTP read failed during $stage (timeout or closed connection).");
            }
            $response .= $line;
            if (preg_match('/^(\d{3})[ -]/', $line, $m) === 1) {
                $code = (int) $m[1];
            }
        } while (preg_match('/^\d{3}-/', $line) === 1);

        if (!in_array($code, $expectedCodes, true)) {
            throw new MailException("SMTP error during $stage: expected "
                . implode('/', $expectedCodes) . ", got $code. Response: " . trim($response));
        }

        return $response;
    }
}
