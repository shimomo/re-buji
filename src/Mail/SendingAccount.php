<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use ReBuji\SystemState;
use ReBuji\Time;

/**
 * Whether the sending account works (design of plan 23). Every use of it ends as worked (the log-in was through, even
 * when a recipient, the sender or the body was refused after it) or failed (no connection, no TLS, a failed log-in).
 * Between quakes nothing is sent, so cron logs in when isDue() says so.
 *
 * A failure shows on the home page at once, in the words of mail.last_error. The status URL turns red only when it
 * lasts: an attempt LASTING_MINUTES or more after the first failure failed too, with nothing working in between. A mere
 * wait does not count, so a single stumble never rings the uptime monitor.
 *
 * @author shimomo
 */
final class SendingAccount
{
    /**
     * How long after the account last worked cron logs in again.
     */
    public const int CHECK_EVERY_MINUTES = 60;

    /**
     * How long after a failure cron tries again. Failed log-ins repeated too often can get the server locked out (on a
     * shared rental server, its IP with it), so twice an hour at most.
     */
    public const int RECHECK_MINUTES = 30;

    /**
     * How far apart the first failure and a later one must be before the failure counts as lasting.
     */
    public const int LASTING_MINUTES = 30;

    /**
     * How long after a failure a start of the app waits before cron logs in again. A fix made in .env comes in with a
     * restart and is soon seen; a container that keeps restarting cannot make cron try a wrong password every run.
     */
    public const int START_RECHECK_MINUTES = 10;

    public function __construct(private readonly SystemState $state, private readonly ClockInterface $clock)
    {
        //
    }

    public function worked(): void
    {
        $now = $this->clock->now();
        $this->state->set('mail.account.next_check_at', Time::format($now->modify('+' . self::CHECK_EVERY_MINUTES . ' minutes')));

        if (!$this->isFailing()) {
            return;
        }

        // Only the failure this class showed goes away: a failure past the log-in, written by the contacts, stays
        // until a mail goes out.
        if ((string) $this->state->get('mail.last_error') === (string) $this->state->get('mail.account.error')) {
            $this->state->set('mail.last_error', '');
        }

        $this->state->set('mail.account.failing_since', '');
        $this->state->set('mail.account.failed_at', '');
        $this->state->set('mail.account.error', '');
    }

    public function failed(string $reason): void
    {
        $now = Time::format($this->clock->now());

        if (!$this->isFailing()) {
            $this->state->set('mail.account.failing_since', $now);
        }

        $this->state->set('mail.account.failed_at', $now);
        $this->state->set('mail.account.next_check_at', Time::format($this->clock->now()->modify('+' . self::RECHECK_MINUTES . ' minutes')));
        $this->state->set('mail.account.error', $reason);
        $this->state->set('mail.last_error', $reason);
    }

    public function isFailing(): bool
    {
        return (string) $this->state->get('mail.account.failing_since') !== '';
    }

    public function isLasting(): bool
    {
        $since = (string) $this->state->get('mail.account.failing_since');
        $last = (string) $this->state->get('mail.account.failed_at');

        return $since !== '' && $last !== '' && Time::parse($last)->getTimestamp() - Time::parse($since)->getTimestamp() >= self::LASTING_MINUTES * 60;
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        $next = (string) $this->state->get('mail.account.next_check_at');

        return $next === '' || $now->getTimestamp() >= Time::parse($next)->getTimestamp();
    }

    /**
     * Makes the next cron run log in (the settings changed). A failure stays: if the new settings fail too, the account
     * has been unusable all along.
     */
    public function checkSoon(): void
    {
        $this->state->set('mail.account.next_check_at', '');
    }

    /**
     * Makes the next cron run after a start of the app (Docker) log in, but no sooner than START_RECHECK_MINUTES after
     * the last failure. A failure stays, as with checkSoon().
     */
    public function checkAfterStart(): void
    {
        $now = $this->clock->now();
        $failed = (string) $this->state->get('mail.account.failed_at');
        $at = $failed === '' ? $now : max($now, Time::parse($failed)->modify('+' . self::START_RECHECK_MINUTES . ' minutes'));
        $this->state->set('mail.account.next_check_at', Time::format($at));
    }
}
