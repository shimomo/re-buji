<?php

declare(strict_types=1);

namespace ReBuji;

use Psr\Clock\ClockInterface;
use ReBuji\Database\Database;

/**
 * Small named values the app keeps about itself, such as when cron last ran.
 *
 * @author shimomo
 */
final class SystemState
{
    public function __construct(private readonly Database $db, private readonly ClockInterface $clock)
    {
        //
    }

    public function get(string $name): ?string
    {
        $value = $this->db->fetchValue('SELECT value FROM system_state WHERE name = ?', [$name]);

        return $value === null ? null : (string) $value;
    }

    public function set(string $name, string $value): void
    {
        $now = Time::format($this->clock->now());

        if ($this->db->execute('UPDATE system_state SET value = ?, updated_at = ? WHERE name = ?', [$value, $now, $name]) === 0) {
            $this->db->execute('INSERT INTO system_state (name, value, updated_at) VALUES (?, ?, ?)', [$name, $value, $now]);
        }
    }
}
