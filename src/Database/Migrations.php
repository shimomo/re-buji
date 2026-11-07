<?php

declare(strict_types=1);

namespace ReBuji\Database;

use ReBuji\Migrations\M0001CreateFoundationTables;
use ReBuji\Migrations\M0002CreateEmployeeTables;
use ReBuji\Migrations\M0003CreateQuakeTables;

/**
 * Every migration, in version order. Later plans append theirs here.
 *
 * @author shimomo
 */
final class Migrations
{
    /**
     * @return list<Migration>
     */
    public static function all(): array
    {
        return [
            new M0001CreateFoundationTables(),
            new M0002CreateEmployeeTables(),
            new M0003CreateQuakeTables(),
        ];
    }
}
