<?php

declare(strict_types=1);

namespace ReBuji\Tests\Quakes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Quakes\Notification;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\QuakeRecords;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;

/**
 * @author shimomo
 */
final class ProxyAnswerTest extends TestCase
{
    #[Test]
    public function recordsAPersonAsSafeOnTheirBehalf(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = $records->quake('20261105121200', '宮城県沖・最大震度6弱', '2026-11-05T03:12:00Z');
            $contact = $records->contact($quake, Notification::SENT);
            $form = (string) $app->handle(TestApp::request('GET', '/contacts/' . $contact . '/proxy'))->getBody();
            $this->assertStringContainsString('従業員 1', $form);
            $this->assertStringContainsString('宮城県沖・最大震度6弱', $form);

            $response = $app->handle(TestApp::request('POST', '/contacts/' . $contact . '/proxy', ['answer' => 'safe', 'note' => ''] + TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/earthquakes/' . $quake, $response->getHeaderLine('Location'));
            $answered = $records->notifications->find($contact);
            $this->assertNotNull($answered);
            $this->assertSame(Notification::SAFE, $answered->answer);
            $this->assertSame('proxy', $answered->answerSource);
            $this->assertSame('安否 太郎', $answered->answeredBy);
            $this->assertNotNull($answered->repliedAt);
            $this->assertStringContainsString('代理の記録を残しました。', (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function turnsAReplyThatNeedsCheckingIntoSafe(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = $records->quake('20261105121200', '宮城県沖・最大震度6弱', '2026-11-05T03:12:00Z');
            $contact = $records->contact($quake, Notification::SENT);
            $records->notifications->recordAnswer($contact, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:20:00Z'));

            $app->handle(TestApp::request('POST', '/contacts/' . $contact . '/proxy', ['answer' => 'safe', 'note' => '電話で無事を確認'] + TestApp::csrf($container)));

            $answered = $records->notifications->find($contact);
            $this->assertNotNull($answered);
            $this->assertSame(Notification::SAFE, $answered->answer);
            $this->assertSame('安否 太郎', $answered->answeredBy);
            $page = (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody();
            $this->assertStringContainsString('<h2>要確認（0 人）</h2>', $page);
            $this->assertStringContainsString('<h2>無事（1 人）</h2>', $page);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function showsTheCurrentRecord(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = $records->quake('20261105121200', '宮城県沖・最大震度6弱', '2026-11-05T03:12:00Z');
            $silent = $records->contact($quake, Notification::SENT);
            $written = $records->contact($quake, Notification::SENT);
            $byProxy = $records->contact($quake, Notification::SENT);
            $at = new DateTimeImmutable('2026-11-05T03:20:00Z');
            $records->notifications->recordAnswer($written, Notification::NEEDS_CHECK, 'mail', null, null, $at);
            $records->notifications->recordAnswer($byProxy, Notification::SAFE, 'proxy', '安否 太郎', null, $at);

            $this->assertStringContainsString('今の記録：まだ答えがありません', self::proxyForm($app, $silent));
            $this->assertStringContainsString('今の記録：要確認（本人のメール）', self::proxyForm($app, $written));
            $this->assertStringContainsString('今の記録：無事（安否 太郎 さんが代理で記録）', self::proxyForm($app, $byProxy));

            $records->notifications->markChecked($written, '安否 太郎', $at);

            $this->assertStringContainsString('今の記録：要確認（本人のメール、確認済み）', self::proxyForm($app, $written));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function keepsTheNoteOfAPersonToCheck(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = $records->quake('20261105121200', '宮城県沖・最大震度6弱', '2026-11-05T03:12:00Z');
            $contact = $records->contact($quake, Notification::SENT);

            $app->handle(TestApp::request('POST', '/contacts/' . $contact . '/proxy', ['answer' => 'needs_check', 'note' => '電話で確認。足を骨折'] + TestApp::csrf($container)));
            $page = (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody();

            $this->assertStringContainsString('電話で確認。足を骨折', $page);
            $this->assertStringContainsString('安否 太郎 さんが代理で記録', $page);
            $this->assertStringContainsString('/contacts/' . $contact . '/proxy', $page);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function recordsOnTheContactAFoldedPersonWasFoldedInto(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $first = $records->contact($records->quake('20261105121200', '宮城県沖・最大震度6弱', '2026-11-05T03:12:00Z'), Notification::SENT);
            $later = $records->quake('20261105121400', '岩手県沖・最大震度5強', '2026-11-05T03:14:00Z');
            $folded = $records->contact($later, Notification::MERGED);
            $records->db->execute('UPDATE notifications SET merged_into_id = ? WHERE id = ?', [$first, $folded]);

            $app->handle(TestApp::request('POST', '/contacts/' . $folded . '/proxy', ['answer' => 'safe', 'note' => ''] + TestApp::csrf($container)));

            $this->assertSame(Notification::SAFE, $records->notifications->find($first)?->answer);
            $this->assertNull($records->notifications->find($folded)?->answer);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesAProxyAnswerWithoutAChoice(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $contact = $records->contact($records->quake('20261105121200', '宮城県沖・最大震度6弱', '2026-11-05T03:12:00Z'), Notification::SENT);

            $missing = $app->handle(TestApp::request('POST', '/contacts/' . $contact . '/proxy', ['note' => '電話した'] + TestApp::csrf($container)));
            $long = $app->handle(TestApp::request('POST', '/contacts/' . $contact . '/proxy', ['answer' => 'safe', 'note' => str_repeat('あ', 2001)] + TestApp::csrf($container)));

            $this->assertSame(200, $missing->getStatusCode());
            $this->assertStringContainsString('無事か要確認かを選んでください。', (string) $missing->getBody());
            $this->assertStringContainsString('メモは 2000 文字までです。', (string) $long->getBody());
            $this->assertNull($records->notifications->find($contact)?->answer);
            $this->assertSame(404, $app->handle(TestApp::request('GET', '/contacts/999/proxy'))->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function proxyForm(App $app, int $contact): string
    {
        return (string) $app->handle(TestApp::request('GET', '/contacts/' . $contact . '/proxy'))->getBody();
    }

    /**
     * @return array{App<ContainerInterface>, ContainerInterface, string}
     */
    private static function loggedIn(): array
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);
        TestApp::logIn($app, $container);

        return [$app, $container, $root];
    }
}
