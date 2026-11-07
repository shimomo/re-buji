<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use DateTimeImmutable;
use Override;
use ReBuji\Cron\CronJob;

/**
 * Design 6.1 step 6: resends to people who have not replied. It runs after the replies are read (plan 4), so someone
 * who just answered is not asked again.
 *
 * @author shimomo
 */
final class ResendJob implements CronJob
{
    public function __construct(private readonly NotificationSender $sender)
    {
        //
    }

    #[Override]
    public function name(): string
    {
        return 'resend';
    }

    #[Override]
    public function run(DateTimeImmutable $now): void
    {
        $this->sender->sendResends();
    }
}
