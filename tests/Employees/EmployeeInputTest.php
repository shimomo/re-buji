<?php

declare(strict_types=1);

namespace ReBuji\Tests\Employees;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Employees\EmployeeCity;
use ReBuji\Employees\EmployeeInput;

/**
 * @author shimomo
 */
final class EmployeeInputTest extends TestCase
{
    #[Test]
    public function dropsBlankRowsAndTrims(): void
    {
        $input = EmployeeInput::from(
            ' 安否 花子 ',
            ' 総務部 ',
            ['', ' taro@example.com '],
            [['code' => '', 'label' => ' '], ['code' => ' 110100 ', 'label' => ' 勤務地 ']],
            " 階段で転びやすい\n",
            true,
        );

        $this->assertSame('安否 花子', $input->name);
        $this->assertSame('総務部', $input->department);
        $this->assertSame(['taro@example.com'], $input->emails);
        $this->assertEquals([new EmployeeCity('0110100', '勤務地')], $input->cities);
        $this->assertSame('階段で転びやすい', $input->memo);
        $this->assertTrue($input->active);
    }

    #[Test]
    public function keepsHalfFilledMunicipalityRows(): void
    {
        // A label or a prefecture without a municipality is a mistake to report, not a blank row to drop.
        $input = EmployeeInput::from('安否 花子', '', ['hanako@example.com'], [
            ['code' => '1310100', 'label' => '勤務地'],
            ['code' => '', 'label' => '自宅'],
            ['code' => '', 'label' => '', 'prefecture' => '01'],
            ['code' => '', 'label' => '', 'prefecture' => ''],
        ], '', true);

        $this->assertEquals([new EmployeeCity('1310100', '勤務地'), new EmployeeCity('', '自宅'), new EmployeeCity('', '')], $input->cities);
    }
}
