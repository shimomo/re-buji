<?php

declare(strict_types=1);

namespace ReBuji\Config;

/**
 * Settings written by the installer, keyed by flat dotted names such as "db.driver".
 *
 * @author shimomo
 */
final readonly class Config
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values)
    {
        //
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
