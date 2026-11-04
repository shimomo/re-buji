<?php

declare(strict_types=1);

namespace ReBuji\Cron;

use DateTimeImmutable;

/**
 * One piece of work done on every cron run. Keep it short: rental servers stop cron jobs that run long.
 *
 * @author shimomo
 */
interface CronJob
{
    public function name(): string;

    public function run(DateTimeImmutable $now): void;
}
