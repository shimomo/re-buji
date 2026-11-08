<?php

declare(strict_types=1);

namespace ReBuji\Tests\Replies;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Replies\IncomingMail;
use ReBuji\Tests\Support\MailFixtures;

/**
 * @author shimomo
 */
final class IncomingMailTest extends TestCase
{
    #[Test]
    public function readsAReply(): void
    {
        $raw = MailFixtures::reply('山田 花子 <Taro.Yamada@Example.com>', "足を怪我しました。\n\n> 山田 花子 さん\n", '0123abcd@example.co.jp', 'Re: [安否確認 #a1b2c3d4] 地震がありました', 'ISO-2022-JP', 'r1@phone.example');
        $raw = str_replace('References: <0123abcd@example.co.jp>', 'References: <first@example.co.jp> <0123abcd@example.co.jp> <other@example.net>', $raw);

        $mail = IncomingMail::parse($raw);

        $this->assertSame('r1@phone.example', $mail->messageId);
        $this->assertSame(['0123abcd@example.co.jp', 'first@example.co.jp', 'other@example.net'], $mail->references);
        $this->assertSame('Taro.Yamada@Example.com', $mail->fromAddress);
        $this->assertSame('Re: [安否確認 #a1b2c3d4] 地震がありました', $mail->subject);
        $this->assertStringStartsWith('足を怪我しました。', $mail->text);
        $this->assertEquals(new DateTimeImmutable('2026-11-05T12:28:00+09:00'), $mail->date);
        $this->assertFalse($mail->autoReply);
        $this->assertNull($mail->bounce);
        $this->assertSame('reply', $mail->kind());
    }

    #[Test]
    public function readsTheTextOfAnHtmlOnlyReply(): void
    {
        $raw = str_replace('Content-Type: text/plain; charset=UTF-8', 'Content-Type: text/html; charset=UTF-8', MailFixtures::reply('hanako@example.com', '<div>無事です<br>家族も</div>'));

        $this->assertSame("無事です\n家族も", trim(IncomingMail::parse($raw)->text));
    }

    #[Test]
    public function tellsAutoRepliesApart(): void
    {
        $plain = MailFixtures::reply('hanako@example.com', "不在です\n", subject: 'Re: お知らせ');

        foreach (['Auto-Submitted: auto-replied', 'X-Autoreply: yes', 'Precedence: bulk'] as $header) {
            $this->assertSame('auto_reply', IncomingMail::parse($header . "\r\n" . $plain)->kind(), $header);
        }

        $this->assertSame('auto_reply', IncomingMail::parse(MailFixtures::reply('hanako@example.com', "不在\n", subject: '【自動応答】不在にしています'))->kind());
        $this->assertSame('auto_reply', IncomingMail::parse(MailFixtures::reply('hanako@example.com', "Away\n", subject: 'Automatic reply: 地震'))->kind());
        $this->assertSame('reply', IncomingMail::parse("Auto-Submitted: no\r\n" . $plain)->kind());
    }

    #[Test]
    public function readsABounce(): void
    {
        $mail = IncomingMail::parse(MailFixtures::bounce('gone@example.com', '0123abcd@example.co.jp'));

        $this->assertSame('bounce', $mail->kind());
        $this->assertNotNull($mail->bounce);
        $this->assertSame(['gone@example.com'], $mail->bounce->recipients);
        $this->assertSame('0123abcd@example.co.jp', $mail->bounce->originalMessageId);
        $this->assertStringContainsString('5.1.1', $mail->bounce->reason);
    }

    #[Test]
    public function readsABounceFromMailerDaemonWithoutAReport(): void
    {
        $raw = "From: MAILER-DAEMON@docomo.ne.jp\r\nTo: anpi@example.co.jp\r\nSubject: Returned mail: User unknown\r\nMessage-ID: <b1@docomo.ne.jp>\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\n"
            . "The original message was received.\r\n\r\n----- Original message -----\r\nFrom: anpi@example.co.jp\r\nTo: taro..yamada@docomo.ne.jp\r\nMessage-ID: <0123abcd@example.co.jp>\r\n";

        $mail = IncomingMail::parse($raw);

        $this->assertSame('bounce', $mail->kind());
        $bounce = $mail->bounce;
        $this->assertNotNull($bounce);
        $this->assertSame('0123abcd@example.co.jp', $bounce->originalMessageId);
        $this->assertSame([], $bounce->recipients);
        $this->assertSame('Returned mail: User unknown', $bounce->reason);
    }

    #[Test]
    public function readsTextInAnUnknownCharsetAsSomething(): void
    {
        $raw = str_replace('charset=Shift_JIS', 'charset=x-sjis', MailFixtures::reply('hanako@example.com', '足を骨折しました', null, 'Re: 地震', 'Shift_JIS'));

        $text = IncomingMail::parse($raw)->text;

        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertNotSame('', trim($text));
    }

    #[Test]
    public function dropsTheQuotedBlockOfAnHtmlReply(): void
    {
        $raw = str_replace('Content-Type: text/plain; charset=UTF-8', 'Content-Type: text/html; charset=UTF-8', MailFixtures::reply('hanako@example.com', '<div>無事です</div><blockquote><div>山田 花子 さん</div></blockquote>'));

        $this->assertSame('無事です', trim(IncomingMail::parse($raw)->text));
    }

    #[Test]
    public function namesItsAttachments(): void
    {
        $this->assertSame(['IMG_0001.jpg'], IncomingMail::parse(MailFixtures::withAttachment('hanako@example.com', 'x@example.co.jp', 'IMG_0001.jpg'))->attachments);
        $this->assertSame([], IncomingMail::parse(MailFixtures::reply('hanako@example.com', '無事'))->attachments);
    }

    #[Test]
    public function tellsADelayFromABounce(): void
    {
        $mail = IncomingMail::parse(MailFixtures::delay('slow@example.com', '0123abcd@example.co.jp'));

        $this->assertSame('delayed', $mail->kind());
        $this->assertFalse($mail->bounce?->failed);
        $this->assertTrue(IncomingMail::parse(MailFixtures::bounce('gone@example.com', null))->bounce?->failed);

        // Exim warns in plain text.
        $warning = "From: Mail Delivery System <Mailer-Daemon@mail.example.com>\r\nTo: anpi@example.co.jp\r\nSubject: Warning: message 1abcde-000001-AB delayed 24 hours\r\n"
            . 'Message-ID: <w1@mail.example.com>' . "\r\nDate: " . MailFixtures::DATE . "\r\n\r\nA message that you sent has not yet been delivered to one or more of its recipients after more than 24 hours on the queue.\r\n";
        $this->assertSame('delayed', IncomingMail::parse($warning)->kind());
    }

    #[Test]
    public function refusesWhatIsNotMail(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IncomingMail::parse('');
    }
}
