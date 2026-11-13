<?php

declare(strict_types=1);

namespace ReBuji\Monitor;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReBuji\Auth\AdminRepository;
use ReBuji\Cron\CronJob;
use ReBuji\Mail\MailSender;
use ReBuji\Mail\MailUnavailable;
use ReBuji\Mail\MessageRepository;
use ReBuji\Mail\NotificationMail;
use ReBuji\Mail\RateLimit;
use ReBuji\Mail\RecipientRefused;
use ReBuji\Quakes\NotificationRepository;
use ReBuji\Replies\ReplyReading;
use ReBuji\SystemState;
use ReBuji\Time;

/**
 * Design 6.2: mails the admins when the home page shows a new problem, since nobody may be looking at it. Each
 * problem is told once, and again only if it changes or comes back after clearing.
 *
 * @author shimomo
 */
final class WatchJob implements CronJob
{
    /**
     * Attempts are at most this far apart: while sending fails, every attempt would wait for the same timeout.
     */
    public const int RETRY_MINUTES = 60;

    private const array KEYS = ['feed_gap', 'feed_failed', 'mail', 'imap', 'imap_stale', 'waiting'];

    public function __construct(
        private readonly SystemState $state,
        private readonly NotificationRepository $notifications,
        private readonly RateLimit $limit,
        private readonly AdminRepository $admins,
        private readonly NotificationMail $mails,
        private readonly MailSender $sender,
        private readonly MessageRepository $messages,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ReplyReading $reading,
    ) {
        //
    }

    #[Override]
    public function name(): string
    {
        return 'watch';
    }

    #[Override]
    public function run(DateTimeImmutable $now): void
    {
        $problems = $this->problems($now);

        foreach (self::KEYS as $key) {
            if (!isset($problems[$key]) && (string) $this->state->get('watch.sent.' . $key) !== '') {
                $this->state->set('watch.sent.' . $key, '');
            }
        }

        $new = array_filter($problems, fn(string $text, string $key): bool => (string) $this->state->get('watch.sent.' . $key) !== $text, ARRAY_FILTER_USE_BOTH);
        // When the limit leaves no room for every admin, the first ones are told: someone hears of it.
        $admins = array_slice($this->admins->all(), 0, max(0, $this->limit->remainingForNotices($now)));
        $lastAttempt = (string) $this->state->get('watch.last_attempt_at');

        if ($new === [] || $admins === []) {
            return;
        }

        if ($lastAttempt !== '' && $now->getTimestamp() - Time::parse($lastAttempt)->getTimestamp() < self::RETRY_MINUTES * 60) {
            return;
        }

        $this->state->set('watch.last_attempt_at', Time::format($now));

        foreach ($admins as $admin) {
            try {
                $mail = $this->mails->watchMail($admin['email'], array_values($new));

                try {
                    $this->sender->send($mail);
                } catch (RecipientRefused $exception) {
                    $this->messages->record(null, 'watch', $admin['email'], $mail->messageId, false, $exception->getMessage(), $now);

                    continue;
                }

                $this->messages->record(null, 'watch', $admin['email'], $mail->messageId, true, '', $now);
            } catch (MailUnavailable $exception) {
                $this->logger->error('watch: cannot tell the admins: ' . $exception->getMessage());

                return;
            }
        }

        foreach ($new as $key => $text) {
            $this->state->set('watch.sent.' . $key, $text);
        }
    }

    /**
     * The problems the home page shows, by key, in its words. The limit's wording leaves out counts and times, which
     * change every run.
     *
     * @return array<string, string>
     */
    private function problems(DateTimeImmutable $now): array
    {
        $problems = [];
        $gap = (string) $this->state->get('feed.gap_since');
        $failed = self::count(json_decode((string) $this->state->get('feed.failed_telegrams'), true));
        $mail = (string) $this->state->get('mail.last_error');
        $imap = (string) $this->state->get('imap.last_error');

        if ($gap !== '') {
            $problems['feed_gap'] = '気象庁の情報に抜けがあります（' . Time::parse($gap)->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('n月j日 G時i分') . ' 以降）。この間の地震は連絡されていない可能性があります。';
        }

        if ($failed > 0) {
            $problems['feed_failed'] = '取れなかったか読めなかった電文が ' . $failed . ' 通あります。';
        }

        if ($mail !== '') {
            $problems['mail'] = 'メールを送れていません：' . $mail;
        }

        if ($imap !== '') {
            $problems['imap'] = '返信を読めていません：' . $imap;
        }

        $stale = $this->reading->staleWarning($now);

        if ($stale !== null) {
            $problems['imap_stale'] = $stale;
        }

        if ($this->notifications->countWaiting($now) > 0 && $this->limit->nextSlotAt($now) !== null) {
            $problems['waiting'] = '1 時間の上限のため、連絡が送信待ちになっています。';
        }

        return $problems;
    }

    private static function count(mixed $list): int
    {
        return is_array($list) ? count($list) : 0;
    }
}
