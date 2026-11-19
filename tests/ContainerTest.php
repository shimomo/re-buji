<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use DateTimeImmutable;
use JmaXml\Feed\FeedType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use ReBuji\Container;
use ReBuji\Database\Database;
use ReBuji\Feed\FeedJob;
use ReBuji\Mail\MailSender;
use ReBuji\Mail\SmtpMailSender;
use ReBuji\Paths;
use ReBuji\Tests\Support\FakeJmaServer;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;

/**
 * @author shimomo
 */
final class ContainerTest extends TestCase
{
    #[Test]
    public function givesCronLongerThanAPageToWaitOnTheMailServer(): void
    {
        [, , $root] = TestApp::installed();

        try {
            $page = Container::build(new Paths($root))->get(MailSender::class);
            $cron = Container::forCron(new Paths($root))->get(MailSender::class);

            $this->assertInstanceOf(SmtpMailSender::class, $page);
            $this->assertInstanceOf(SmtpMailSender::class, $cron);
            $this->assertSame(15, $page->timeoutSeconds);
            $this->assertSame(60, $cron->timeoutSeconds);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function savesWhatTheFeedClientSawThroughTheFeedJob(): void
    {
        $server = new FakeJmaServer();
        $now = new DateTimeImmutable('2009-08-10T20:12:00Z');
        [, $container, $root] = TestApp::installed([ClientInterface::class => $server, ClockInterface::class => new FrozenClock($now)]);

        try {
            $entry = [['url' => FakeJmaServer::telegramUrl('20090810200900', 'VXSE51')]];
            $server->serve(FeedType::EarthquakeVolcano->highFrequencyUrl(), FakeJmaServer::feed('2009-08-10T20:12:00Z', $entry));
            $server->serve(FeedType::EarthquakeVolcano->longTermUrl(), FakeJmaServer::feed('2009-08-10T20:12:00Z', $entry));
            $job = $container->get(FeedJob::class);
            $db = $container->get(Database::class);
            $this->assertInstanceOf(FeedJob::class, $job);
            $this->assertInstanceOf(Database::class, $db);

            // The first run returns no telegram and only marks the one it saw.
            $job->run($now);

            $this->assertSame(2, (int) $db->fetchValue('SELECT COUNT(*) FROM feed_states'));
            $this->assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM feed_seen'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
