<?php

declare(strict_types=1);

namespace ReBuji\Http;

/**
 * @author shimomo
 */
interface Session
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /**
     * Issues a new session id (after login), keeping the data.
     */
    public function regenerate(): void;

    public function clear(): void;

    /**
     * A reference to an array kept in the session, for code that stores by reference (slim/csrf).
     *
     * @return array<array-key, mixed>
     */
    public function &reference(string $key): array;
}
