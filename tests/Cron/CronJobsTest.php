<?php

declare(strict_types=1);

namespace ReBuji\Tests\Cron;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Cron\CronJob;
use ReBuji\Cron\CronJobs;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;

/**
 * @author shimomo
 */
final class CronJobsTest extends TestCase
{
    #[Test]
    public function checksTheAccountAfterTheOtherJobsAndBeforeTheWatch(): void
    {
        [, $container, $root] = TestApp::installed();

        try {
            $names = array_map(static fn(CronJob $job): string => $job->name(), CronJobs::all($container));

            $this->assertSame(['feed', 'dispatch', 'send', 'replies', 'resend', 'mail-check', 'watch'], $names);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
