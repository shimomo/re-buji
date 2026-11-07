<?php

declare(strict_types=1);

namespace ReBuji\Replies;

use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReBuji\Cron\CronJob;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\Imap\MailboxUnavailable;
use ReBuji\Settings\Settings;
use ReBuji\SystemState;
use ReBuji\Time;

/**
 * Design 6.1 step 5: reads the reply mailbox and records replies, between sending and resending so someone who just
 * answered is not asked again.
 *
 * Only mail received since the IMAP settings were saved is read, so a mailbox full of old unseen mail is not listed as
 * mail that matched nobody. A run reads at most MAX_MAILS, for at most MAX_SECONDS.
 *
 * @author shimomo
 */
final class ReplyJob implements CronJob
{
    public const int MAX_MAILS = 50;

    public const int MAX_SECONDS = 60;

    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly ReplyRecorder $recorder,
        private readonly ReplyRepository $replies,
        private readonly Settings $settings,
        private readonly SystemState $state,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        //
    }

    #[Override]
    public function name(): string
    {
        return 'replies';
    }

    #[Override]
    public function run(DateTimeImmutable $now): void
    {
        if ($this->settings->imap() === null) {
            return;
        }

        $since = (string) $this->state->get('imap.since');

        if ($since === '') {
            $since = Time::format($now);
            $this->state->set('imap.since', $since);
        }

        $started = $this->clock->now();

        try {
            foreach ($this->mailbox->unseen(Time::parse($since), self::MAX_MAILS) as $raw) {
                if ($this->clock->now()->getTimestamp() - $started->getTimestamp() > self::MAX_SECONDS) {
                    break;
                }

                try {
                    $mail = IncomingMail::parse($raw->raw);
                } catch (InvalidArgumentException $exception) {
                    $this->logger->warning('replies: unreadable mail ' . $raw->uid . ': ' . $exception->getMessage());
                    $this->replies->record(null, 'unreadable', null, '', '（読めないメール）', '', null, $now);
                    $this->mailbox->markSeen($raw);

                    continue;
                }

                // Recorded already: the run that read it stopped before marking it seen.
                if ($mail->messageId === null || !$this->replies->knows($mail->messageId)) {
                    $this->recorder->record($mail);
                }

                $this->mailbox->markSeen($raw);
            }
        } catch (MailboxUnavailable $exception) {
            $this->state->set('imap.last_error', $exception->getMessage());

            throw $exception;
        }

        $this->state->set('imap.last_checked_at', Time::format($now));
        $this->state->set('imap.last_error', '');
    }
}
