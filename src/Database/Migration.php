<?php

declare(strict_types=1);

namespace ReBuji\Database;

/**
 * One change to the database schema. The SQL may differ between SQLite and MySQL; keep the differences inside
 * statements().
 *
 * On SQLite, a migration runs inside a transaction (see Migrator), where `PRAGMA foreign_keys = OFF` does nothing. A
 * migration that rebuilds a table by dropping it would fire `ON DELETE CASCADE` and delete the rows that point at it
 * (dropping `notifications` deletes their replies and messages). Rebuild a referenced table without dropping it, or
 * turn the pragma off outside the transaction first.
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
