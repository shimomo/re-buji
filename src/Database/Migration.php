<?php

declare(strict_types=1);

namespace ReBuji\Database;

/**
 * One change to the database schema. The SQL may differ between SQLite and MySQL; keep the differences inside
 * statements().
 *
 * @author shimomo
 */
interface Migration
{
    /**
     * Four digits, applied in order: "0001", "0002", …
     */
    public function version(): string;

    public function description(): string;

    /**
     * @param 'sqlite'|'mysql'|string $driver
     *
     * @return list<string>
     */
    public function statements(string $driver): array;
}
