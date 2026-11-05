<?php

declare(strict_types=1);

namespace ReBuji\Cron;

/**
 * @author shimomo
 */
enum CronOutcome
{
    case Ran;

    /**
     * Another run still held the lock, so no job ran.
     */
    case Skipped;
}
