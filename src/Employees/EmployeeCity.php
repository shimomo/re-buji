<?php

declare(strict_types=1);

namespace ReBuji\Employees;

/**
 * One of an employee's municipalities: a JMA code and an optional label such as "勤務地" or "自宅".
 *
 * @author shimomo
 */
final readonly class EmployeeCity
{
    public function __construct(public string $code, public string $label)
    {
        //
    }
}
