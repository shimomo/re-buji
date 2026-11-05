<?php

declare(strict_types=1);

namespace ReBuji\Cron;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReBuji\SystemState;
use ReBuji\Time;
use RuntimeException;
use Throwable;

/**
 * Runs the jobs under a lock, so two runs never overlap, and records when cron last started and finished.
 *
 * A failing job is logged and recorded in cron.last_error; the others still run. cron.last_finished_at is written
 * even then, because cron itself is alive; a fully successful run clears cron.last_error.
 *
 * @author shimomo
 */
final class CronRunner
{
    /**
     * @param list<CronJob> $jobs
     */
    public function __construct(
        private readonly string $lockPath,
        private readonly array $jobs,
        private readonly SystemState $state,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        //
    }

    public function run(): CronOutcome
    {
        $lock = fopen($this->lockPath, 'c');

        if ($lock === false) {
            throw new RuntimeException('Cannot open the cron lock: ' . $this->lockPath);
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->logger->info('cron skipped: the previous run is still going');

            return CronOutcome::Skipped;
        }

        try {
            $now = $this->clock->now();
            $this->state->set('cron.last_started_at', Time::format($now));
            $error = '';

            foreach ($this->jobs as $job) {
                try {
                    $job->run($now);
                } catch (Throwable $exception) {
                    $error = $job->name() . ': ' . $exception->getMessage();
                    $this->logger->error('cron job failed: ' . $job->name(), ['exception' => $exception]);
                }
            }

            $this->state->set('cron.last_error', $error);
            $this->state->set('cron.last_finished_at', Time::format($this->clock->now()));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return CronOutcome::Ran;
    }
}
