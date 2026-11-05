<?php

declare(strict_types=1);

namespace ReBuji\Tests\Employees;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Employees\EmployeeCity;
use ReBuji\Employees\EmployeeInput;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TestDatabase;

/**
 * @author shimomo
 */
final class EmployeeRepositoryTest extends TestCase
{
    #[Test]
    #[DataProvider('drivers')]
    public function savesAndReadsAnEmployee(string $driver): void
    {
        [$employees] = $this->repository($driver);
        $id = $employees->create($this->hanako());
        $employee = $employees->find($id);

        $this->assertNotNull($employee);
        $this->assertSame($id, $employee->id);
        $this->assertSame('安否 花子', $employee->name);
        $this->assertSame('総務部', $employee->department);
        $this->assertSame(['hanako@example.com', 'Hanako.Home@example.net'], $employee->emails);
        $this->assertEquals([new EmployeeCity('1310100', '勤務地'), new EmployeeCity('0110100', '自宅')], $employee->cities);
        $this->assertSame("階段で\n転びやすい", $employee->memo);
        $this->assertTrue($employee->active);
        $this->assertEquals([$employee], $employees->all());
        $this->assertSame(['all' => 1, 'active' => 1], $employees->counts());
    }

    #[Test]
    #[DataProvider('drivers')]
    public function updateReplacesAddressesAndCities(string $driver): void
    {
        [$employees] = $this->repository($driver);
        $id = $employees->create($this->hanako());
        $employees->update($id, EmployeeInput::from('安否 花子', '営業部', ['hanako@example.org'], [['code' => '0120200', 'label' => '']], '', false));
        $employee = $employees->find($id);

        $this->assertNotNull($employee);
        $this->assertSame('営業部', $employee->department);
        $this->assertSame(['hanako@example.org'], $employee->emails);
        $this->assertEquals([new EmployeeCity('0120200', '')], $employee->cities);
        $this->assertFalse($employee->active);
        $this->assertNull($employees->addressOwner('hanako@example.com'));
        $this->assertSame(['all' => 1, 'active' => 0], $employees->counts());
    }

    #[Test]
    #[DataProvider('drivers')]
    public function deleteRemovesEverything(string $driver): void
    {
        [$employees, $db] = $this->repository($driver);
        $id = $employees->create($this->hanako());
        $employees->delete($id);

        $this->assertNull($employees->find($id));
        $this->assertSame([], $employees->all());
        $this->assertSame(0, (int) $db->fetchValue('SELECT COUNT(*) FROM employee_emails'));
        $this->assertSame(['all' => 0, 'active' => 0], $employees->counts());
    }

    #[Test]
    #[DataProvider('drivers')]
    public function findsWhoUsesAnAddress(string $driver): void
    {
        [$employees] = $this->repository($driver);
        $id = $employees->create($this->hanako());

        $this->assertSame(['id' => $id, 'name' => '安否 花子'], $employees->addressOwner(' HANAKO@example.com '));
        $this->assertNull($employees->addressOwner('nobody@example.com'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function listsCodesThatCanNoLongerBeJudged(string $driver): void
    {
        // A code that was valid when registered but is gone from a newer code table (here: Hamamatsu before 2024).
        [$employees, $db] = $this->repository($driver);
        $id = $employees->create($this->hanako());
        $db->execute('INSERT INTO employee_cities (employee_id, position, city_code, label) VALUES (?, 9, ?, ?)', [$id, '2213100', '実家']);

        $this->assertSame([['employee_id' => $id, 'name' => '安否 花子', 'code' => '2213100']], $employees->unknownCities());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        return TestDatabase::each();
    }

    /**
     * @return array{EmployeeRepository, Database}
     */
    private function repository(string $driver): array
    {
        $db = TestDatabase::open($driver);
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();

        return [new EmployeeRepository($db, $clock), $db];
    }

    private function hanako(): EmployeeInput
    {
        return EmployeeInput::from(
            '安否 花子',
            '総務部',
            ['hanako@example.com', 'Hanako.Home@example.net'],
            [['code' => '1310100', 'label' => '勤務地'], ['code' => '0110100', 'label' => '自宅']],
            "階段で\n転びやすい",
            true,
        );
    }
}
