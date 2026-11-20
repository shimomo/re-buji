<?php

declare(strict_types=1);

namespace ReBuji\Tests\Replies;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Quakes\Notification;
use ReBuji\Replies\ReplyRepository;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\QuakeRecords;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;

/**
 * @author shimomo
 */
final class UnmatchedPageTest extends TestCase
{
    #[Test]
    public function listsUnmatchedMailNewestFirst(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $replies = self::replies($container);
            $replies->record(null, 'reply', null, 'private@example.net', '無事です', '山田です。私用のアドレスから。無事です。', 'r1@example.net', new DateTimeImmutable('2026-11-05T03:20:00Z'));
            $replies->record(null, 'bounce', null, 'MAILER-DAEMON@example.co.jp', 'Undelivered Mail', '', 'b1@example.co.jp', new DateTimeImmutable('2026-11-05T03:25:00Z'));

            $page = (string) $app->handle(TestApp::request('GET', '/mail/unmatched'))->getBody();

            $this->assertStringContainsString('登録していないアドレスからのメールや、番号の無いメールです。', $page);
            $this->assertNotFalse(strpos($page, '宛先不明'));
            $this->assertTrue(strpos($page, 'Undelivered Mail') < strpos($page, '私用のアドレスから'));
            $this->assertStringContainsString('11月5日 12:20', $page);
            $this->assertStringContainsString('返信', $page);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function labelsAReadReceipt(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            self::replies($container)->record(null, 'receipt', null, 'person1@example.com', '開封済み: お知らせ', '', 'r9@phone.example', new DateTimeImmutable('2026-11-05T03:20:00Z'));

            $this->assertStringContainsString('開封確認', (string) $app->handle(TestApp::request('GET', '/mail/unmatched'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function deletesUnmatchedMail(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $replies = self::replies($container);
            $id = $replies->record(null, 'reply', null, 'private@example.net', '無事です', '無事です', null, new DateTimeImmutable('2026-11-05T03:20:00Z'));
            $records = new QuakeRecords($container);
            $contact = $records->contact($records->quake('20261105121200', '宮城県沖', '2026-11-05T03:12:00Z'), Notification::SENT);
            $matched = $replies->record($contact, 'reply', 'thread', 'person1@example.com', 'Re:', '', null, new DateTimeImmutable('2026-11-05T03:21:00Z'));

            $response = $app->handle(TestApp::request('POST', '/mail/unmatched/' . $id . '/delete', TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/mail/unmatched', $response->getHeaderLine('Location'));
            $this->assertStringContainsString('照合できなかったメールはありません。', (string) $app->handle(TestApp::request('GET', '/mail/unmatched'))->getBody());
            $this->assertSame(404, $app->handle(TestApp::request('POST', '/mail/unmatched/' . $matched . '/delete', TestApp::csrf($container)))->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function deletesTheSelectedMail(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $replies = self::replies($container);
            $at = new DateTimeImmutable('2026-11-05T03:20:00Z');
            $a = $replies->record(null, 'auto_reply', null, 'a@example.net', '自動応答：出張中', '出張中です', null, $at);
            $b = $replies->record(null, 'receipt', null, 'b@example.net', '開封確認', '', null, $at);
            $replies->record(null, 'reply', null, 'c@example.net', '家族です', '無事です', null, $at);
            $records = new QuakeRecords($container);
            $contact = $records->contact($records->quake('20261105121200', '宮城県沖', '2026-11-05T03:12:00Z'), Notification::SENT);
            $matched = $replies->record($contact, 'reply', 'thread', 'person1@example.com', 'Re:', '', null, $at);

            $page = (string) $app->handle(TestApp::request('GET', '/mail/unmatched'))->getBody();
            $this->assertStringContainsString('name="ids[]"', $page);
            $this->assertStringContainsString('選んだメールを消す', $page);

            $response = $app->handle(TestApp::request('POST', '/mail/unmatched/delete', ['ids' => [(string) $a, (string) $b, (string) $matched]] + TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/mail/unmatched', $response->getHeaderLine('Location'));
            $after = (string) $app->handle(TestApp::request('GET', '/mail/unmatched'))->getBody();
            $this->assertStringContainsString('2 件のメールの記録を消しました。', $after);
            $this->assertStringContainsString('家族です', $after);
            $this->assertStringNotContainsString('出張中', $after);
            $this->assertStringNotContainsString('開封確認', $after);
            $this->assertCount(1, $replies->forNotification($contact));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function asksToSelectSomeMail(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            self::replies($container)->record(null, 'reply', null, 'c@example.net', '家族です', '無事です', null, new DateTimeImmutable('2026-11-05T03:20:00Z'));

            $response = $app->handle(TestApp::request('POST', '/mail/unmatched/delete', TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $after = (string) $app->handle(TestApp::request('GET', '/mail/unmatched'))->getBody();
            $this->assertStringContainsString('メールが選ばれていません。', $after);
            $this->assertStringContainsString('家族です', $after);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function saysWhenTheSelectedMailWasAlreadyGone(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $replies = self::replies($container);
            $at = new DateTimeImmutable('2026-11-05T03:20:00Z');
            $a = $replies->record(null, 'auto_reply', null, 'a@example.net', '自動応答', '出張中です', null, $at);
            $b = $replies->record(null, 'receipt', null, 'b@example.net', '開封確認', '', null, $at);
            // Another admin got there first.
            $replies->deleteUnmatched($a);

            $app->handle(TestApp::request('POST', '/mail/unmatched/delete', ['ids' => [(string) $a]] + TestApp::csrf($container)));

            $this->assertStringContainsString('選んだメールは、もう消えていました。', (string) $app->handle(TestApp::request('GET', '/mail/unmatched'))->getBody());

            $app->handle(TestApp::request('POST', '/mail/unmatched/delete', ['ids' => [(string) $a, (string) $b]] + TestApp::csrf($container)));

            $this->assertStringContainsString('1 件のメールの記録を消しました。', (string) $app->handle(TestApp::request('GET', '/mail/unmatched'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function asksBeforeDeletingOnlyWhenSomethingIsTicked(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            self::replies($container)->record(null, 'reply', null, 'c@example.net', '家族です', '無事です', null, new DateTimeImmutable('2026-11-05T03:20:00Z'));

            $this->assertStringContainsString(
                'Array.prototype.some.call(this.elements, function (e) { return e.checked; }) ? confirm(this.dataset.confirm) : true',
                (string) $app->handle(TestApp::request('GET', '/mail/unmatched'))->getBody(),
            );
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function warnsAtHomeAboutUnmatchedMail(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            self::replies($container)->record(null, 'reply', null, 'private@example.net', '無事です', '無事です', null, new DateTimeImmutable('2026-11-05T03:20:00Z'));

            $home = (string) $app->handle(TestApp::request('GET', '/'))->getBody();

            $this->assertStringContainsString('照合できなかったメールが 1 通あります。', $home);
            $this->assertStringContainsString('href="/mail/unmatched"', $home);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    private static function replies(ContainerInterface $container): ReplyRepository
    {
        $replies = $container->get(ReplyRepository::class);
        assert($replies instanceof ReplyRepository);

        return $replies;
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
