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

    public bool $postponed = false;

    public int $deferredInARow = 0;

    public function __construct(public readonly DateTimeImmutable $now)
    {
        //
    }

    /**
     * Whether the step should not move on to the next person.
     */
    public function mustStop(): bool
    {
        return $this->unavailable || $this->postponed || $this->deferredInARow >= NotificationSender::DEFERRALS_IN_A_ROW;
    }
}
