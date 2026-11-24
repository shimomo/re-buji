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
 * Mails are kept back from contacts and test mails for the admins' notices: one per admin, but no more than a tenth of
 * the limit (reserve()). The notice that contacts are held back by the limit (design 6.2) could otherwise never go
 * out, since it is needed exactly when the limit is used up.
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
    private function reserve(): int
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
     * When a person with that many addresses may be sent to, or null while they may be now. The sender writes to all
     * of a person's addresses at once, so it waits until enough mails of the last hour have left the window.
     */
    public function nextSlotFor(DateTimeImmutable $now, int $mails): ?DateTimeImmutable
    {
        $short = $mails - $this->remaining($now);

        if ($short <= 0) {
            return null;
        }

        return $this->messages->nthAttemptSince($now->modify('-60 minutes'), $short)?->modify('+60 minutes');
    }
}
