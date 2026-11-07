<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use DateTimeImmutable;
use Override;
use ReBuji\Cron\CronJob;

/**
 * Design 6.1 step 4: first contacts, then the notices for cancelled quakes (a false alarm is cleared right after the
 * contacts that cannot wait).
 *
 * @author shimomo
 */
final class SendJob implements CronJob
{
    public function __construct(private readonly NotificationSender $sender)
    {
        //
    }

    #[Override]
    public function name(): string
    {
        return 'send';
    }

    #[Override]
    public function run(DateTimeImmutable $now): void
    {
        $this->sender->sendFirstContacts();
        $this->sender->sendCancelNotices();
    }
}
