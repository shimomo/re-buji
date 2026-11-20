<?php

declare(strict_types=1);

namespace ReBuji\Tests\Quakes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Feed\TelegramRepository;
use ReBuji\Mail\MessageRepository;
use ReBuji\Quakes\DispatchJob;
use ReBuji\Quakes\Notification;
use ReBuji\Replies\ReplyRepository;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\JmaSamples;
use ReBuji\Tests\Support\QuakeRecords;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;

/**
 * @author shimomo
 */
final class QuakeDeletionTest extends TestCase
{
    #[Test]
    public function deletesAQuakeWithItsRecords(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = $records->quake('20090811050711', '駿河湾・最大震度6弱', '2026-11-05T03:12:00Z');
            $other = $records->quake('20100705065610', '岩手県沖・最大震度4', '2026-11-05T03:00:00Z');
            $contact = $records->contact($quake, Notification::SENT);
            $records->contact($other, Notification::SENT);
            $at = new DateTimeImmutable('2026-11-05T03:12:00Z');
            self::get($container, MessageRepository::class)->record($contact, 'first', 'person1@example.com', 'm1@example.co.jp', true, '', $at);
            $replies = self::get($container, ReplyRepository::class);
            $replies->record($contact, 'reply', 'thread', 'person1@example.com', 'Re:', '', 'r1@example.com', $at);
            $replies->record(null, 'reply', null, 'stranger@example.net', '無事です', '無事です', 'r2@example.net', $at);
            JmaSamples::store(self::get($container, TelegramRepository::class), $at, '32-35_07_01_100915_VXSE51.xml', '32-35_08_02_100915_VXSE51.xml');
            $page = (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $quake))->getBody();
            $this->assertStringContainsString('/earthquakes/' . $quake . '/delete', $page);
            $this->assertStringContainsString('この地震の連絡と返信の記録をすべて消します。元に戻せません。消しますか？', $page);

