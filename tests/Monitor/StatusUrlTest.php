<?php

declare(strict_types=1);

namespace ReBuji\Tests\Monitor;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\SystemState;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use ReBuji\Time;

/**
 * @author shimomo
 */
final class StatusUrlTest extends TestCase
{
    private const string TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[Test]
    public function reportsOkWithTheRightToken(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            $this->lastRun($container, $clock, '-2 minutes');
            $response = $app->handle(TestApp::request('GET', '/status/' . self::TOKEN));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            $this->assertSame(['status' => 'ok', 'last_run' => '2026-11-05T03:10:00Z'], json_decode((string) $response->getBody(), true));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function reportsStaleAsServiceUnavailable(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            $never = $app->handle(TestApp::request('GET', '/status/' . self::TOKEN));
            $this->assertSame(503, $never->getStatusCode());
            $this->assertSame(['status' => 'stale', 'last_run' => null], json_decode((string) $never->getBody(), true));

            $this->lastRun($container, $clock, '-20 minutes');
            $stale = $app->handle(TestApp::request('GET', '/status/' . self::TOKEN));
            $this->assertSame(503, $stale->getStatusCode());
            $this->assertSame(['status' => 'stale', 'last_run' => '2026-11-05T02:52:00Z'], json_decode((string) $stale->getBody(), true));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function wrongTokenLooksLikeAMissingPage(): void
    {
        [$app, , $root] = TestApp::installed();

        try {
            $wrong = $app->handle(TestApp::request('GET', '/status/' . str_repeat('b', 32)));
            $missing = $app->handle(TestApp::request('GET', '/no-such-page'));

            $this->assertSame(404, $wrong->getStatusCode());
            $this->assertSame((string) $missing->getBody(), (string) $wrong->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function bodyContainsNoAdminData(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            $this->lastRun($container, $clock, '-1 minute');
            $body = (string) $app->handle(TestApp::request('GET', '/status/' . self::TOKEN))->getBody();

            $this->assertStringNotContainsString(TestApp::ADMIN_EMAIL, $body);
            $this->assertStringNotContainsString('安否 太郎', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    private function lastRun(ContainerInterface $container, FrozenClock $clock, string $ago): void
    {
        $state = $container->get(SystemState::class);
        $this->assertInstanceOf(SystemState::class, $state);
        $state->set('cron.last_finished_at', Time::format($clock->now()->modify($ago)));
    }
}
