<?php

declare(strict_types=1);

namespace ReBuji\Cron;

use Psr\Container\ContainerInterface;
use ReBuji\Feed\FeedJob;
use ReBuji\Quakes\DispatchJob;

/**
 * The jobs of a cron run, in order. Later plans add them in the order of design 6.1: poll the feed, build events,
 * choose recipients, send mail, read replies, resend.
 *
 * @author shimomo
 */
final class CronJobs
{
    /**
     * @return list<CronJob>
     */
    public static function all(ContainerInterface $container): array
    {
        $jobs = [];

        // Design 6.1's order. Later plans insert their jobs between these.
        foreach ([FeedJob::class, DispatchJob::class] as $class) {
            $job = $container->get($class);
            assert($job instanceof CronJob);
            $jobs[] = $job;
        }

        return $jobs;
    }
}