            $response = $app->handle(TestApp::request('POST', '/earthquakes/' . $quake . '/delete', TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/earthquakes', $response->getHeaderLine('Location'));
            $db = $records->db;
            $this->assertNull($records->earthquakes->find($quake));
            $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM notifications WHERE earthquake_id = ?', [$quake]));
            $this->assertSame(0, (int) $db->fetchValue("SELECT COUNT(*) FROM messages WHERE message_id = 'm1@example.co.jp'"));
            $this->assertSame(0, (int) $db->fetchValue("SELECT COUNT(*) FROM replies WHERE message_id = 'r1@example.com'"));
            $this->assertSame(0, (int) $db->fetchValue("SELECT COUNT(*) FROM telegrams WHERE event_id = '20090811050711'"));
            $this->assertSame(1, (int) $db->fetchValue("SELECT COUNT(*) FROM telegrams WHERE event_id = '20100705065610'"));
            $this->assertNotNull($records->earthquakes->find($other));
            $this->assertSame(1, $replies->countUnmatched());
            $this->assertStringContainsString('地震の記録を消しました。', (string) $app->handle(TestApp::request('GET', '/earthquakes'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function doesNotBringADeletedQuakeBack(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $quake = $records->quake('20090811050711', '駿河湾・最大震度6弱', '2026-11-05T03:12:00Z');
            $records->contact($quake, Notification::SENT);
            $telegrams = self::get($container, TelegramRepository::class);
            $at = new DateTimeImmutable('2026-11-05T03:12:00Z');
            JmaSamples::store($telegrams, $at, '32-35_07_01_100915_VXSE51.xml');
            $telegrams->markProcessed('20090811050711', $at);
            $app->handle(TestApp::request('POST', '/earthquakes/' . $quake . '/delete', TestApp::csrf($container)));

            JmaSamples::store($telegrams, $at, '32-35_07_06_240613_VXSE53.xml');
            self::get($container, DispatchJob::class)->run($at);

            $this->assertNull($records->earthquakes->findByEventId('20090811050711'));
            $this->assertSame(0, (int) $records->db->fetchValue('SELECT COUNT(*) FROM notifications'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function handsTheRepliesToContactsFoldedIntoIt(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $replies = self::get($container, ReplyRepository::class);
            $a = $records->quake('20100705065511', '岩手県沿岸南部・最大震度4', '2026-11-05T02:55:00Z');
            $b = $records->quake('20100705065610', '岩手県沖・最大震度4', '2026-11-05T02:56:00Z');
            $c = $records->quake('20100705065720', '岩手県沖・最大震度3', '2026-11-05T02:57:00Z');
            $first = $records->contact($a, Notification::SENT);
            $records->notifications->recordAnswer($first, Notification::NEEDS_CHECK, 'mail', null, null, new DateTimeImmutable('2026-11-05T03:12:00Z'));
            $replies->record($first, 'auto_reply', 'thread', 'person1@example.com', '自動応答：不在', '11月25日まで出張中です。', 'a1@example.com', new DateTimeImmutable('2026-11-05T03:15:00Z'));
            $replies->record($first, 'reply', 'thread', 'person1@example.com', 'Re:', '足を怪我しました', 'r1@example.com', new DateTimeImmutable('2026-11-05T03:20:00Z'));
            $replies->record($first, 'receipt', 'thread', 'person1@example.com', '開封確認', '', 'k1@example.com', new DateTimeImmutable('2026-11-05T03:25:00Z'));
            $folded = self::foldInto($container, $records, $first, $b, $c);

            $app->handle(TestApp::request('POST', '/earthquakes/' . $a . '/delete', TestApp::csrf($container)));

            foreach ($folded as $id) {
                // The reply and the auto-reply, which the page shows; not the read receipt.
                $this->assertSame(['auto_reply', 'reply'], array_column($replies->forNotification($id), 'kind'));
            }

            $page = (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $b))->getBody();
            $this->assertStringContainsString('足を怪我しました', $page);
            $this->assertStringContainsString('自動応答：「11月25日まで出張中です。」', $page);
            $this->assertFalse($replies->knows('r1@example.com'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function handsTheAnswersToContactsFoldedIntoIt(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $a = $records->quake('20100705065511', '岩手県沿岸南部・最大震度4', '2026-11-05T02:55:00Z');
            $b = $records->quake('20100705065610', '岩手県沖・最大震度4', '2026-11-05T02:56:00Z');
            $c = $records->quake('20100705065720', '岩手県沖・最大震度3', '2026-11-05T02:57:00Z');
            $first = $records->contact($a, Notification::SENT);
            $records->notifications->recordAnswer($first, Notification::SAFE, 'proxy', '安否 太郎', '電話で確認', new DateTimeImmutable('2026-11-05T03:12:00Z'));
            $folded = self::foldInto($container, $records, $first, $b, $c);

            $response = $app->handle(TestApp::request('POST', '/earthquakes/' . $a . '/delete', TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());

            foreach ($folded as $id) {
                $contact = $records->notifications->find($id);
                $this->assertNotNull($contact);
                $this->assertSame(Notification::SAFE, $contact->answer);
                $this->assertSame('proxy', $contact->answerSource);
                $this->assertSame('安否 太郎', $contact->answeredBy);
                $this->assertSame('電話で確認', $contact->proxyNote);
                $this->assertEquals(new DateTimeImmutable('2026-11-05T03:12:00Z'), $contact->repliedAt);
                $this->assertNull($contact->mergedIntoId);
            }

            $this->assertStringContainsString('安否 太郎 さんが代理で記録', (string) $app->handle(TestApp::request('GET', '/earthquakes/' . $b))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function handsNoAnswerToAContactOfACancelledQuake(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $a = $records->quake('20100705065511', '岩手県沿岸南部・最大震度4', '2026-11-05T02:55:00Z');
            $b = $records->quake('20100705065610', '岩手県沖・最大震度4', '2026-11-05T02:56:00Z');
            $c = $records->quake('20100705065720', '岩手県沖・最大震度3', '2026-11-05T02:57:00Z');
            $first = $records->contact($a, Notification::SENT);
            $records->notifications->recordAnswer($first, Notification::SAFE, 'proxy', '安否 太郎', null, new DateTimeImmutable('2026-11-05T03:12:00Z'));
            [$cancelled, $folded] = self::foldInto($container, $records, $first, $b, $c);
            // JMA withdrew B: its contact is cancelled but still points at A.
            $records->notifications->markCancelled($cancelled);

            $app->handle(TestApp::request('POST', '/earthquakes/' . $a . '/delete', TestApp::csrf($container)));

            $withdrawn = $records->notifications->find($cancelled);
            $this->assertNotNull($withdrawn);
            $this->assertNull($withdrawn->answer);
            $this->assertSame(Notification::CANCELLED, $withdrawn->state);
            $this->assertSame(Notification::SAFE, $records->notifications->find($folded)?->answer);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function leavesAFoldedContactAloneWhenThereWasNoAnswer(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $records = new QuakeRecords($container);
            $a = $records->quake('20100705065511', '岩手県沿岸南部・最大震度4', '2026-11-05T02:55:00Z');
            $b = $records->quake('20100705065610', '岩手県沖・最大震度4', '2026-11-05T02:56:00Z');
            $first = $records->contact($a, Notification::SENT);
            [$folded] = self::foldInto($container, $records, $first, $b);

            $app->handle(TestApp::request('POST', '/earthquakes/' . $a . '/delete', TestApp::csrf($container)));

            $contact = $records->notifications->find($folded);
            $this->assertNotNull($contact);
            $this->assertNull($contact->answer);
            $this->assertSame(Notification::MERGED, $contact->state);
            $this->assertSame(200, $app->handle(TestApp::request('GET', '/earthquakes/' . $b))->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesAnUnknownQuake(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            $this->assertSame(404, $app->handle(TestApp::request('POST', '/earthquakes/999/delete', TestApp::csrf($container)))->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function get(ContainerInterface $container, string $class): object
    {
        $service = $container->get($class);
        assert($service instanceof $class);

        return $service;
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

    /**
     * Contacts of the same person for the given quakes, folded into the first contact, as a related quake's would be.
     *
     * @return non-empty-list<int>
     */
    private static function foldInto(ContainerInterface $container, QuakeRecords $records, int $first, int $quake, int ...$more): array
    {
        $contact = $records->notifications->find($first);
        assert($contact !== null && $contact->employeeId !== null);
        $employee = self::get($container, EmployeeRepository::class)->find($contact->employeeId);
        assert($employee !== null);
        $folded = [];

        foreach ([$quake, ...$more] as $quake) {
            $folded[] = $records->notifications->create($quake, $employee, [], 5, Notification::MERGED, null, $first);
        }

        return $folded;
    }
}
