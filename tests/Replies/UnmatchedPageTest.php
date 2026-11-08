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
