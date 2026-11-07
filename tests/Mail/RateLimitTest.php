<?php

declare(strict_types=1);

namespace ReBuji\Tests\Mail;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
        $db = TestDatabase::open($driver);
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();
        $settings = new Settings($db, $clock);
        $settings->save(['hourly_limit' => '10']);
        $messages = new MessageRepository($db);

        return [new RateLimit($messages, $settings), $messages];
    }

    private static function sent(MessageRepository $messages, DateTimeImmutable $at, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $messages->record(null, 'test', 'admin@example.com', bin2hex(random_bytes(16)) . '@example.co.jp', true, '', $at);
        }
    }
}
