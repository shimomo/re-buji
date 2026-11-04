<?php

declare(strict_types=1);

namespace ReBuji\Tests\Cron;

use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Cron\CronJob;
use ReBuji\Cron\CronOutcome;
use ReBuji\Cron\CronRunner;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Log\FileLogger;
use ReBuji\SystemState;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Time;
use RuntimeException;

/**
 * @author shimomo
 */
final class CronRunnerTest extends TestCase
{
    #[Test]
    public function runsJobsInOrderAndRecordsTimes(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            [$state, $clock] = $this->state();
            $calls = new \ArrayObject();
            $runner = new CronRunner($directory . '/cron.lock', [$this->job('A', $calls), $this->job('B', $calls)], $state, $clock, new FileLogger($directory . '/log', $clock));

            $this->assertSame(CronOutcome::Ran, $runner->run());
            $this->assertSame(['A', 'B'], $calls->getArrayCopy());
            $this->assertSame(Time::format($clock->now()), $state->get('cron.last_started_at'));
            $this->assertSame(Time::format($clock->now()), $state->get('cron.last_finished_at'));
            $this->assertSame('', $state->get('cron.last_error'));
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function aFailingJobDoesNotStopTheOthers(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            [$state, $clock] = $this->state();
            $calls = new \ArrayObject();
            $runner = new CronRunner($directory . '/cron.lock', [$this->job('A', $calls, new RuntimeException('feed down')), $this->job('B', $calls)], $state, $clock, new FileLogger($directory . '/log', $clock));
            $runner->run();
            $log = file($directory . '/log', FILE_IGNORE_NEW_LINES);

            $this->assertSame(['A', 'B'], $calls->getArrayCopy());
            $this->assertSame('A: feed down', $state->get('cron.last_error'));
            $this->assertSame(Time::format($clock->now()), $state->get('cron.last_finished_at'));
            $this->assertIsArray($log);
            $this->assertCount(1, $log);
            $this->assertStringContainsString('feed down', $log[0]);
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function skipsWhileAnotherRunHoldsTheLock(): void
    {
        $directory = TemporaryDirectory::create();
        $other = fopen($directory . '/cron.lock', 'c');
        $this->assertNotFalse($other);

        try {
            $this->assertTrue(flock($other, LOCK_EX | LOCK_NB));
            [$state, $clock] = $this->state();
            $calls = new \ArrayObject();
            $runner = new CronRunner($directory . '/cron.lock', [$this->job('A', $calls)], $state, $clock, new FileLogger($directory . '/log', $clock));

            $this->assertSame(CronOutcome::Skipped, $runner->run());
            $this->assertSame([], $calls->getArrayCopy());
            $this->assertNull($state->get('cron.last_started_at'));
        } finally {
            flock($other, LOCK_UN);
            fclose($other);
            TemporaryDirectory::remove($directory);
        }
    }

    /**
     * @return array{SystemState, FrozenClock}
     */
    private function state(): array
    {
        $db = Database::sqliteInMemory();
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();

        return [new SystemState($db, $clock), $clock];
    }

    /**
     * @param \ArrayObject<int, string> $calls
     */
    private function job(string $name, \ArrayObject $calls, ?RuntimeException $failure = null): CronJob
    {
        return new class ($name, $calls, $failure) implements CronJob {
            /**
             * @param \ArrayObject<int, string> $calls
             */
            public function __construct(private readonly string $name, private readonly \ArrayObject $calls, private readonly ?RuntimeException $failure)
            {
                //
            }

            #[Override]
            public function name(): string
            {
                return $this->name;
            }

            #[Override]
            public function run(DateTimeImmutable $now): void
            {
                $this->calls->append($this->name);

                if ($this->failure !== null) {
                    throw $this->failure;
                }
            }
        };
    }
}
