<?php

declare(strict_types=1);

namespace ReBuji\Tests\Employees;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Employees\Employee;
use ReBuji\Employees\EmployeeCity;
use ReBuji\Employees\EmployeeCsv;
use ReBuji\Employees\EmployeeInput;

/**
 * @author shimomo
 */
final class EmployeeCsvTest extends TestCase
{
    #[Test]
    public function exportsWithABomAndTheHeader(): void
    {
        $csv = EmployeeCsv::export([$this->hanako()]);
        $lines = explode("\r\n", substr($csv, 3));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame(implode(',', EmployeeCsv::HEADER), $lines[0]);
        // fputcsv() quotes fields with a space or a line break.
        $this->assertSame('7,"安否 花子",総務部,hanako@example.com,hanako.home@example.net,,,1310100,勤務地,0110100,自宅,,,,,,,"階段で' . "\n" . '転びやすい",有効', $lines[1]);
    }

    #[Test]
    public function roundTripsAnExport(): void
    {
        $parsed = EmployeeCsv::parse(EmployeeCsv::export([$this->hanako()]));

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame(2, $parsed['rows'][0]['line']);
        $this->assertSame(7, $parsed['rows'][0]['id']);
        $this->assertEquals(
            new EmployeeInput('安否 花子', '総務部', ['hanako@example.com', 'hanako.home@example.net'], [new EmployeeCity('1310100', '勤務地'), new EmployeeCity('0110100', '自宅')], "階段で\n転びやすい", true),
            $parsed['rows'][0]['input'],
        );
    }

    #[Test]
    public function importsShiftJisFromExcel(): void
    {
        // Excel's plain "CSV" format is CP932, which has characters plain Shift_JIS lacks (髙, 﨑).
        $csv = (string) mb_convert_encoding($this->file(['', '髙橋 﨑子', '営業部', 'sakiko@example.com', '', '', '', '1310100', '勤務地']), 'SJIS-win', 'UTF-8');
        $parsed = EmployeeCsv::parse($csv);

        $this->assertSame([], $parsed['errors']);
        $this->assertSame('髙橋 﨑子', $parsed['rows'][0]['input']->name);
        $this->assertSame('営業部', $parsed['rows'][0]['input']->department);
    }

    #[Test]
    public function importsCodesWhoseLeadingZeroExcelDropped(): void
    {
        $parsed = EmployeeCsv::parse($this->file(['', '安否 花子', '', 'hanako@example.com', '', '', '', '110100', '']));

        $this->assertSame('0110100', $parsed['rows'][0]['input']->cities[0]->code);
    }

    #[Test]
    public function refusesAFileWithOtherHeaders(): void
    {
        $parsed = EmployeeCsv::parse("名前,メール\r\n安否 花子,hanako@example.com\r\n");

        $this->assertSame([], $parsed['rows']);
        $this->assertCount(1, $parsed['errors']);
        $this->assertStringStartsWith('1 行目の見出しが違います。', $parsed['errors'][0]);
        $this->assertStringContainsString(implode(',', EmployeeCsv::HEADER), $parsed['errors'][0]);
    }

    #[Test]
    public function skipsEmptyRowsAndCountsLines(): void
    {
        $csv = implode(',', EmployeeCsv::HEADER) . "\r\n" . str_repeat(',', 18) . "\r\n" . ',安否 花子,,hanako@example.com,,,,1310100,' . "\r\n\r\n";
        $parsed = EmployeeCsv::parse($csv);

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame(3, $parsed['rows'][0]['line']);
        $this->assertNull($parsed['rows'][0]['id']);
    }

    #[Test]
    public function reportsAWrongIdOrState(): void
    {
        $csv = $this->file(['abc', '安否 花子', '', 'hanako@example.com', '', '', '', '1310100', '', '', '', '', '', '', '', '', '', '', '休職']);
        $parsed = EmployeeCsv::parse($csv);

        $this->assertSame(['2 行目：ID は数字で書いてください：abc', '2 行目：状態は「有効」か「停止」にしてください：休職'], $parsed['errors']);
    }

    /**
     * @param list<string> $row
     */
    private function file(array $row): string
    {
        return implode(',', EmployeeCsv::HEADER) . "\r\n" . implode(',', $row) . "\r\n";
    }

    private function hanako(): Employee
    {
        return new Employee(
            7,
            '安否 花子',
            '総務部',
            ['hanako@example.com', 'hanako.home@example.net'],
            [new EmployeeCity('1310100', '勤務地'), new EmployeeCity('0110100', '自宅')],
            "階段で\n転びやすい",
            true,
        );
    }
}
