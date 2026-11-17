<?php

declare(strict_types=1);

namespace ReBuji\Database;

use Psr\Clock\ClockInterface;
use ReBuji\Time;
use RuntimeException;

/**
 * Applies pending migrations and records each one in schema_migrations, which the migrator creates itself.
 *
 * With a lock file, one update runs at a time: a second one (the update button pressed twice) is refused with
 * MigrationInProgress instead of applying the same changes again and failing.
 *
 * On SQLite, a migration's statements and its record are applied in one transaction, so a migration that fails
 * halfway leaves nothing behind. MySQL commits DDL implicitly (and PDO's commit() would then throw "There is no active
 * transaction"), so there each statement is applied and the version recorded in turn.
 *
 * @author shimomo
 */
final class Migrator
{
    /**
     * @param list<Migration> $migrations
     */
    public function __construct(
        private readonly Database $db,
        private readonly array $migrations,
        private readonly ClockInterface $clock,
        private readonly ?string $lockPath = null,
    ) {
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
     *
     * @throws MigrationInProgress while another update holds the lock
     */
    public function migrate(): array
    {
        if ($this->lockPath === null) {
            return $this->applyPending();
        }

        $lock = fopen($this->lockPath, 'c');

        if ($lock === false) {
            throw new RuntimeException('Cannot open the migration lock: ' . $this->lockPath);
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            throw new MigrationInProgress('Another database update is running');
        }

        try {
            // Read under the lock: the update that held it may have applied what this one was asked to.
            return $this->applyPending();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return list<string>
     */
    private function applyPending(): array
    {
        $applied = [];

        foreach ($this->pending() as $migration) {
            if ($this->db->driver() === 'sqlite') {
                $this->db->transaction(function () use ($migration): void {
                    $this->apply($migration);
                });
            } else {
                $this->apply($migration);
            }

            $applied[] = $migration->version();
        }

        return $applied;
    }

    private function apply(Migration $migration): void
    {
        foreach ($migration->statements($this->db->driver()) as $statement) {
            $this->db->execute($statement);
        }

        $this->db->execute('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)', [$migration->version(), Time::format($this->clock->now())]);
    }

    private function ensureTable(): void
    {
        $this->db->execute('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(16) NOT NULL PRIMARY KEY, applied_at VARCHAR(20) NOT NULL)');
    }
}
