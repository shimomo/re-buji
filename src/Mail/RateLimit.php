<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use DateTimeImmutable;
use ReBuji\Settings\Settings;

/**
 * How many mails may still go out this hour (design 5.3). Rental servers cap mails per hour; going over can stop the
 * account, so every mail handed to the server counts, refused ones too.
 *
 * @author shimomo
 */
final class RateLimit
{
    public function __construct(private readonly MessageRepository $messages, private readonly Settings $settings)
    {
        //
    }

    public function remaining(DateTimeImmutable $now): int
    {
        return $this->settings->int('hourly_limit') - $this->messages->countSince($now->modify('-60 minutes'));
    }

    /**
     * When the oldest mail of the last hour leaves the window, or null while mails may still go.
     */
    public function nextSlotAt(DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($this->remaining($now) > 0) {
            return null;
        }

        return $this->messages->oldestSince($now->modify('-60 minutes'))?->modify('+60 minutes');
    }
}
