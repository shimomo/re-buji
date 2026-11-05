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
final class EmployeeTablesTest extends TestCase
{
    #[Test]
    #[DataProvider('drivers')]
    public function removesAddressesAndCitiesWithTheEmployee(string $driver): void
    {
        $db = $this->migrated($driver);
        $id = $this->employee($db);
        $db->execute('INSERT INTO employee_emails (employee_id, position, address, address_key) VALUES (?, 0, ?, ?)', [$id, 'Taro@example.com', 'taro@example.com']);
        $db->execute('INSERT INTO employee_cities (employee_id, position, city_code, label) VALUES (?, 0, ?, ?)', [$id, '1310100', '勤務地']);

        $db->execute('DELETE FROM employees WHERE id = ?', [$id]);

        $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM employee_emails'));
        $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM employee_cities'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function refusesTheSameAddressKeyTwice(string $driver): void
    {
        $db = $this->migrated($driver);
        $first = $this->employee($db);
        $second = $this->employee($db);
        $db->execute('INSERT INTO employee_emails (employee_id, position, address, address_key) VALUES (?, 0, ?, ?)', [$first, 'Taro@example.com', 'taro@example.com']);

        $this->expectException(PDOException::class);
        $db->execute('INSERT INTO employee_emails (employee_id, position, address, address_key) VALUES (?, 0, ?, ?)', [$second, 'taro@example.com', 'taro@example.com']);
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
            ['安否 太郎', '総務部', '', '2026-11-05T03:00:00Z', '2026-11-05T03:00:00Z'],
        );

        return $db->lastInsertId();
    }
}
