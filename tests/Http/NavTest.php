<?php

declare(strict_types=1);

namespace ReBuji\Tests\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReBuji\Quakes\Earthquake;
use ReBuji\Replies\ReplyRepository;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\QuakeRecords;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;

/**
 * @author shimomo
 */
final class NavTest extends TestCase
{
    #[Test]
    public function showsTheTabsAndMarksTheCurrentOne(): void
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            TestApp::logIn($app, $container);
            $home = (string) $app->handle(TestApp::request('GET', '/'))->getBody();

            foreach (['/', '/earthquakes', '/mail/unmatched', '/employees', '/more'] as $path) {
                $this->assertStringContainsString('href="' . $path . '"', $home);
            }

            $this->assertStringContainsString('href="/" aria-current="page"', $home);
            $this->assertSame(1, substr_count($home, 'aria-current="page"'));
            $this->assertStringContainsString('href="/employees" aria-current="page"', (string) $app->handle(TestApp::request('GET', '/employees'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function countsWhatWaitsForADecision(): void
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            TestApp::logIn($app, $container);
            $empty = (string) $app->handle(TestApp::request('GET', '/employees'))->getBody();
            $this->assertStringNotContainsString('class="badge"', $empty);

            (new QuakeRecords($container))->quake('e1', '遅れた地震', '2026-11-05T03:00:00Z', Earthquake::HELD);
            $replies = $container->get(ReplyRepository::class);
            $this->assertInstanceOf(ReplyRepository::class, $replies);

            foreach (['r1', 'r2'] as $id) {
                $replies->record(null, 'reply', null, 'private@example.net', '無事です', '無事です', $id . '@example.net', new DateTimeImmutable('2026-11-05T03:20:00Z'));
            }

            $page = (string) $app->handle(TestApp::request('GET', '/employees'))->getBody();
            $this->assertMatchesRegularExpression('#href="/earthquakes".*?<span class="badge">1</span></a>#', $page);
            $this->assertMatchesRegularExpression('#href="/mail/unmatched".*?<span class="badge">2</span></a>#', $page);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function listsTheRestOnTheMorePage(): void
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            TestApp::logIn($app, $container);
            $response = $app->handle(TestApp::request('GET', '/more'));
            $body = (string) $response->getBody();

            $this->assertSame(200, $response->getStatusCode());

            foreach (['/drills/new', '/admins', '/settings', '/admin/database'] as $path) {
                $this->assertStringContainsString('class="row-link" href="' . $path . '"', $body);
            }

            $this->assertStringContainsString('action="/logout"', $body);
            $this->assertStringContainsString('href="/more" aria-current="page"', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
