<?php

declare(strict_types=1);

namespace ReBuji\Migrations;

use Override;
use ReBuji\Database\Migration;

/**
 * The EventIDs of quakes an admin deleted, so their later telegrams do not make the quake again. A row is kept for
 * good: one per deleted quake, and a telegram may come days later.
 *
 * @author shimomo
 */
final class M0005CreateDeletedEvents implements Migration
{
    #[Override]
    public function version(): string
    {
        return '0005';
    }

    #[Override]
    public function description(): string
    {
        return '消した地震の EventID';
    }

    #[Override]
    public function statements(string $driver): array
    {
        $table = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        return [
            "CREATE TABLE deleted_events (event_id VARCHAR(32) NOT NULL PRIMARY KEY, deleted_at VARCHAR(20) NOT NULL){$table}",
        ];
    }
}
