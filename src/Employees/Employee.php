<?php

declare(strict_types=1);

namespace ReBuji\Employees;

/**
 * A registered employee. Addresses and municipalities keep the order they were entered in.
 *
 * @author shimomo
 */
final readonly class Employee
{
    /**
     * @param list<string> $emails
     * @param list<EmployeeCity> $cities
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $department,
        public array $emails,
        public array $cities,
        public string $memo,
        public bool $active,
    ) {
        //
    }
}
