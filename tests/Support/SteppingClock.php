<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * Returns the start time first, then a step later on every call: for code that watches how long it has run.
 *
 * @author shimomo
 */
final class SteppingClock implements ClockInterface
{
    private int $calls = 0;

    public function __construct(private readonly DateTimeImmutable $start, private readonly int $stepSeconds)
    {
        //
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->start->modify('+' . ($this->calls++ * $this->stepSeconds) . ' seconds');
    }
}
