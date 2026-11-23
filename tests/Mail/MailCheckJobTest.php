<?php

declare(strict_types=1);

namespace ReBuji\Tests\Mail;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReBuji\Mail\MailCheckJob;
use ReBuji\Mail\SendingAccount;
use ReBuji\Tests\Support\QuakeWorld;

/**
 * @author shimomo
 */
final class MailCheckJobTest extends TestCase
{
    #[Test]
    public function logsInWhenDue(): void
    {
        $world = new QuakeWorld('2026-11-05T03:12:00Z');
        self::job($world)->run($world->clock->now());

        $this->assertSame(1, self::logins($world));
        $this->assertSame([], $world->mailer->sent);
    }

    #[Test]
    public function waitsAnHourAfterTheAccountWorked(): void
    {
        $world = new QuakeWorld('2026-11-05T03:12:00Z');
        self::account($world)->worked();
        $world->clock->advance('+59 minutes');
        self::job($world)->run($world->clock->now());

        $this->assertSame(0, self::logins($world));

        $world->clock->advance('+1 minute');
        self::job($world)->run($world->clock->now());

        $this->assertSame(1, self::logins($world));
    }

    #[Test]
    public function triesAgainHalfAnHourAfterAFailure(): void
    {
        $world = new QuakeWorld('2026-11-05T03:12:00Z');
        self::account($world)->failed('x');
        $world->clock->advance('+29 minutes');
        self::job($world)->run($world->clock->now());

        $this->assertSame(0, self::logins($world));

        $world->clock->advance('+1 minute');
        self::job($world)->run($world->clock->now());

        $this->assertSame(1, self::logins($world));
    }

    #[Test]
    public function keepsTheRunGoingWhenItCannotLogIn(): void
    {
        $world = new QuakeWorld('2026-11-05T03:12:00Z');
        $world->mailer->unavailable = true;
        self::job($world)->run($world->clock->now());

        $this->assertSame(1, self::logins($world));
    }

    #[Test]
    public function skipsWithoutSendingSettings(): void
    {
        $world = new QuakeWorld('2026-11-05T03:12:00Z');
        $world->settings->save(['smtp.host' => '']);
        self::job($world)->run($world->clock->now());

        $this->assertSame(0, self::logins($world));
    }

    /**
     * Read through a call: Psalm would take the count for fixed after the first assertion.
     */
    private static function logins(QuakeWorld $world): int
    {
        return $world->mailer->logins;
    }

    private static function account(QuakeWorld $world): SendingAccount
    {
        return new SendingAccount($world->state, $world->clock);
    }

    private static function job(QuakeWorld $world): MailCheckJob
    {
        return new MailCheckJob($world->settings, self::account($world), $world->mailer, new NullLogger());
    }
}
