<?php

declare(strict_types=1);

namespace ReBuji\Tests\Employees;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Employees\EmployeeCsv;
use ReBuji\Employees\EmployeeImport;
use ReBuji\Employees\EmployeeInput;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Employees\EmployeeValidator;
use ReBuji\Tests\Support\FrozenClock;

/**
 * @author shimomo
 */
final class EmployeeImportTest extends TestCase
{
    #[Test]
    public function createsAndUpdatesInOneGo(): void
    {
        [$import, $employees] = $this->import();
        $id = $employees->create($this->input('安否 太郎', 'taro@example.com'));

        $result = $import->run($this->file([
            [(string) $id, '安否 太郎（本社）', 'taro@example.com'],
            ['', '安否 花子', 'hanako@example.com'],
        ]));

        $this->assertSame([], $result->errors);
        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->updated);
        $this->assertSame('安否 太郎（本社）', $employees->find($id)?->name);
        $this->assertSame(['all' => 2, 'active' => 2], $employees->counts());
    }

    #[Test]
    public function importsNothingWhenAnyRowIsWrong(): void
    {
        [$import, $employees] = $this->import();

        $result = $import->run($this->file([
            ['', '安否 花子', 'hanako@example.com'],
            ['', '安否 次郎', 'jiro@example.com', '0110000'],
        ]));

        $this->assertCount(1, $result->errors);
        $this->assertStringStartsWith('3 行目：市町村コード 0110000', $result->errors[0]);
        $this->assertSame(0, $result->created);
        $this->assertSame(0, $employees->counts()['all']);
    }

    #[Test]
    public function refusesTheSameAddressOnTwoRows(): void
    {
        [$import] = $this->import();

        $result = $import->run($this->file([
            ['', '安否 花子', 'hanako@example.com'],
            ['', '安否 次郎', 'Hanako@Example.com'],
        ]));

        $this->assertSame(['3 行目：このメールアドレスは 2 行目と同じです：Hanako@Example.com'], $result->errors);
    }

    #[Test]
    public function allowsMovingAnAddressBetweenPeopleInTheFile(): void
    {
        [$import, $employees] = $this->import();
        $a = $employees->create($this->input('安否 太郎', 'shared@example.com'));
        $b = $employees->create($this->input('安否 花子', 'hanako@example.com'));

        $result = $import->run($this->file([
            [(string) $a, '安否 太郎', 'taro@example.com'],
            [(string) $b, '安否 花子', 'shared@example.com'],
        ]));

        $this->assertSame([], $result->errors);
        $this->assertSame(['shared@example.com'], $employees->find($b)?->emails);
    }

    #[Test]
    public function allowsMovingAnAddressToSomeoneAboveInTheFile(): void
    {
        // The receiver's row comes before the row that gives the address up.
        [$import, $employees] = $this->import();
        $a = $employees->create($this->input('安否 太郎', 'shared@example.com'));
        $b = $employees->create($this->input('安否 花子', 'hanako@example.com'));

        $result = $import->run($this->file([
            [(string) $b, '安否 花子', 'shared@example.com'],
            [(string) $a, '安否 太郎', 'taro@example.com'],
        ]));

        $this->assertSame([], $result->errors);
        $this->assertSame(['shared@example.com'], $employees->find($b)?->emails);
        $this->assertSame(['taro@example.com'], $employees->find($a)?->emails);
    }

    #[Test]
    public function allowsSwappingAddresses(): void
    {
        // Addresses entered the wrong way round, corrected in one file.
        [$import, $employees] = $this->import();
        $a = $employees->create($this->input('安否 太郎', 'hanako@example.com'));
        $b = $employees->create($this->input('安否 花子', 'taro@example.com'));

        $result = $import->run($this->file([
            [(string) $a, '安否 太郎', 'taro@example.com'],
            [(string) $b, '安否 花子', 'hanako@example.com'],
        ]));

        $this->assertSame([], $result->errors);
        $this->assertSame(['taro@example.com'], $employees->find($a)?->emails);
        $this->assertSame(['hanako@example.com'], $employees->find($b)?->emails);
    }

    #[Test]
    public function allowsANewRowToTakeAnAddressFromSomeoneBelow(): void
    {
        [$import, $employees] = $this->import();
        $a = $employees->create($this->input('安否 太郎', 'shared@example.com'));

        $result = $import->run($this->file([
            ['', '安否 花子', 'shared@example.com'],
            [(string) $a, '安否 太郎', 'taro@example.com'],
        ]));

        $this->assertSame([], $result->errors);
        $this->assertSame(1, $result->created);
        $this->assertSame(['taro@example.com'], $employees->find($a)?->emails);
    }

    #[Test]
    public function refusesAnAddressSomeoneOutsideTheFileUses(): void
    {
        [$import, $employees] = $this->import();
        $employees->create($this->input('安否 太郎', 'taro@example.com'));

        $result = $import->run($this->file([['', '安否 花子', 'taro@example.com']]));

        $this->assertSame(['2 行目：このメールアドレスは安否 太郎 さんが使っています：taro@example.com'], $result->errors);
    }

    #[Test]
    public function refusesALabelWithoutACode(): void
    {
        [$import, $employees] = $this->import();
        $csv = implode(',', EmployeeCsv::HEADER) . "\r\n" . ',安否 花子,,hanako@example.com,,,,1310100,勤務地,,自宅,,,,,,,,' . "\r\n";

        $result = $import->run($csv);

        $this->assertSame(['2 行目：市町村が入っていません：自宅'], $result->errors);
        $this->assertSame(0, $employees->counts()['all']);
    }

    #[Test]
    public function refusesAnUnknownOrRepeatedId(): void
    {
        [$import, $employees] = $this->import();
        $id = $employees->create($this->input('安否 太郎', 'taro@example.com'));

        $result = $import->run($this->file([
            ['999', '安否 花子', 'hanako@example.com'],
            [(string) $id, '安否 太郎', 'taro@example.com'],
            [(string) $id, '安否 太郎', 'taro2@example.com'],
        ]));

        $this->assertSame(['2 行目：ID 999 の人はいません。', '4 行目：ID ' . $id . ' は 3 行目にもあります。'], $result->errors);
    }

    /**
     * @return array{EmployeeImport, EmployeeRepository}
     */
    private function import(): array
    {
        $db = Database::sqliteInMemory();
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();
        $employees = new EmployeeRepository($db, $clock);

        return [new EmployeeImport($db, $employees, new EmployeeValidator($employees)), $employees];
    }

    private function input(string $name, string $email): EmployeeInput
    {
        return EmployeeInput::from($name, '', [$email], [['code' => '1310100', 'label' => '']], '', true);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3?: string}> $rows ID, name, one address and an optional code
     */
    private function file(array $rows): string
    {
        $lines = [implode(',', EmployeeCsv::HEADER)];

        foreach ($rows as $row) {
            $lines[] = implode(',', [$row[0], $row[1], '', $row[2], '', '', '', $row[3] ?? '1310100', '', '', '', '', '', '', '', '', '', '', '']);
        }

        return implode("\r\n", $lines) . "\r\n";
    }
}
