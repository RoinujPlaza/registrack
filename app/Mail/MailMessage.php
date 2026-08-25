<?php

declare(strict_types=1);

namespace RegisTrack\Mail;

/** Builds RFC 5322 plain-text messages shared by all transports. */
final class MailMessage
{
    /**
     * @return array{headers: string, body: string}
     */
    public static function build(string $fromAddress, string $fromName, string $to, string $subject, string $textBody): array
    {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = implode("\r\n", [
            'From: ' . self::formatAddress($fromAddress, $fromName),
            'To: ' . $to,
            'Subject: ' . $encodedSubject,
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: REGIS-TRACK',
        ]);

        return [
            'headers' => $headers,
            // Base64 body avoids dot-stuffing and line-length issues entirely.
            'body' => chunk_split(base64_encode($textBody)),
        ];
    }

    public static function formatAddress(string $address, string $name): string
    {
        return $name === '' ? $address : "\"$name\" <$address>";
    }
}
