<?php

declare(strict_types=1);

namespace ReBuji\Tests\Install;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Install\SetupReplyFinder;
use ReBuji\Mail\Imap\MailboxUnavailable;
use ReBuji\Tests\Support\FakeMailbox;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\MailFixtures;

/**
 * @author shimomo
 */
final class SetupReplyFinderTest extends TestCase
{
    #[Test]
    public function findsAReplyByItsReferencesAndMarksItSeen(): void
    {
        $mailbox = new FakeMailbox();
        $uid = $mailbox->add(MailFixtures::reply('admin@example.com', '', 'abc@example.jp', 'Re: こんにちは'));

        $this->assertTrue((new SetupReplyFinder($mailbox))->find((new FrozenClock())->now(), 'abc@example.jp', 'n0'));
        $this->assertSame([$uid], $mailbox->seen);
    }

    #[Test]
    public function findsAReplyByTheNumberInTheSubject(): void
    {
        $mailbox = new FakeMailbox();
        $mailbox->add(MailFixtures::reply('admin@example.com', '', null, 'Re: [Re:無事 の確認 #1234abcd] このメールに返信してください'));

        $this->assertTrue((new SetupReplyFinder($mailbox))->find((new FrozenClock())->now(), 'other@example.jp', '1234abcd'));
    }

    #[Test]
    public function ignoresAutoRepliesAndLeavesThemUnseen(): void
    {
        $mailbox = new FakeMailbox();
        $mailbox->add(MailFixtures::autoReply('admin@example.com', 'abc@example.jp'));

        $this->assertFalse((new SetupReplyFinder($mailbox))->find((new FrozenClock())->now(), 'abc@example.jp', '1234abcd'));
        $this->assertSame([], $mailbox->seen);
    }

    #[Test]
    public function passesOnAnUnavailableMailbox(): void
    {
        $mailbox = new FakeMailbox();
        $mailbox->unavailable = true;

        $this->expectException(MailboxUnavailable::class);

        (new SetupReplyFinder($mailbox))->find((new FrozenClock())->now(), 'abc@example.jp', '1234abcd');
    }
}
