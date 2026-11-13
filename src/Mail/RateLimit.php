<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use DateTimeImmutable;
use ReBuji\Auth\AdminRepository;
use ReBuji\Settings\Settings;

/**
 * How many mails may still go out this hour (design 5.3). Rental servers cap mails per hour; going over can stop the
 * account, so every mail handed to the server counts, refused ones too.
 *
 * One mail per admin is kept back from contacts and test mails: the notice that contacts are held back by the limit
 * (design 6.2) could otherwise never go out, since it is needed exactly when the limit is used up.
 *
 * @author shimomo
 */
final class RateLimit
{
    /**
     * The admins' notices keep at most a tenth of the hourly limit back. The limit is 10 or more, so contacts always
     * have room.
     */
    public const int RESERVE_SHARE = 10;

    public function __construct(private readonly MessageRepository $messages, private readonly Settings $settings, private readonly AdminRepository $admins)
    {
        //
    }

    /**
     * What contacts and test mails may still use.
     */
    public function remaining(DateTimeImmutable $now): int
    {
        return $this->remainingForNotices($now) - $this->reserve();
    }

    /**
     * Mails kept back for the admins' notices: one for each admin, but no more than a tenth of the limit.
     */
    public function reserve(): int
    {
        return min($this->admins->count(), intdiv($this->settings->int('hourly_limit'), self::RESERVE_SHARE));
    }

    /**
     * What the admins' notices may still use: the whole limit.
     */
    public function remainingForNotices(DateTimeImmutable $now): int
    {
        return $this->settings->int('hourly_limit') - $this->messages->countSince($now->modify('-60 minutes'));
    }

    /**
     * When the oldest mail of the last hour leaves the window, or null while contacts may still go.
     */
    public function nextSlotAt(DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($this->remaining($now) > 0) {
            return null;
        }

        return $this->messages->oldestSince($now->modify('-60 minutes'))?->modify('+60 minutes');
    }
}
