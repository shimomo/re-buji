<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Container;
use ReBuji\Mail\MailSender;
use ReBuji\Mail\SmtpMailSender;
use ReBuji\Paths;
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
}
