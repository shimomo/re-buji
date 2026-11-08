<?php

declare(strict_types=1);

namespace ReBuji\Tests\Database;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TestDatabase;

/**
 * @author shimomo
 */
final class ReplyTablesTest extends TestCase
{
    private const string NOW = '2026-11-05T03:00:00Z';

    #[Test]
    #[DataProvider('drivers')]
    public function removesRepliesWithTheEarthquake(string $driver): void
    {
        $db = $this->migrated($driver);
        $earthquake = $this->earthquake($db);
        $contact = $this->contact($db, $earthquake, $this->employee($db), 'a1b2c3d4');
        $this->reply($db, $contact, 'r1@phone.example');

        $db->execute('DELETE FROM earthquakes WHERE id = ?', [$earthquake]);

        $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM replies'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function keepsUnmatchedMailsWithoutAContact(string $driver): void
    {
        $db = $this->migrated($driver);

        $this->reply($db, null, 'r1@phone.example');

        $this->assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM replies WHERE notification_id IS NULL'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function refusesTheSameIncomingMailTwice(string $driver): void
    {
        $db = $this->migrated($driver);
        $this->reply($db, null, 'r1@phone.example');

        $this->expectException(PDOException::class);
        $this->reply($db, null, 'r1@phone.example');
    }

    #[Test]
    #[DataProvider('drivers')]
    public function addsTheAnswerAndBounceColumns(string $driver): void
    {
        $db = $this->migrated($driver);
        $contact = $this->contact($db, $this->earthquake($db), $this->employee($db), 'a1b2c3d4');
        $db->execute("UPDATE notifications SET answer = 'safe', answer_source = 'proxy', answered_by = '安否 太郎', proxy_note = '電話で確認', auto_replied_at = ?, checked_at = ?, checked_by = '安否 太郎' WHERE id = ?", [self::NOW, self::NOW, $contact]);
        $db->execute("INSERT INTO messages (notification_id, kind, address, message_id, sent, error, attempted_at, bounced_at, bounce_reason) VALUES (?, 'first', 'gone@example.com', 'm1@example.co.jp', 1, '', ?, ?, '5.1.1')", [$contact, self::NOW, self::NOW]);

        $this->assertSame('safe', $db->fetchValue('SELECT answer FROM notifications WHERE id = ?', [$contact]));
        $this->assertSame('5.1.1', $db->fetchValue('SELECT bounce_reason FROM messages'));
    }

    private function reply(Database $db, ?int $contact, string $messageId): void
    {
        $db->execute(
            "INSERT INTO replies (notification_id, kind, matched_by, from_address, subject, body, message_id, received_at, created_at) VALUES (?, 'reply', NULL, 'taro@example.com', 'Re: test', '', ?, ?, ?)",
            [$contact, $messageId, self::NOW, self::NOW],
        );
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

    private function employee(Database $db): int
    {
        $db->execute(
            'INSERT INTO employees (name, department, memo, active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)',
            ['安否 太郎', '総務部', '', self::NOW, self::NOW],
        );

        return $db->lastInsertId();
    }

    private function earthquake(Database $db): int
    {
        $db->execute(
            "INSERT INTO earthquakes (kind, event_id, title, detected_at, threshold, delivery, late, created_at, updated_at) VALUES ('quake', ?, ?, ?, '5-', 'auto', 0, ?, ?)",
            ['20090811050711', '駿河湾・最大震度6弱', '2009-08-10T20:07:00Z', self::NOW, self::NOW],
        );

        return $db->lastInsertId();
    }

    private function contact(Database $db, int $earthquake, int $employee, string $token): int
    {
        $db->execute(
            "INSERT INTO notifications (earthquake_id, employee_id, employee_name, employee_department, token, cities, priority, state, sent_count, created_at, updated_at) VALUES (?, ?, '安否 太郎', '総務部', ?, '[]', 7, 'sent', 1, ?, ?)",
            [$earthquake, $employee, $token, self::NOW, self::NOW],
        );

        return $db->lastInsertId();
    }
}
