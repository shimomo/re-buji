<?php

declare(strict_types=1);

namespace ReBuji\Database;

use Psr\Clock\ClockInterface;
use ReBuji\Time;

/**
 * Applies pending migrations and records each one in schema_migrations, which the migrator creates itself.
 *
 * MySQL commits DDL implicitly, so migrations are not wrapped in transactions; each version is applied and recorded in
 * turn.
 *
 * @author shimomo
 */
final class Migrator
{
    /**
     * @param list<Migration> $migrations
     */
    public function __construct(private readonly Database $db, private readonly array $migrations, private readonly ClockInterface $clock)
    {
        //
    }

    /**
     * @return list<Migration>
     */
    public function pending(): array
    {
        $this->ensureTable();
        $applied = array_map(
            static fn(array $row): string => (string) $row['version'],
            $this->db->fetchAll('SELECT version FROM schema_migrations'),
        );

        return array_values(array_filter($this->migrations, static fn(Migration $m): bool => !in_array($m->version(), $applied, true)));
    }

    /**
     * @return list<string> the versions applied
     */
    public function migrate(): array
    {
        $applied = [];

        foreach ($this->pending() as $migration) {
            foreach ($migration->statements($this->db->driver()) as $statement) {
                $this->db->execute($statement);
            }

            $this->db->execute('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)', [$migration->version(), Time::format($this->clock->now())]);
            $applied[] = $migration->version();
        }

        return $applied;
    }

    private function ensureTable(): void
    {
        $this->db->execute('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(16) NOT NULL PRIMARY KEY, applied_at VARCHAR(20) NOT NULL)');
    }
}
