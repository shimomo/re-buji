<?php

declare(strict_types=1);

namespace ReBuji\Tests\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReBuji\Auth\LoginThrottle;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;

/**
 * @author shimomo
 */
final class LoginThrottleTest extends TestCase
{
    #[Test]
    public function blocksAfterFiveFailuresWithinFifteenMinutes(): void
    {
        [$throttle, $clock] = $this->throttle();

        for ($i = 0; $i < 4; $i++) {
            $throttle->record('admin@example.com', '192.0.2.1', false, $clock->now());
        }

        $this->assertFalse($throttle->isBlocked('admin@example.com', '192.0.2.1', $clock->now()));
        $throttle->record('admin@example.com', '192.0.2.1', false, $clock->now());
        $this->assertTrue($throttle->isBlocked('admin@example.com', '192.0.2.1', $clock->now()));

        $clock->advance('+16 minutes');
        $this->assertFalse($throttle->isBlocked('admin@example.com', '192.0.2.1', $clock->now()));
    }

    #[Test]
    public function anotherAddressCannotLockTheAdminOut(): void
    {
        [$throttle, $clock] = $this->throttle();

        for ($i = 0; $i < 5; $i++) {
            $throttle->record('admin@example.com', '203.0.113.7', false, $clock->now());
        }

        $this->assertTrue($throttle->isBlocked('admin@example.com', '203.0.113.7', $clock->now()));
        $this->assertFalse($throttle->isBlocked('admin@example.com', '192.0.2.1', $clock->now()));
    }

    #[Test]
    public function blocksAnAddressTryingManyEmails(): void
    {
        [$throttle, $clock] = $this->throttle();

        for ($i = 0; $i < 19; $i++) {
            $throttle->record('user' . $i . '@example.com', '192.0.2.1', false, $clock->now());
        }

        $this->assertFalse($throttle->isBlocked('someone@example.com', '192.0.2.1', $clock->now()));
        $throttle->record('user19@example.com', '192.0.2.1', false, $clock->now());
        $this->assertTrue($throttle->isBlocked('someone@example.com', '192.0.2.1', $clock->now()));
        $this->assertFalse($throttle->isBlocked('someone@example.com', '192.0.2.2', $clock->now()));
    }

    #[Test]
    public function successesDoNotCount(): void
    {
        [$throttle, $clock] = $this->throttle();

        for ($i = 0; $i < 5; $i++) {
            $throttle->record('admin@example.com', '192.0.2.1', true, $clock->now());
        }

        $this->assertFalse($throttle->isBlocked('admin@example.com', '192.0.2.1', $clock->now()));
    }

    #[Test]
    public function aSuccessStartsTheCountAgain(): void
    {
        [$throttle, $clock] = $this->throttle();

        for ($i = 0; $i < 4; $i++) {
            $throttle->record('admin@example.com', '192.0.2.1', false, $clock->now());
        }

        $throttle->record('admin@example.com', '192.0.2.1', true, $clock->now());

        for ($i = 0; $i < 4; $i++) {
            $throttle->record('admin@example.com', '192.0.2.1', false, $clock->now());
        }

        $this->assertFalse($throttle->isBlocked('admin@example.com', '192.0.2.1', $clock->now()));
    }

    #[Test]
    public function blockedLoginSaysToWait(): void
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            for ($i = 0; $i < 5; $i++) {
                TestApp::logIn($app, $container, 'wrong-password-123');
            }

            $response = TestApp::logIn($app, $container);
            $body = (string) $response->getBody();

            // A stressed admin told "wrong password" keeps retrying; the block does not depend on the account, so
            // saying so reveals nothing.
            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('ログインに続けて失敗したため、15 分ほど受け付けを止めています', $body);
            $this->assertStringNotContainsString('メールアドレスかパスワードが違います', $body);
            $this->assertNull(TestApp::session($container)->get('admin_id'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function attemptsWhileBlockedDoNotExtendTheBlock(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            for ($i = 0; $i < 5; $i++) {
                TestApp::logIn($app, $container, 'wrong-password-123');
            }

            // Enough retries during the block to keep it going if they counted.
            $clock->advance('+10 minutes');

            for ($i = 0; $i < 5; $i++) {
                TestApp::logIn($app, $container, 'wrong-password-123');
            }

            $clock->advance('+6 minutes');
            $response = TestApp::logIn($app, $container);

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/', $response->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * @return array{LoginThrottle, FrozenClock}
     */
    private function throttle(): array
    {
        $db = Database::sqliteInMemory();
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();

        return [new LoginThrottle($db), $clock];
    }
}
