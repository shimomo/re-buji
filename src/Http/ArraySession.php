<?php

declare(strict_types=1);

namespace ReBuji\Http;

use Override;

/**
 * A session kept in memory, for tests.
 *
 * @author shimomo
 */
final class ArraySession implements Session
{
    public int $regenerated = 0;

    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    #[Override]
    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    #[Override]
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    #[Override]
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    #[Override]
    public function regenerate(): void
    {
        $this->regenerated++;
    }

    #[Override]
    public function clear(): void
    {
        $this->data = [];
    }

    #[Override]
    public function &reference(string $key): array
    {
        if (!isset($this->data[$key]) || !is_array($this->data[$key])) {
            $this->data[$key] = [];
        }

        /**
         * slim/csrf keeps a reference to this array, which Psalm cannot follow.
         *
         * @var array<array-key, mixed> $reference
         * @psalm-suppress UnsupportedPropertyReferenceUsage
         */
        $reference = &$this->data[$key];

        return $reference;
    }
}
