<?php

declare(strict_types=1);

namespace ReBuji\Monitor;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use ReBuji\SystemState;
use ReBuji\Time;

/**
 * Whether cron is alive: stale when it never finished a run, or not for 15 minutes or more.
 *
 * Plan 3 switches this to the last successful feed poll.
 *
 * @author shimomo
 */
final class Health
{
    public const int STALE_AFTER_MINUTES = 15;

    public function __construct(private readonly SystemState $state, private readonly ClockInterface $clock)
    {
        //
    }

    public function lastRunAt(): ?DateTimeImmutable
    {
        $value = $this->state->get('cron.last_finished_at');

        if ($value === null) {
            return null;
        }

        try {
            return Time::parse($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function isStale(): bool
    {
        $lastRun = $this->lastRunAt();

        return $lastRun === null || $this->clock->now()->getTimestamp() - $lastRun->getTimestamp() >= self::STALE_AFTER_MINUTES * 60;
    }

    public function lastError(): ?string
    {
        $error = $this->state->get('cron.last_error');

        return $error === null || $error === '' ? null : $error;
    }
}
