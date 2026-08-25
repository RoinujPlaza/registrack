<?php

declare(strict_types=1);

namespace RegisTrack\Mail;

use RuntimeException;

/** Thrown when an email delivery attempt fails; dispatcher records the error. */
final class MailException extends RuntimeException
{
}
