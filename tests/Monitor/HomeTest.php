<?php

declare(strict_types=1);

namespace ReBuji\Tests\Monitor;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReBuji\Config\ConfigFile;
use ReBuji\Mail\MessageRepository;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\Notification;
use ReBuji\Replies\ReplyRepository;
use ReBuji\Settings\Settings;
use ReBuji\SystemState;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\QuakeRecords;
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

    #[Test]
    public function warnsAboutLateQuakes(): void
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            TestApp::logIn($app, $container);
            $records = new QuakeRecords($container);
            $records->contact($records->quake('20090811050711', '駿河湾・最大震度6弱', '2026-11-04T20:00:00Z', Earthquake::HELD), Notification::HELD);

            $body = (string) $app->handle(TestApp::request('GET', '/'))->getBody();

            $this->assertStringContainsString('遅れて届いた地震が 1 件あります。送るかどうかを決めてください。', $body);
            $this->assertStringContainsString('href="/earthquakes"', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function forgetsALateQuakeThatWasCancelled(): void
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            TestApp::logIn($app, $container);
            $records = new QuakeRecords($container);
            $late = $records->quake('20090811050711', '駿河湾・最大震度6弱', '2026-11-04T20:00:00Z', Earthquake::HELD);
            $records->contact($late, Notification::HELD, '2026-11-04T20:00:00Z');
            $records->earthquakes->markCancelled($late, new DateTimeImmutable('2026-11-05T00:00:00Z'));

            $response = $app->handle(TestApp::request('GET', '/'));

            // Without this, an error page would pass too: it says nothing of late quakes either.
            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringNotContainsString('遅れて届いた地震が', (string) $response->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function warnsWhenContactsWaitForTheLimit(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            TestApp::logIn($app, $container);
            $settings = $container->get(Settings::class);
            $messages = $container->get(MessageRepository::class);
            $this->assertInstanceOf(Settings::class, $settings);
            $this->assertInstanceOf(MessageRepository::class, $messages);
            $records = new QuakeRecords($container);
            $quake = $records->quake('20090811050711', '駿河湾・最大震度6弱', '2026-11-05T03:00:00Z');
            $records->contact($quake, Notification::PENDING, '2026-11-05T03:00:00Z');
            $records->contact($quake, Notification::PENDING, '2026-11-05T03:00:00Z');

            $this->assertStringContainsString('送信待ちの連絡が 2 件あります。次の cron で送ります。', (string) $app->handle(TestApp::request('GET', '/'))->getBody());

            $settings->save(['hourly_limit' => '10']);

            for ($i = 0; $i < 10; $i++) {
                $messages->record(null, 'test', 'admin@example.com', 'm' . $i . '@example.co.jp', true, '', $clock->now()->modify('-5 minutes'));
            }

            $this->assertStringContainsString(
                '1 時間の上限のため、2 人への連絡が送信待ちです。次に送れるのは 13:07 ごろです。',
                (string) $app->handle(TestApp::request('GET', '/'))->getBody(),
            );
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function waitsForRoomForEveryAddressOfThePerson(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            TestApp::logIn($app, $container);
            $settings = $container->get(Settings::class);
            $messages = $container->get(MessageRepository::class);
            $this->assertInstanceOf(Settings::class, $settings);
            $this->assertInstanceOf(MessageRepository::class, $messages);
            $records = new QuakeRecords($container);
            $quake = $records->quake('20090811050711', '駿河湾・最大震度6弱', '2026-11-05T03:00:00Z');
            $records->contact($quake, Notification::PENDING, '2026-11-05T03:00:00Z', addresses: 4);
            // A limit of 10 keeps 1 back for the one admin; after 7 mails, 2 are left for a person who needs 4.
            $settings->save(['hourly_limit' => '10']);

            for ($i = 0; $i < 7; $i++) {
                $messages->record(null, 'test', 'admin@example.com', 'm' . $i . '@example.co.jp', true, '', $clock->now()->modify('-5 minutes'));
            }

            $this->assertStringContainsString(
                '1 時間の上限のため、1 人への連絡が送信待ちです。次に送れるのは 13:07 ごろです。',
                (string) $app->handle(TestApp::request('GET', '/'))->getBody(),
            );
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function waitsBehindThePersonAtTheFront(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            TestApp::logIn($app, $container);
            $settings = $container->get(Settings::class);
            $messages = $container->get(MessageRepository::class);
            $this->assertInstanceOf(Settings::class, $settings);
            $this->assertInstanceOf(MessageRepository::class, $messages);
            $records = new QuakeRecords($container);
            $quake = $records->quake('20090811050711', '駿河湾・最大震度6弱', '2026-11-05T03:00:00Z');
            // Same priority: the first person is first in line. 3 mails are left; the sender stops at the one who needs 4.
            $records->contact($quake, Notification::PENDING, '2026-11-05T03:00:00Z', addresses: 4);
            $records->contact($quake, Notification::PENDING, '2026-11-05T03:00:00Z');
            $settings->save(['hourly_limit' => '10']);

            for ($i = 0; $i < 6; $i++) {
                $messages->record(null, 'test', 'admin@example.com', 'm' . $i . '@example.co.jp', true, '', $clock->now()->modify('-5 minutes'));
            }

            $this->assertStringContainsString(
                '1 時間の上限のため、2 人への連絡が送信待ちです。次に送れるのは 13:07 ごろです。',
                (string) $app->handle(TestApp::request('GET', '/'))->getBody(),
            );
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function warnsWhenMailCannotBeSent(): void
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            TestApp::logIn($app, $container);
            $this->assertStringContainsString('メールの送信の設定がありません。「設定」で SMTP を登録してください。', (string) $app->handle(TestApp::request('GET', '/'))->getBody());

            $settings = $container->get(Settings::class);
            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(Settings::class, $settings);
            $this->assertInstanceOf(SystemState::class, $state);
            $settings->save(['smtp.host' => 'smtp.example.co.jp', 'mail.from_address' => 'anpi@example.co.jp']);
            $state->set('mail.last_error', 'Failed to authenticate on SMTP server');
            $body = (string) $app->handle(TestApp::request('GET', '/'))->getBody();

            $this->assertStringNotContainsString('メールの送信の設定がありません', $body);
            $this->assertStringContainsString('メールを送れていません：Failed to authenticate on SMTP server', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function warnsAboutTheFeedUntilAcknowledged(): void
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            TestApp::logIn($app, $container);
            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(SystemState::class, $state);
            $state->set('feed.gap_since', '2026-11-05T03:00:00Z');
            $state->set('feed.failed_telegrams', json_encode([['title' => '震度速報', 'url' => 'https://www.data.jma.go.jp/developer/xml/data/x.xml', 'reason' => 'HTTP 404', 'at' => '2026-11-05T03:05:00Z']], JSON_THROW_ON_ERROR));
            $body = (string) $app->handle(TestApp::request('GET', '/'))->getBody();

            $this->assertStringContainsString('気象庁の情報に抜けがあります（11月5日 12時00分 以降）。この間の地震は連絡されていない可能性があります。', $body);
            $this->assertStringContainsString('取れなかった電文があります：震度速報（11月5日 12時05分）', $body);

            $response = $app->handle(TestApp::request('POST', '/feed/acknowledge', TestApp::csrf($container)));
            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/', $response->getHeaderLine('Location'));

            $after = (string) $app->handle(TestApp::request('GET', '/'))->getBody();
            $this->assertStringNotContainsString('気象庁の情報に抜けがあります', $after);
            $this->assertStringNotContainsString('取れなかった電文があります', $after);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function saysWhichTelegramsCouldNotBeRead(): void
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            TestApp::logIn($app, $container);
            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(SystemState::class, $state);
            $state->set('feed.failed_telegrams', json_encode([
                ['kind' => 'read', 'title' => '震源・震度に関する情報', 'url' => 'https://example.com/broken.xml', 'reason' => 'not a JMA telegram', 'at' => '2026-11-05T03:05:00Z'],
                ['title' => '震度速報', 'url' => 'https://www.data.jma.go.jp/developer/xml/data/x.xml', 'reason' => 'HTTP 404', 'at' => '2026-11-05T03:06:00Z'],
            ], JSON_THROW_ON_ERROR));

            $body = (string) $app->handle(TestApp::request('GET', '/'))->getBody();

            $this->assertStringContainsString('読めなかった電文があります：震源・震度に関する情報（11月5日 12時05分）', $body);
            $this->assertStringContainsString('取れなかった電文があります：震度速報（11月5日 12時06分）', $body);
            $this->assertStringNotContainsString('取れなかった電文があります：震源・震度に関する情報', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function warnsWhenRepliesCannotBeRead(): void
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            TestApp::logIn($app, $container);
            $this->assertStringContainsString('返信を読む設定（IMAP）がありません。「設定」で登録してください。', (string) $app->handle(TestApp::request('GET', '/'))->getBody());

            $settings = $container->get(Settings::class);
            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(Settings::class, $settings);
            $this->assertInstanceOf(SystemState::class, $state);
            $settings->save(['imap.host' => 'imap.example.co.jp']);
            $state->set('imap.last_error', 'IMAP のサーバーが断りました：NO [AUTHENTICATIONFAILED]');
            $body = (string) $app->handle(TestApp::request('GET', '/'))->getBody();

            $this->assertStringNotContainsString('返信を読む設定（IMAP）がありません', $body);
            $this->assertStringContainsString('返信を読めていません：IMAP のサーバーが断りました：NO [AUTHENTICATIONFAILED]', $body);

            // The run that read them last may have died without a word (out of memory, say).
            $state->set('imap.last_error', '');
            $state->set('imap.last_checked_at', '2026-11-05T02:00:00Z');
            $this->assertStringContainsString('返信を 30 分以上読めていません（最後は 11月5日 11時00分）。', (string) $app->handle(TestApp::request('GET', '/'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function showsTheQuakeBeingFollowedAboveTheWaitingThings(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            TestApp::logIn($app, $container);
            $records = new QuakeRecords($container);
            $quake = $records->quake('20261105121200', '宮城県沖・最大震度6弱', '2026-11-05T03:00:00Z');
            $records->contact($quake, Notification::SENT);
            $check = $records->contact($quake, Notification::SENT);
            $records->notifications->recordAnswer($check, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:10:00Z'));
            $replies = $container->get(ReplyRepository::class);
            $this->assertInstanceOf(ReplyRepository::class, $replies);
            $replies->record(null, 'reply', null, 'private@example.net', '無事です', '無事です', null, new DateTimeImmutable('2026-11-05T03:05:00Z'));

            $body = (string) $app->handle(TestApp::request('GET', '/'))->getBody();

            $tile = strpos($body, '<span class="tile-label">返信なし</span>');
            $waiting = strpos($body, '照合できなかったメールが 1 通あります');
            $this->assertNotFalse($tile);
            $this->assertNotFalse($waiting);
            $this->assertLessThan($waiting, $tile);
            $this->assertStringContainsString('/contacts/' . $check . '/check', $body);
            $this->assertStringContainsString('href="/earthquakes/' . $quake . '"', $body);
            $this->assertStringContainsString('<h2>⚠ 要確認</h2>', $body);
            $this->assertStringContainsString('<h2>✕ 返信なし</h2>', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function putsAStoppedFeedAboveTheQuake(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            TestApp::logIn($app, $container);
            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(SystemState::class, $state);
            $state->set('feed.last_checked_at', Time::format($clock->now()->modify('-20 minutes')));
            $records = new QuakeRecords($container);
            $records->contact($records->quake('20261105121200', '宮城県沖・最大震度6弱', '2026-11-05T03:00:00Z'), Notification::SENT);

            $body = (string) $app->handle(TestApp::request('GET', '/'))->getBody();

            $stopped = strpos($body, '15 分以上、気象庁の情報を確かめていません');
            $tile = strpos($body, '<span class="tile-label">返信なし</span>');
            $this->assertNotFalse($stopped);
            $this->assertNotFalse($tile);
            $this->assertLessThan($tile, $stopped);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function saysAllIsWellOnlyWhenNothingWaits(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            TestApp::logIn($app, $container);
            $state = $container->get(SystemState::class);
            $settings = $container->get(Settings::class);
            $replies = $container->get(ReplyRepository::class);
            $this->assertInstanceOf(SystemState::class, $state);
            $this->assertInstanceOf(Settings::class, $settings);
            $this->assertInstanceOf(ReplyRepository::class, $replies);
            $state->set('feed.last_checked_at', Time::format($clock->now()->modify('-1 minute')));
            $settings->save(['smtp.host' => 'smtp.example.co.jp', 'mail.from_address' => 'anpi@example.co.jp', 'imap.host' => 'imap.example.co.jp']);

            $calm = (string) $app->handle(TestApp::request('GET', '/'))->getBody();
            $this->assertStringContainsString('✓ 異常なし', $calm);
            $this->assertStringContainsString('気象庁の情報を 1 分前に確認', $calm);
            $this->assertStringNotContainsString('対応中の地震はありません', $calm);

            $mail = $replies->record(null, 'reply', null, 'private@example.net', '無事です', '無事です', null, new DateTimeImmutable('2026-11-05T03:05:00Z'));
            $waiting = (string) $app->handle(TestApp::request('GET', '/'))->getBody();
            $this->assertStringNotContainsString('✓ 異常なし', $waiting);
            $this->assertStringContainsString('対応中の地震はありません', $waiting);

            $replies->deleteUnmatched($mail);
            $settings->save(['smtp.host' => '']);
            $stopped = (string) $app->handle(TestApp::request('GET', '/'))->getBody();
            $this->assertStringContainsString('メールの送信の設定がありません', $stopped);
            $this->assertStringNotContainsString('✓ 異常なし', $stopped);
            $this->assertStringContainsString('対応中の地震はありません', $stopped);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function foldsBothListsAfterTwentyOnTheHome(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            TestApp::logIn($app, $container);
            $records = new QuakeRecords($container);
            $quake = $records->quake('20261105121200', '宮城県沖・最大震度6弱', '2026-11-05T03:00:00Z');

            for ($i = 0; $i < 21; $i++) {
                $records->contact($quake, Notification::SENT);
                $check = $records->contact($quake, Notification::SENT);
                $records->notifications->recordAnswer($check, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:10:00Z'));
            }

            $body = (string) $app->handle(TestApp::request('GET', '/'))->getBody();

            $this->assertSame(2, substr_count($body, 'ほか 1 人を表示'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function putsTheAcknowledgeButtonRightAfterTheGapWarningOnce(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            TestApp::logIn($app, $container);
            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(SystemState::class, $state);
            $state->set('feed.last_checked_at', Time::format($clock->now()->modify('-1 minute')));
            $state->set('feed.gap_since', Time::format($clock->now()->modify('-2 hours')));
            $records = new QuakeRecords($container);
            $records->contact($records->quake('20261105121200', '宮城県沖・最大震度6弱', '2026-11-05T03:00:00Z'), Notification::SENT);

            $body = (string) $app->handle(TestApp::request('GET', '/'))->getBody();

            $gap = strpos($body, '気象庁の情報に抜けがあります');
            $form = strpos($body, 'action="/feed/acknowledge"');
            $tile = strpos($body, '<span class="tile-label">返信なし</span>');
            $this->assertNotFalse($gap);
            $this->assertNotFalse($form);
            $this->assertNotFalse($tile);
            $this->assertLessThan($form, $gap);
            $this->assertLessThan($tile, $form);
            $this->assertSame(1, substr_count($body, 'action="/feed/acknowledge"'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function showsZeroToCheckWhenEveryoneIsChecked(): void
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);

        try {
            TestApp::logIn($app, $container);
            $records = new QuakeRecords($container);
            $quake = $records->quake('20261105121200', '宮城県沖・最大震度6弱', '2026-11-05T03:00:00Z');
            $check = $records->contact($quake, Notification::SENT);
            $records->notifications->recordAnswer($check, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:10:00Z'));
            $records->notifications->markChecked($check, 'admin', new DateTimeImmutable('2026-11-05T03:11:00Z'));

            $body = (string) $app->handle(TestApp::request('GET', '/'))->getBody();

            $this->assertStringContainsString('<span class="tile-n">0</span><span class="tile-label">要確認</span>', $body);
            $this->assertStringContainsString('うち確認済み 1 人', $body);
            $this->assertStringContainsString('<h2>⚠ 要確認</h2>', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
