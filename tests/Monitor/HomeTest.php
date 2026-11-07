<?php

declare(strict_types=1);

namespace ReBuji\Tests\Monitor;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReBuji\Config\ConfigFile;
use ReBuji\SystemState;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use ReBuji\Time;

/**
 * @author shimomo
 */
final class HomeTest extends TestCase
{
    #[Test]
    public function warnsWhenTheFeedHasNotBeenCheckedForFifteenMinutes(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            TestApp::logIn($app, $container);
            $never = (string) $app->handle(TestApp::request('GET', '/'))->getBody();
            $this->assertStringContainsString('まだ一度も気象庁の情報を確かめていません', $never);

            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(SystemState::class, $state);
            $state->set('feed.last_checked_at', Time::format($clock->now()->modify('-20 minutes')));
            $state->set('cron.last_error', 'feed: down');
            $body = (string) $app->handle(TestApp::request('GET', '/'))->getBody();

            $this->assertStringContainsString('15 分以上、気象庁の情報を確かめていません（最後は 2026-11-05 11:52）', $body);
            $this->assertStringContainsString('2026-11-05 11:52', $body);
            $this->assertStringContainsString('feed: down', $body);
            $this->assertStringContainsString('https://example.com/status/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $body);

            $state->set('feed.last_checked_at', Time::format($clock->now()->modify('-1 minute')));
            $fresh = (string) $app->handle(TestApp::request('GET', '/'))->getBody();
            $this->assertStringNotContainsString('15 分以上', $fresh);
            $this->assertStringContainsString('最後に気象庁の情報を確かめたのは 2026-11-05 12:11 です。', $fresh);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function regeneratesTheStatusToken(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            TestApp::logIn($app, $container);
            $old = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
            $response = $app->handle(TestApp::request('POST', '/settings/status-token', TestApp::csrf($container)));
            $new = (string) (new ConfigFile($root . '/storage/config.php'))->load()->get('status.token');

            $this->assertSame(302, $response->getStatusCode());
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $new);
            $this->assertNotSame($old, $new);
            $this->assertSame(404, $app->handle(TestApp::request('GET', '/status/' . $old))->getStatusCode());
            $this->assertNotSame(404, $app->handle(TestApp::request('GET', '/status/' . $new))->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function regeneratingTheTokenRequiresLogin(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            $response = $app->handle(TestApp::request('POST', '/settings/status-token', TestApp::csrf($container)));

            $this->assertSame('/login', $response->getHeaderLine('Location'));
            $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', (new ConfigFile($root . '/storage/config.php'))->load()->get('status.token'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
