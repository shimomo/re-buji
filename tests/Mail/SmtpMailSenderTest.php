<?php

declare(strict_types=1);

namespace ReBuji\Tests\Mail;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Mail\MailUnavailable;
use ReBuji\Mail\OutgoingMail;
use ReBuji\Mail\RecipientDeferred;
use ReBuji\Mail\RecipientRefused;
use ReBuji\Mail\SmtpMailSender;
use ReBuji\Settings\SmtpSettings;
use ReBuji\Tests\Support\FakeSmtpServer;

/**
 * @author shimomo
 */
final class SmtpMailSenderTest extends TestCase
{
    private const string SUBJECT = '[安否確認 #a1b2c3d4] 地震がありました（駿河湾・最大震度6弱）';

    #[Test]
    public function sendsThroughTheAccount(): void
    {
        $server = FakeSmtpServer::start();

        try {
            (new SmtpMailSender(self::settings($server->port())))->send(self::mail('taro..yamada@docomo.ne.jp'));

            $transcript = $server->transcript();
            $this->assertStringContainsString('C: AUTH ', $transcript);
            $this->assertStringContainsString('C: MAIL FROM:<anpi@example.co.jp>', $transcript);
            $this->assertStringContainsString('C: RCPT TO:<"taro..yamada"@docomo.ne.jp>', $transcript);

            $headers = $server->lastHeaders();
            $this->assertSame('<0123abcd@example.co.jp>', $headers['Message-ID'] ?? null);
            $this->assertSame('auto-generated', $headers['Auto-Submitted'] ?? null);
            $this->assertSame('anpi@example.co.jp', $headers['Reply-To'] ?? null);
            $this->assertSame('安否確認 <anpi@example.co.jp>', $headers['From'] ?? null);
            $this->assertSame(self::SUBJECT, $headers['Subject'] ?? null);
            $this->assertStringContainsString('charset=utf-8', $headers['Content-Type'] ?? '');
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function reportsARefusedRecipientAndKeepsSending(): void
    {
        $server = FakeSmtpServer::start(['rejectRecipients' => ['gone@example.com']]);

        try {
            $sender = new SmtpMailSender(self::settings($server->port()));

            try {
                $sender->send(self::mail('gone@example.com'));
                $this->fail('A refused recipient should be reported');
            } catch (RecipientRefused $exception) {
                $this->assertStringContainsString('550', $exception->getMessage());
            }

            $sender->send(self::mail('ok@example.com'));

            $this->assertStringContainsString('C: RCPT TO:<ok@example.com>', $server->transcript());
            $this->assertStringContainsString('S: 250 2.0.0 Ok: queued', $server->transcript());
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function reportsATemporarilyRefusedRecipientAndKeepsSending(): void
    {
        $server = FakeSmtpServer::start(['deferRecipients' => ['taro@docomo.ne.jpp'], 'policyRecipients' => ['blocked@example.com']]);

        try {
            $sender = new SmtpMailSender(self::settings($server->port()));

            foreach (['taro@docomo.ne.jpp' => '450', 'blocked@example.com' => '554 5.7.1'] as $address => $answer) {
                try {
                    $sender->send(self::mail($address));
                    $this->fail('A deferred recipient should be reported: ' . $address);
                } catch (RecipientDeferred $exception) {
                    $this->assertStringContainsString($answer, $exception->getMessage());
                }
            }

            $sender->send(self::mail('ok@example.com'));

            $this->assertStringContainsString('C: RCPT TO:<ok@example.com>', $server->transcript());
            $this->assertStringContainsString('S: 250 2.0.0 Ok: queued', $server->transcript());
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function reportsAFailedLogInAsUnavailable(): void
    {
        $server = FakeSmtpServer::start(['rejectAuth' => true]);

        try {
            $this->expectException(MailUnavailable::class);

            (new SmtpMailSender(self::settings($server->port())))->send(self::mail('ok@example.com'));
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function reportsAServerNobodyAnswersAsUnavailable(): void
    {
        $this->expectException(MailUnavailable::class);

        // Nothing listens on port 9 (discard).
        (new SmtpMailSender(self::settings(9)))->send(self::mail('ok@example.com'));
    }

    #[Test]
    public function reportsMissingSettings(): void
    {
        $this->expectException(MailUnavailable::class);
        $this->expectExceptionMessage('メールの送信の設定がありません');

        (new SmtpMailSender(null))->send(self::mail('ok@example.com'));
    }

    private static function settings(int $port): SmtpSettings
    {
        return new SmtpSettings('127.0.0.1', $port, 'starttls', 'anpi', 'secret', 'anpi@example.co.jp', '安否確認');
    }

    private static function mail(string $to): OutgoingMail
    {
        return new OutgoingMail('anpi@example.co.jp', '安否確認', $to, self::SUBJECT, "山田 花子 さん\n\n地震がありました。\n", '0123abcd@example.co.jp');
    }
}
