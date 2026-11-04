<?php

declare(strict_types=1);

namespace ReBuji\Cron;

use Psr\Container\ContainerInterface;

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
        return [];
    }
}
