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
use ReBuji\Mail\RecipientDeferred;
use ReBuji\Mail\RecipientRefused;
use ReBuji\Quakes\NotificationRepository;
use ReBuji\Replies\ReplyReading;
use ReBuji\SystemState;
use ReBuji\Time;

/**
 * Design 6.2: mails the admins when the home page shows a new problem, since nobody may be looking at it. Each
 * problem is told once, and again only if it changes or comes back after clearing.
 *
 * A problem is told at most once in RETRY_MINUTES, even when its words change every run (a different address deferred
 * each time, in a big quake) or it keeps clearing and coming back: those mails would take the hour's room from the
 * contacts. A problem not told within that time goes out on the next run.
 *
 * @author shimomo
 */
final class WatchJob implements CronJob
{
    /**
     * How long a problem told waits before it is told again, changed or come back; and how long the next attempt
     * waits after one that could not send (while sending fails, every attempt would wait for the same timeout).
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

        $new = array_filter(
            $problems,
            fn(string $text, string $key): bool => (string) $this->state->get('watch.sent.' . $key) !== $text && !$this->isRecent('watch.told_at.' . $key, $now),
            ARRAY_FILTER_USE_BOTH,
        );
        // When the limit leaves no room for every admin, the first ones are told: someone hears of it.
        $admins = array_slice($this->admins->all(), 0, max(0, $this->limit->remainingForNotices($now)));

        if ($new === [] || $admins === [] || $this->isRecent('watch.last_attempt_at', $now)) {
            return;
        }

        $this->state->set('watch.last_attempt_at', Time::format($now));
        $accepted = 0;
        $deferred = 0;

        foreach ($admins as $admin) {
            try {
                $mail = $this->mails->watchMail($admin['email'], array_values($new));

                try {
                    $this->sender->send($mail);
                } catch (RecipientRefused $exception) {
                    $this->messages->record(null, 'watch', $admin['email'], $mail->messageId, false, $exception->getMessage(), $now);

                    continue;
                } catch (RecipientDeferred $exception) {
                    // Refused for now (in a big quake, mail backs up): the other admins are still told.
                    $this->messages->record(null, 'watch', $admin['email'], $mail->messageId, false, $exception->getMessage(), $now);
                    $this->logger->warning('watch: ' . $admin['email'] . ' deferred: ' . $exception->getMessage());
                    $deferred++;

                    continue;
                }

                $this->messages->record(null, 'watch', $admin['email'], $mail->messageId, true, '', $now);
                $accepted++;
            } catch (MailUnavailable $exception) {
                $this->logger->error('watch: cannot tell the admins: ' . $exception->getMessage());

                return;
            }
        }

        // Nobody took it, and someone may later: the problems are not told yet, and the attempt waits as one that
        // could not send.
        if ($accepted === 0 && $deferred > 0) {
            return;
        }

        foreach ($new as $key => $text) {
            $this->state->set('watch.sent.' . $key, $text);
            $this->state->set('watch.told_at.' . $key, Time::format($now));
        }

        $this->state->set('watch.last_attempt_at', '');
    }

    /**
     * Whether the time in the given state is less than RETRY_MINUTES old.
     */
    private function isRecent(string $name, DateTimeImmutable $now): bool
    {
        $at = (string) $this->state->get($name);

        return $at !== '' && $now->getTimestamp() - Time::parse($at)->getTimestamp() < self::RETRY_MINUTES * 60;
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
        $imap = $this->reading->lastingError($now);

        if ($gap !== '') {
            $problems['feed_gap'] = '気象庁の情報に抜けがあります（' . Time::parse($gap)->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('n月j日 G時i分') . ' 以降）。この間の地震は連絡されていない可能性があります。';
        }

        if ($failed > 0) {
            $problems['feed_failed'] = '取れなかったか読めなかった電文が ' . $failed . ' 通あります。';
        }

        if ($mail !== '') {
            $problems['mail'] = 'メールを送れていません：' . $mail;
        }

        if ($imap !== null) {
            $problems['imap'] = '返信を読めていません：' . $imap;
        }

        $stale = $this->reading->staleWarning($now);

        if ($stale !== null) {
            $problems['imap_stale'] = $stale;
        }

        $front = $this->notifications->addressesAtTheFront($now);

        if ($front !== null && $this->limit->nextSlotFor($now, $front) !== null) {
            $problems['waiting'] = '1 時間の上限のため、連絡が送信待ちになっています。';
        }

        return $problems;
    }

    private static function count(mixed $list): int
    {
        return is_array($list) ? count($list) : 0;
    }
}
