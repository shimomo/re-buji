<?php

declare(strict_types=1);

namespace ReBuji\Tests\Replies;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Replies\ReplyRepository;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TestDatabase;

/**
 * @author shimomo
 */
final class ReplyRepositoryTest extends TestCase
{
    #[Test]
    #[DataProvider('drivers')]
    public function keepsRepliesAndUnmatchedMailsApart(string $driver): void
    {
        $db = $this->migrated($driver);
        $replies = new ReplyRepository($db, new FrozenClock());
        $contact = $this->contact($db);
        $matched = $replies->record($contact, 'reply', 'thread', 'hanako@example.com', 'Re: 地震', '足を怪我しました', 'r1@phone.example', new DateTimeImmutable('2026-11-05T03:28:00Z'));
        $older = $replies->record(null, 'reply', null, 'private@example.net', '無事です', '無事です', 'r2@phone.example', new DateTimeImmutable('2026-11-05T03:20:00Z'));
        $newer = $replies->record(null, 'bounce', null, 'MAILER-DAEMON@example.co.jp', 'Undelivered Mail', '', null, new DateTimeImmutable('2026-11-05T03:30:00Z'));

        $this->assertEquals([['kind' => 'reply', 'body' => '足を怪我しました', 'receivedAt' => new DateTimeImmutable('2026-11-05T03:28:00Z')]], $replies->forNotification($contact));
        $this->assertSame([$newer, $older], array_map(static fn(array $row): int => $row['id'], $replies->unmatched()));
        $this->assertSame('private@example.net', $replies->unmatched()[1]['fromAddress']);
        $this->assertSame(2, $replies->countUnmatched());
        $this->assertTrue($replies->knows('r1@phone.example'));
        $this->assertFalse($replies->knows('r9@phone.example'));
        $this->assertFalse($replies->deleteUnmatched($matched));
        $this->assertTrue($replies->deleteUnmatched($older));
        $this->assertSame(1, $replies->countUnmatched());
    }

    #[Test]
    #[DataProvider('drivers')]
    public function cutsLongSubjectsAndBodies(string $driver): void
    {
        $db = $this->migrated($driver);
        $replies = new ReplyRepository($db, new FrozenClock());

        $replies->record(null, 'reply', null, 'taro@example.com', str_repeat('件', 300), str_repeat('本', 5000), null, new DateTimeImmutable('2026-11-05T03:28:00Z'));

        $row = $replies->unmatched()[0];
        $this->assertSame(255, mb_strlen($row['subject']));
        $this->assertSame(4000, mb_strlen($row['body']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        return TestDatabase::each();
    }

    private function migrated(string $driver): Database
    {
        $db = TestDatabase::open($driver);
        (new Migrator($db, Migrations::all(), new FrozenClock()))->migrate();

        return $db;
    }

    private function contact(Database $db): int
    {
        $now = '2026-11-05T03:00:00Z';
        $db->execute("INSERT INTO employees (name, department, memo, active, created_at, updated_at) VALUES ('山田 花子', '', '', 1, ?, ?)", [$now, $now]);
        $employee = $db->lastInsertId();
        $db->execute("INSERT INTO earthquakes (kind, event_id, title, detected_at, threshold, delivery, late, created_at, updated_at) VALUES ('quake', '1', '地震', ?, '5-', 'auto', 0, ?, ?)", [$now, $now, $now]);
        $quake = $db->lastInsertId();
        $db->execute("INSERT INTO notifications (earthquake_id, employee_id, employee_name, employee_department, token, cities, priority, state, sent_count, created_at, updated_at) VALUES (?, ?, '山田 花子', '', 'a1b2c3d4', '[]', 5, 'sent', 1, ?, ?)", [$quake, $employee, $now, $now]);

        return $db->lastInsertId();
    }
}
