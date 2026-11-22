<?php

declare(strict_types=1);

namespace ReBuji\Replies;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReBuji\Config\Environment;
use ReBuji\Cron\CronJob;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\Imap\MailboxUnavailable;
use ReBuji\Mail\MessageRepository;
use ReBuji\Settings\Settings;
use ReBuji\SystemState;
use ReBuji\Time;
use Throwable;

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

    /**
     * How long a reply to a test mail is left for bin/console check --wait-reply to find (Docker only).
     */
    public const int TEST_REPLY_MINUTES = 60;

    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly ReplyRecorder $recorder,
        private readonly ReplyRepository $replies,
        private readonly Settings $settings,
        private readonly SystemState $state,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly MessageRepository $messages,
        private readonly Environment $environment,
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

                // One mail that cannot be read or recorded must not stop the replies behind it, run after run: it is
                // listed for the admins instead. A failure of the mailbox itself still stops the run.
                try {
                    $mail = IncomingMail::parse($raw->raw);
                    $test = $this->testSentAt($mail);

                    if ($test !== null && $now->getTimestamp() - $test->getTimestamp() < self::TEST_REPLY_MINUTES * 60) {
                        // bin/console check --wait-reply may be waiting for it: left unseen for its finder.
                        continue;
                    }

                    // Recorded already: the run that read it stopped before marking it seen, or the same mail came
                    // twice. A mail without a Message-ID is known by its text. A reply to an old test mail answers
                    // nobody, so it is not listed as unmatched either.
                    if ($test === null && !$this->replies->knows($mail->key)) {
                        $this->recorder->record($mail);
                    }
                } catch (MailboxUnavailable $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    $this->logger->warning('replies: unreadable mail ' . $raw->uid . ': ' . $exception->getMessage());
                    // Known by its text, so that reading it again (it fails the same way) lists it once.
                    $key = IncomingMail::keyOf($raw->raw);

                    if (!$this->replies->knows($key)) {
                        $this->replies->record(null, 'unreadable', null, '', '（読めないメール）', '', $key, $now);
                    }
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

    /**
     * When the test mail this reply answers was sent. Only in Docker, where bin/console check sends and waits for one;
     * the rental copy lists such replies as before.
     */
    private function testSentAt(IncomingMail $mail): ?DateTimeImmutable
    {
        if (!$this->environment->enabled() || $mail->kind() !== IncomingMail::REPLY) {
            return null;
        }

        return $this->messages->testSentAt($mail->references);
    }
}
