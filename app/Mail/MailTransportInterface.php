<?php

declare(strict_types=1);

namespace RegisTrack\Mail;

/** A mail transport delivers one message or throws MailException. */
interface MailTransportInterface
{
    /**
     * @param string $to      recipient email address
     * @param string $subject subject line
     * @param string $textBody plain-text body
     * @throws MailException on any delivery failure
     */
    public function send(string $to, string $subject, string $textBody): void;
}
