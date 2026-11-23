<?php

declare(strict_types=1);

namespace ReBuji\Tests\Mail;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Mail\AccountUnavailable;
use ReBuji\Mail\MailUnavailable;
use ReBuji\Mail\OutgoingMail;
use ReBuji\Mail\RecipientDeferred;
use ReBuji\Mail\RecipientRefused;
use ReBuji\Mail\SmtpMailSender;
use ReBuji\Settings\SmtpSettings;
use ReBuji\Tests\Support\FakeSmtpServer;
use ReflectionMethod;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

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
    public function reportsAFailedLogInAsAccountUnavailable(): void
    {
        $server = FakeSmtpServer::start(['rejectAuth' => true]);

        try {
            $this->expectException(AccountUnavailable::class);

            (new SmtpMailSender(self::settings($server->port())))->send(self::mail('ok@example.com'));
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function reportsAServerNobodyAnswersAsAccountUnavailable(): void
    {
        $this->expectException(AccountUnavailable::class);

        // Nothing listens on port 9 (discard).
        (new SmtpMailSender(self::settings(9)))->send(self::mail('ok@example.com'));
    }

    #[Test]
    public function keepsARefusedSenderApartFromTheAccount(): void
    {
        $server = FakeSmtpServer::start(['rejectSender' => true]);

        try {
            (new SmtpMailSender(self::settings($server->port())))->send(self::mail('ok@example.com'));
            $this->fail('A refused sender should be reported');
        } catch (MailUnavailable $exception) {
            $this->assertNotInstanceOf(AccountUnavailable::class, $exception);
            $this->assertStringContainsString('553', $exception->getMessage());
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function logsInWithoutSending(): void
    {
        $server = FakeSmtpServer::start();

        try {
            (new SmtpMailSender(self::settings($server->port())))->logIn();

            $transcript = $server->transcript();
            $this->assertStringContainsString('C: AUTH ', $transcript);
            $this->assertStringContainsString('C: QUIT', $transcript);
            $this->assertStringNotContainsString('C: MAIL FROM', $transcript);
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function logsInAgainOnAFreshConnection(): void
    {
        $server = FakeSmtpServer::start();

        try {
            $sender = new SmtpMailSender(self::settings($server->port()));
            $sender->send(self::mail('ok@example.com'));
            $sender->logIn();

            $this->assertSame(2, substr_count($server->transcript(), 'S: 220 fake.example ESMTP'));
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function connectsWithoutAUsername(): void
    {
        $server = FakeSmtpServer::start();

        try {
            (new SmtpMailSender(new SmtpSettings('127.0.0.1', $server->port(), 'none', '', '', 'anpi@example.co.jp', '安否確認')))->logIn();

            $transcript = $server->transcript();
            $this->assertStringContainsString('C: EHLO', $transcript);
            $this->assertStringContainsString('C: QUIT', $transcript);
            $this->assertStringNotContainsString('C: AUTH', $transcript);
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function reportsAFailedLogInCheck(): void
    {
        $server = FakeSmtpServer::start(['rejectAuth' => true]);

        try {
            $this->expectException(AccountUnavailable::class);

            (new SmtpMailSender(self::settings($server->port())))->logIn();
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function reportsMissingSettingsWhenLoggingIn(): void
    {
        try {
            (new SmtpMailSender(null))->logIn();
            $this->fail('Missing settings should be reported');
        } catch (MailUnavailable $exception) {
            $this->assertNotInstanceOf(AccountUnavailable::class, $exception);
            $this->assertStringContainsString('メールの送信の設定がありません', $exception->getMessage());
        }
    }

    #[Test]
    public function givesUpOnAServerThatNeverAnswers(): void
    {
        // Listens but never accepts, so the greeting never comes.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        // The rental server's own setting.
        $previous = ini_set('default_socket_timeout', '10');
        $started = microtime(true);

        try {
            (new SmtpMailSender(self::settings($port), 1))->send(self::mail('ok@example.com'));
            $this->fail('A server that never answers should be reported as unavailable');
        } catch (MailUnavailable) {
            $this->assertLessThan(5.0, microtime(true) - $started);
            $this->assertSame('10', ini_get('default_socket_timeout'));
        } finally {
            ini_set('default_socket_timeout', (string) $previous);
            fclose($server);
        }
    }

    #[Test]
    public function refusesToLogInWithoutTls(): void
    {
        $server = FakeSmtpServer::start();

        try {
            try {
                (new SmtpMailSender(self::settings($server->port(), 'starttls')))->send(self::mail('ok@example.com'));
                $this->fail('A server without STARTTLS should not get the password');
            } catch (AccountUnavailable) {
                // expected
            }

            $this->assertStringNotContainsString('C: AUTH', $server->transcript());
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function recordsAFailedLogInOnTheAccount(): void
    {
        $server = FakeSmtpServer::start(['rejectAuth' => true]);
        [$account, $state] = SendingAccountTest::account();

        try {
            try {
                (new SmtpMailSender(self::settings($server->port()), 15, $account))->send(self::mail('ok@example.com'));
                $this->fail('A failed log-in should be reported');
            } catch (AccountUnavailable) {
                // expected
            }

            $this->assertTrue($account->isFailing());
            $this->assertStringContainsString('535', (string) $state->get('mail.last_error'));
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function recordsASentMailOnTheAccount(): void
    {
        $server = FakeSmtpServer::start();
        [$account] = SendingAccountTest::account();
        $account->failed('x');

        try {
            (new SmtpMailSender(self::settings($server->port()), 15, $account))->send(self::mail('ok@example.com'));

            $this->assertFalse($account->isFailing());
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function countsARefusedSenderAsAWorkingAccount(): void
    {
        $server = FakeSmtpServer::start(['rejectSender' => true]);
        [$account] = SendingAccountTest::account();
        $account->failed('x');

        try {
            try {
                (new SmtpMailSender(self::settings($server->port()), 15, $account))->send(self::mail('ok@example.com'));
                $this->fail('A refused sender should be reported');
            } catch (MailUnavailable) {
                // expected
            }

            $this->assertFalse($account->isFailing());
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function recordsALogInCheck(): void
    {
        $server = FakeSmtpServer::start();
        [$account, , $clock] = SendingAccountTest::account();

        try {
            (new SmtpMailSender(self::settings($server->port()), 15, $account))->logIn();

            $this->assertFalse($account->isDue($clock->now()->modify('+59 minutes')));
        } finally {
            $server->stop();
        }
    }

    #[Test]
    public function leavesTheAccountAloneWithoutSettings(): void
    {
        [$account, , $clock] = SendingAccountTest::account();

        try {
            (new SmtpMailSender(null, 15, $account))->send(self::mail('ok@example.com'));
            $this->fail('Missing settings should be reported');
        } catch (MailUnavailable) {
            // expected
        }

        $this->assertTrue($account->isDue($clock->now()));
        $this->assertFalse($account->isFailing());
    }

    #[Test]
    public function reportsMissingSettings(): void
    {
        $this->expectException(MailUnavailable::class);
        $this->expectExceptionMessage('メールの送信の設定がありません');

        (new SmtpMailSender(null))->send(self::mail('ok@example.com'));
    }

    #[Test]
    public function trustsTheGivenCa(): void
    {
        $settings = new SmtpSettings('smtp.example.co.jp', 465, 'ssl', 'anpi', 'secret', 'anpi@example.co.jp', '安否確認', '/certs/ca.pem');

        $this->assertSame(['ssl' => ['cafile' => '/certs/ca.pem']], self::streamOptions($settings));
        $this->assertSame([], self::streamOptions(self::settings(25)));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function streamOptions(SmtpSettings $settings): array
    {
        $transport = (new ReflectionMethod(SmtpMailSender::class, 'transport'))->invoke(new SmtpMailSender($settings));
        assert($transport instanceof EsmtpTransport);
        $stream = $transport->getStream();
        assert($stream instanceof SocketStream);

        // The only way to see what the transport will give the socket.
        /** @psalm-suppress InternalMethod */
        return $stream->getStreamOptions();
    }

    /**
     * The fake server has no TLS, so the tests send without it ('none' is for tests only; the settings page offers ssl
     * and starttls).
     */
    private static function settings(int $port, string $encryption = 'none'): SmtpSettings
    {
        return new SmtpSettings('127.0.0.1', $port, $encryption, 'anpi', 'secret', 'anpi@example.co.jp', '安否確認');
    }

    private static function mail(string $to): OutgoingMail
    {
        return new OutgoingMail('anpi@example.co.jp', '安否確認', $to, self::SUBJECT, "山田 花子 さん\n\n地震がありました。\n", '0123abcd@example.co.jp');
    }
}
