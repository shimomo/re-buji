<?php

declare(strict_types=1);

namespace ReBuji\Tests\Mail;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Auth\AdminRepository;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Mail\MessageRepository;
use ReBuji\Mail\RateLimit;
use ReBuji\Settings\Settings;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TestDatabase;

/**
 * @author shimomo
 */
final class RateLimitTest extends TestCase
{
    #[Test]
    #[DataProvider('drivers')]
    public function countsTheLastHour(string $driver): void
    {
        [$limit, $messages] = self::limit($driver);
        $now = new DateTimeImmutable('2009-08-10T21:00:00Z');
        self::sent($messages, $now->modify('-61 minutes'), 3);
        self::sent($messages, $now->modify('-30 minutes'), 3);
        $messages->record(null, 'test', 'gone@example.com', 'refused@example.co.jp', false, '550 5.1.1 User unknown', $now->modify('-30 minutes'));

        $this->assertSame(6, $limit->remaining($now));
        $this->assertNull($limit->nextSlotAt($now));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function saysWhenTheNextMailCanGo(string $driver): void
    {
        [$limit, $messages] = self::limit($driver);
        $now = new DateTimeImmutable('2009-08-10T21:00:00Z');
        self::sent($messages, $now->modify('-61 minutes'), 2);
        self::sent($messages, $now->modify('-48 minutes'), 4);
        self::sent($messages, $now->modify('-10 minutes'), 6);

        $this->assertSame(0, $limit->remaining($now));
        $this->assertEquals(new DateTimeImmutable('2009-08-10T21:12:00Z'), $limit->nextSlotAt($now));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function keepsRoomForTheAdminsNotices(string $driver): void
    {
        // A limit of 20 keeps back up to 2, one for each admin.
        [$limit, $messages, $admins] = self::limitWithAdmins($driver, 20);
        $admins->create('安否 太郎', 'admin@example.com', 'correct-horse-battery');
        $admins->create('安否 花子', 'hanako@example.com', 'correct-horse-battery');
        $now = new DateTimeImmutable('2009-08-10T21:00:00Z');
        self::sent($messages, $now->modify('-10 minutes'), 17);

        $this->assertSame(1, $limit->remaining($now));
        $this->assertSame(3, $limit->remainingForNotices($now));

        self::sent($messages, $now->modify('-5 minutes'), 1);

        $this->assertSame(0, $limit->remaining($now));
        $this->assertEquals(new DateTimeImmutable('2009-08-10T21:50:00Z'), $limit->nextSlotAt($now));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function keepsAtMostATenthOfTheLimitBack(string $driver): void
    {
        [$limit, , $admins] = self::limitWithAdmins($driver);

        for ($i = 1; $i <= 12; $i++) {
            $admins->create('管理者 ' . $i, 'admin' . $i . '@example.com', 'correct-horse-battery');
        }

        $now = new DateTimeImmutable('2009-08-10T21:00:00Z');

        $this->assertSame(9, $limit->remaining($now));
        $this->assertSame(10, $limit->remainingForNotices($now));

        [$limit, , $admins] = self::limitWithAdmins($driver, 100);
        $admins->create('安否 太郎', 'admin@example.com', 'correct-horse-battery');
        $admins->create('安否 花子', 'hanako@example.com', 'correct-horse-battery');

        $this->assertSame(98, $limit->remaining($now));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        return TestDatabase::each();
    }

    /**
     * @return array{RateLimit, MessageRepository}
     */
    private static function limit(string $driver): array
    {
        [$limit, $messages] = self::limitWithAdmins($driver);

        return [$limit, $messages];
    }

    /**
     * @return array{RateLimit, MessageRepository, AdminRepository}
     */
    private static function limitWithAdmins(string $driver, int $hourly = 10): array
    {
        $db = TestDatabase::open($driver);
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();
        $settings = new Settings($db, $clock);
        $settings->save(['hourly_limit' => (string) $hourly]);
        $messages = new MessageRepository($db);
        $admins = new AdminRepository($db, $clock);

        return [new RateLimit($messages, $settings, $admins), $messages, $admins];
    }

    private static function sent(MessageRepository $messages, DateTimeImmutable $at, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $messages->record(null, 'test', 'admin@example.com', bin2hex(random_bytes(16)) . '@example.co.jp', true, '', $at);
        }
    }
}
