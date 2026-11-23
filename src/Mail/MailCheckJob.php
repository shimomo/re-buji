<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use DateTimeImmutable;
use Override;
use Psr\Log\LoggerInterface;
use ReBuji\Cron\CronJob;
use ReBuji\Settings\Settings;

/**
 * Logs in to the sending account when SendingAccount says it is time, without sending anything: between quakes nothing
 * else would find a broken account (plan 23). The sender records the outcome.
 *
 * It runs after the jobs that send, so it never delays a contact, and before WatchJob, so an account that works again
 * is known before the watch decides whether to send.
 *
 * @author shimomo
 */
final class MailCheckJob implements CronJob
{
    public function __construct(
        private readonly Settings $settings,
        private readonly SendingAccount $account,
        private readonly MailLogin $login,
        private readonly LoggerInterface $logger,
    ) {
        //
    }

    #[Override]
    public function name(): string
    {
        return 'mail-check';
    }

    #[Override]
    public function run(DateTimeImmutable $now): void
    {
        // Without settings the home page already says so, and there is nothing to log in to.
        if ($this->settings->smtp() === null || !$this->account->isDue($now)) {
            return;
        }

        try {
            $this->login->logIn();
        } catch (MailUnavailable $exception) {
            // Recorded by the sender and shown on the home page. Thrown on, it would show again as a cron error.
            $this->logger->warning('mail-check: cannot log in: ' . $exception->getMessage());
        }
    }
}
