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
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TestDatabase;

/**
 * @author shimomo
 */
final class QuakeTablesTest extends TestCase
{
    private const string NOW = '2026-11-05T03:00:00Z';

    #[Test]
    #[DataProvider('drivers')]
    public function keepsTheContactWhenTheEmployeeIsDeleted(string $driver): void
    {
        $db = $this->migrated($driver);
        $employee = $this->employee($db);
        $contact = $this->contact($db, $this->earthquake($db), $employee, 'a1b2c3d4');
        $this->message($db, $contact, '0123abcd');

        (new EmployeeRepository($db, new FrozenClock()))->delete($employee);

        $row = $db->fetchOne('SELECT employee_id, employee_name FROM notifications WHERE id = ?', [$contact]);
        $this->assertNotNull($row);
        $this->assertNull($row['employee_id']);
        $this->assertSame('安否 太郎', $row['employee_name']);
        $this->assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM messages'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function removesContactsAndMessagesWithTheEarthquake(string $driver): void
    {
        $db = $this->migrated($driver);
        $earthquake = $this->earthquake($db);
        $contact = $this->contact($db, $earthquake, $this->employee($db), 'a1b2c3d4');
        $this->message($db, $contact, '0123abcd');

        $db->execute('DELETE FROM earthquakes WHERE id = ?', [$earthquake]);

        $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM notifications'));
        $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM messages'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function allowsOneContactPerPersonAndEarthquake(string $driver): void
    {
        $db = $this->migrated($driver);
        $earthquake = $this->earthquake($db);
        $employee = $this->employee($db);
        $this->contact($db, $earthquake, $employee, 'a1b2c3d4');

        $this->expectException(PDOException::class);
        $this->contact($db, $earthquake, $employee, 'e5f6a7b8');
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

    private function message(Database $db, int $contact, string $messageId): void
    {
        $db->execute(
            "INSERT INTO messages (notification_id, kind, address, message_id, sent, error, attempted_at) VALUES (?, 'first', 'taro@example.com', ?, 1, '', ?)",
            [$contact, $messageId . '@example.co.jp', self::NOW],
        );
    }
}
