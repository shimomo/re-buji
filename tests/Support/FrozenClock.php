<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * @author shimomo
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now = new DateTimeImmutable('2026-11-05T03:12:00Z'))
    {
        //
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
