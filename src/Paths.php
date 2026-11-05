<?php

declare(strict_types=1);

namespace ReBuji;

/**
 * Where the parts of an installed copy live, relative to its root.
 *
 * @author shimomo
 */
final readonly class Paths
{
    public function __construct(private string $root)
    {
        //
    }

    public function root(): string
    {
        return $this->root;
    }

    public function public(): string
    {
        return $this->root . '/public';
    }

    public function templates(): string
    {
        return $this->root . '/templates';
    }

    /**
     * The writable directory for the config file, the SQLite database, sessions, logs and the cron lock.
     */
    public function storage(string $relative = ''): string
    {
        return $relative === '' ? $this->root . '/storage' : $this->root . '/storage/' . $relative;
    }
}
