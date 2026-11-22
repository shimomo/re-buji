<?php

declare(strict_types=1);

namespace ReBuji\Tests\Quakes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\Notification;
use ReBuji\Quakes\QuakeSnapshot;
use ReBuji\Replies\ReplyRepository;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\QuakeRecords;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;

/**
 * @author shimomo
 */
final class QuakeBoardPageTest extends TestCase
{
    #[Test]
    public function showsTheQuakeInTheOrderOfTheDesign(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);
            $safe = $records->contact($quake, Notification::SENT);
            $check = $records->contact($quake, Notification::SENT);
            $records->contact($quake, Notification::SENT);
            $records->notifications->recordAnswer($safe, Notification::SAFE, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:20:00Z'));
            $records->notifications->recordAnswer($check, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:28:00Z'));

            $page = (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody();

            $this->assertStringContainsString('11月5日 12:12 ごろ　宮城県沖（M6.8）最大震度6弱', $page);
            $this->assertStringContainsString('震源・震度に関する情報（12:17 発表）をもとに判定　｜　対象：震度5弱以上の市町村に登録した人', $page);
            $this->assertStringContainsString('通常（訓練ではない）', $page);
            $positions = array_map(static fn(string $text): int|false => strpos($page, $text), ['<span class="tile-label">返信なし</span>', '<span class="tile-label">要確認</span>', '<span class="tile-label">無事</span>', '<span class="tile-label">対象</span>', '<h2>返信なし', '<h2>要確認', '<h2>無事']);
            $this->assertNotContains(false, $positions);
            $sorted = $positions;
            sort($sorted);
            $this->assertSame($sorted, $positions);
            $this->assertStringContainsString('/contacts/' . $check . '/check', $page);
            $this->assertStringContainsString('<span class="tile-n">3</span><span class="tile-label">対象</span>', $page);
            $this->assertStringContainsString('<span class="tile-n">1</span><span class="tile-label">返信なし</span>', $page);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function countsTheCheckedApartFromThoseToCheck(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);
            $first = $records->contact($quake, Notification::SENT);
            $second = $records->contact($quake, Notification::SENT);
            $records->notifications->recordAnswer($first, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:28:00Z'));
            $records->notifications->recordAnswer($second, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:29:00Z'));
            $records->notifications->markChecked($first, '安否 太郎', new DateTimeImmutable('2026-11-05T03:30:00Z'));

            $page = (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody();

            $this->assertStringContainsString('<span class="tile-n">1</span><span class="tile-label">要確認</span>', $page);
            $this->assertStringContainsString('うち確認済み 1 人', $page);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function foldsTheNoReplyListAfterTwenty(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);

            for ($i = 0; $i < 21; $i++) {
                $records->contact($quake, Notification::SENT);
            }

            $this->assertStringContainsString('ほか 1 人を表示', (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function doesNotFoldThoseToCheck(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);

            for ($i = 0; $i < 21; $i++) {
                $contact = $records->contact($quake, Notification::SENT);
                $records->notifications->recordAnswer($contact, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:28:00Z'));
            }

            $this->assertStringNotContainsString('ほか', (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * Someone who wrote from an address of their own, in a new mail, is in the unmatched mails: the admin looking for
     * those who have not replied hears of them here.
     */
    #[Test]
    public function pointsToTheUnmatchedMailsAfterTheContacts(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);
            $records->contact($quake, Notification::SENT);
            $replies = $container->get(ReplyRepository::class);
            $this->assertInstanceOf(ReplyRepository::class, $replies);
            $replies->record(null, 'reply', null, 'private@example.net', '無事です', '無事です', null, new DateTimeImmutable('2026-11-05T03:30:00Z'));

            $page = (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody();

            $notice = 'この地震の連絡を送った後に、照合できなかったメールが 1 通届いています。返信なしの人からのメールかもしれません。';
            $this->assertStringContainsString($notice, $page);
            $this->assertStringContainsString('href="/mail/unmatched">一覧</a>', $page);
            $this->assertGreaterThan((int) strpos($page, '<h2>返信なし'), (int) strpos($page, $notice));
            $this->assertLessThan((int) strpos($page, '<h2>要確認'), (int) strpos($page, $notice));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function saysNothingOfUnmatchedMailsWhenEveryoneAnswered(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);
            $contact = $records->contact($quake, Notification::SENT);
            $records->notifications->recordAnswer($contact, Notification::SAFE, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:20:00Z'));
            $replies = $container->get(ReplyRepository::class);
            $this->assertInstanceOf(ReplyRepository::class, $replies);
            $replies->record(null, 'reply', null, 'private@example.net', '無事です', '無事です', null, new DateTimeImmutable('2026-11-05T03:30:00Z'));

            $page = (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody();

            $this->assertStringNotContainsString('この地震の連絡を送った後に', $page);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function showsWhatAnAutoReplySaid(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);
            $contact = $records->contact($quake, Notification::SENT);
            $records->notifications->recordAutoReply($contact, new DateTimeImmutable('2026-11-05T03:20:00Z'));
            $replies = $container->get(ReplyRepository::class);
            $this->assertInstanceOf(ReplyRepository::class, $replies);
            $replies->record($contact, 'auto_reply', 'thread', 'person1@example.com', '自動応答：不在', "11月25日まで出張中です。\n急ぎの連絡は代表へ。", null, new DateTimeImmutable('2026-11-05T03:20:00Z'));

            $page = (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody();

            $this->assertStringContainsString('自動応答：「11月25日まで出張中です。<br />', $page);
            $this->assertStringContainsString('不在の自動応答だけ', $page);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function keepsTheAdminsNoteNextToWhoRecordedIt(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);
            $contact = $records->contact($quake, Notification::SENT);
            $records->notifications->recordAutoReply($contact, new DateTimeImmutable('2026-11-05T03:20:00Z'));
            $replies = $container->get(ReplyRepository::class);
            $this->assertInstanceOf(ReplyRepository::class, $replies);
            $replies->record($contact, 'auto_reply', 'thread', 'person1@example.com', '自動応答：不在', '11月25日まで出張中です。', null, new DateTimeImmutable('2026-11-05T03:20:00Z'));
            $records->notifications->recordAnswer($contact, Notification::SAFE, 'proxy', '安否 太郎', '電話で無事を確認', new DateTimeImmutable('2026-11-05T03:30:00Z'));

            $page = (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody();

            // The auto-reply must not sit between the admin's note and the line saying who recorded it.
            $positions = array_map(static fn(string $text): int|false => strpos($page, $text), ['自動応答：「11月25日まで出張中です。」', '「電話で無事を確認」', '安否 太郎 さんが代理で記録']);
            $this->assertNotContains(false, $positions);
            $sorted = $positions;
            sort($sorted);
            $this->assertSame($sorted, $positions);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function marksAReplyChecked(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);
            $check = $records->contact($quake, Notification::SENT);
            $records->notifications->recordAnswer($check, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:28:00Z'));

            $response = $app->handle(TestApp::request('POST', '/contacts/' . $check . '/check', TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/earthquakes/' . $quake, $response->getHeaderLine('Location'));
            $checked = $records->notifications->find($check);
            $this->assertNotNull($checked);
            $this->assertEquals(new DateTimeImmutable('2026-11-05T03:12:00Z'), $checked->checkedAt);
            $this->assertSame('安否 太郎', $checked->checkedBy);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function returnsToWhereTheCheckWasPressed(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);
            $contacts = [];

            foreach (range(1, 3) as $ignored) {
                $contact = $records->contact($quake, Notification::SENT);
                $records->notifications->recordAnswer($contact, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:28:00Z'));
                $contacts[] = $contact;
            }

            $home = $app->handle(TestApp::request('POST', '/contacts/' . $contacts[0] . '/check', ['from' => 'home'] + TestApp::csrf($container)));
            $none = $app->handle(TestApp::request('POST', '/contacts/' . $contacts[1] . '/check', TestApp::csrf($container)));
            $outside = $app->handle(TestApp::request('POST', '/contacts/' . $contacts[2] . '/check', ['from' => 'https://example.com'] + TestApp::csrf($container)));

            $this->assertSame('/', $home->getHeaderLine('Location'));
            $this->assertSame('/earthquakes/' . $quake, $none->getHeaderLine('Location'));
            $this->assertSame('/earthquakes/' . $quake, $outside->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function asksTheHomeToReturnHere(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);
            $contact = $records->contact($quake, Notification::SENT);
            $records->notifications->recordAnswer($contact, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:28:00Z'));

            $home = (string) $app->handle(TestApp::request('GET', '/'))->getBody();
            $detail = (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody();

            $this->assertStringContainsString('name="from" value="home"', $home);
            $this->assertStringContainsString('/contacts/' . $contact . '/proxy?from=home', $home);
            $this->assertStringNotContainsString('name="from"', $detail);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesToCheckWhatNeedsNoChecking(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $contact = $records->contact(self::miyagi($records), Notification::SENT);

            $this->assertSame(404, $app->handle(TestApp::request('POST', '/contacts/' . $contact . '/check', TestApp::csrf($container)))->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function saysAQuakeWasCancelled(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = self::miyagi($records);
            $records->earthquakes->markCancelled($quake, new DateTimeImmutable('2026-11-05T03:15:00Z'));

            $this->assertStringContainsString('この地震は気象庁が取り消しました。', (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody());
            $this->assertSame(404, $app->handle(TestApp::request('GET', '/earthquakes/999'))->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function linksTheListToEachQuake(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $quake = self::miyagi(new QuakeRecords($container));

            $this->assertStringContainsString('href="/earthquakes/' . $quake . '"', (string) $app->handle(TestApp::request('GET', '/earthquakes'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    private static function miyagi(QuakeRecords $records): int
    {
        return $records->earthquakes->createQuake(
            '20261105121200',
            new QuakeSnapshot('宮城県沖・最大震度6弱', '宮城県沖', '6.8', '6-', null, '震源・震度に関する情報', new DateTimeImmutable('2026-11-05T03:17:00Z')),
            '5-',
            Earthquake::AUTO,
            false,
            new DateTimeImmutable('2026-11-05T03:12:00Z'),
        );
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
