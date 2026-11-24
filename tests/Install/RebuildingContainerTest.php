<?php

declare(strict_types=1);

namespace ReBuji\Tests\Install;

use DI\FactoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReBuji\Container;
use ReBuji\Install\RebuildingContainer;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\MailSender;
use ReBuji\Paths;
use ReBuji\Tests\Support\FakeMailbox;
use ReBuji\Tests\Support\RecordingMailSender;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * @author shimomo
 */
final class RebuildingContainerTest extends TestCase
{
    #[Test]
    public function buildsTheMailServicesAnewEachTime(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $container = Container::build(new Paths($root), [
                Mailbox::class => static fn(): Mailbox => new FakeMailbox(),
                MailSender::class => static fn(): MailSender => new RecordingMailSender(),
            ]);
            $this->assertInstanceOf(FactoryInterface::class, $container);
            $rebuilding = new RebuildingContainer($container);

            $this->assertNotSame($rebuilding->get(Mailbox::class), $rebuilding->get(Mailbox::class));
            $this->assertNotSame($rebuilding->get(MailSender::class), $rebuilding->get(MailSender::class));
            $this->assertSame($rebuilding->get(ClockInterface::class), $rebuilding->get(ClockInterface::class));
            $this->assertTrue($rebuilding->has(Paths::class));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
