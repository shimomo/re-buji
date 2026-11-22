<?php

declare(strict_types=1);

namespace ReBuji\Monitor;

/**
 * The secret in the status URL.
 *
 * @author shimomo
 */
final class StatusToken
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
