<?php

declare(strict_types=1);

namespace ReBuji\Employees;

/**
 * @author shimomo
 */
final readonly class ImportResult
{
    /**
     * @param list<string> $errors when not empty, nothing was written
     */
    public function __construct(public array $errors, public int $created, public int $updated)
    {
        //
    }
}
