<?php

declare(strict_types=1);

namespace ReBuji\Tests\Mail;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Mail\SendingAccount;
use ReBuji\SystemState;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TestDatabase;

/**
 * @author shimomo
 */
final class SendingAccountTest extends TestCase
{
    private const string AUTH_FAILED = 'Failed to authenticate on SMTP server with username "anpi" using the following authenticators: "LOGIN", "PLAIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed"."';

    #[Test]
    public function isDueBeforeAnyCheck(): void
    {
        [$account, , $clock] = self::account();

        $this->assertTrue($account->isDue($clock->now()));
        $this->assertFalse($account->isFailing());
        $this->assertFalse($account->isLasting());
    }

    #[Test]
    public function waitsAnHourAfterItWorked(): void
    {
        [$account, , $clock] = self::account();
        $account->worked();

        $this->assertFalse($account->isDue($clock->now()->modify('+59 minutes')));
        $this->assertTrue($account->isDue($clock->now()->modify('+60 minutes')));
    }

    #[Test]
    public function checksAgainHalfAnHourAfterAFailure(): void
    {
        [$account, , $clock] = self::account();
        $account->failed('x');

        $this->assertFalse($account->isDue($clock->now()->modify('+29 minutes')));
        $this->assertTrue($account->isDue($clock->now()->modify('+30 minutes')));
    }

    #[Test]
    public function showsTheFailureOnTheHomePage(): void
    {
        [$account, $state] = self::account();
        $account->failed(self::AUTH_FAILED);

        $this->assertSame(self::AUTH_FAILED, $state->get('mail.last_error'));
        $this->assertTrue($account->isFailing());
    }

    #[Test]
    public function keepsTheFirstFailureTime(): void
    {
        [$account, $state, $clock] = self::account();
        $account->failed('x');
        $clock->advance('+10 minutes');
        $account->failed('x');

        $this->assertSame('2026-11-05T03:12:00Z', $state->get('mail.account.failing_since'));
    }

    #[Test]
    public function isNotLastingAfterOneFailure(): void
    {
        [$account, , $clock] = self::account();
        $account->failed('x');
        $clock->advance('+40 minutes');

        $this->assertFalse($account->isLasting());
    }

    #[Test]
    public function isLastingWhenAFailureHalfAnHourLaterFollows(): void
    {
        [$account, , $clock] = self::account();
        $account->failed('x');
        $clock->advance('+29 minutes');
        $account->failed('x');

        $this->assertFalse($account->isLasting());

        $clock->advance('+1 minute');
        $account->failed('x');

        $this->assertTrue($account->isLasting());
    }

    #[Test]
    public function clearsTheFailureWhenTheAccountWorks(): void
    {
        [$account, $state] = self::account();
        $account->failed(self::AUTH_FAILED);
        $account->worked();

        $this->assertFalse($account->isFailing());
        $this->assertFalse($account->isLasting());
        $this->assertSame('', $state->get('mail.last_error'));
    }

    #[Test]
    public function keepsAnotherErrorWhenTheAccountWorks(): void
    {
        [$account, $state] = self::account();
        $account->failed(self::AUTH_FAILED);
        // What the contacts wrote after a failure past the log-in.
        $state->set('mail.last_error', 'Expected response code "250" but got code "552", with message "552 5.3.4 Daily limit exceeded".');
        $account->worked();

        $this->assertSame('Expected response code "250" but got code "552", with message "552 5.3.4 Daily limit exceeded".', $state->get('mail.last_error'));
    }

    #[Test]
    public function startsCountingAgainAfterItWorked(): void
    {
        [$account, , $clock] = self::account();
        $account->failed('x');
        $clock->advance('+10 minutes');
        $account->worked();
        $clock->advance('+25 minutes');
        $account->failed('x');

        $this->assertTrue($account->isFailing());
        $this->assertFalse($account->isLasting());
    }

    #[Test]
    public function checkSoonMakesTheNextRunCheck(): void
    {
        [$account, , $clock] = self::account();
        $account->worked();
        $account->checkSoon();

        $this->assertTrue($account->isDue($clock->now()->modify('+1 minute')));

        $account->failed('x');
        $account->checkSoon();

        $this->assertTrue($account->isFailing());
        $this->assertTrue($account->isDue($clock->now()));
    }

    #[Test]
    public function checksOnTheNextRunAfterAStartWhenTheAccountWorks(): void
    {
        [$account, , $clock] = self::account();
        $account->worked();
        $account->checkAfterStart();

        $this->assertTrue($account->isDue($clock->now()));
    }

    #[Test]
    public function waitsAfterAFreshFailureBeforeCheckingAfterAStart(): void
    {
        [$account, , $clock] = self::account();
        $account->failed('x');
        // A container that keeps restarting must not have cron try a wrong password every run.
        $account->checkAfterStart();

        $this->assertFalse($account->isDue($clock->now()->modify('+9 minutes')));
        $this->assertTrue($account->isDue($clock->now()->modify('+10 minutes')));
    }

    #[Test]
    public function checksOnTheNextRunAfterAStartLongAfterAFailure(): void
    {
        [$account, , $clock] = self::account();
        $account->failed('x');
        $clock->advance('+20 minutes');
        $account->checkAfterStart();

        $this->assertTrue($account->isDue($clock->now()));
        $this->assertTrue($account->isFailing());
    }

    /**
     * @return array{SendingAccount, SystemState, FrozenClock}
     */
    public static function account(): array
    {
        $db = TestDatabase::open('sqlite');
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();
        $state = new SystemState($db, $clock);

        return [new SendingAccount($state, $clock), $state, $clock];
    }
}
