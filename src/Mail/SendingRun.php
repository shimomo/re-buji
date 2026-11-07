<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use DateTimeImmutable;

/**
 * One call of NotificationSender: its start time, recorded on every mail, and how it went.
 *
 * @author shimomo
 *
 * @internal
 */
final class SendingRun
{
    public bool $succeeded = false;

    public bool $unavailable = false;

    public function __construct(public readonly DateTimeImmutable $now)
    {
        //
    }
}
