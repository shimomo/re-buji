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

        $this->assertFalse($throttle->isBlocked('admin@example.com', '198.51.100.9', $clock->now()));
        $throttle->record('admin@example.com', '192.0.2.1', false, $clock->now());
        $this->assertTrue($throttle->isBlocked('admin@example.com', '198.51.100.9', $clock->now()));

        $clock->advance('+16 minutes');
        $this->assertFalse($throttle->isBlocked('admin@example.com', '198.51.100.9', $clock->now()));
    }

    #[Test]
    public function blocksByAddressAcrossEmails(): void
    {
        [$throttle, $clock] = $this->throttle();

        for ($i = 0; $i < 5; $i++) {
            $throttle->record('user' . $i . '@example.com', '192.0.2.1', false, $clock->now());
        }

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
    public function blockedLoginLooksLikeAWrongPassword(): void
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            for ($i = 0; $i < 5; $i++) {
                TestApp::logIn($app, $container, 'wrong-password-123');
            }

            $response = TestApp::logIn($app, $container);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('メールアドレスかパスワードが違います', (string) $response->getBody());
            $this->assertNull(TestApp::session($container)->get('admin_id'));
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
