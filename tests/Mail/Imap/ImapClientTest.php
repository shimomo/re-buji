<?php

declare(strict_types=1);

namespace ReBuji\Tests\Mail\Imap;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Mail\Imap\ImapClient;
use ReBuji\Mail\Imap\ImapMailbox;
use ReBuji\Mail\Imap\ImapSettings;
use ReBuji\Mail\Imap\MailboxUnavailable;
use ReBuji\Tests\Support\FakeImapServer;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * The fake server has no TLS, so the tests use 'none' (for tests only; the settings page offers ssl and starttls).
 *
 * @author shimomo
 */
final class ImapClientTest extends TestCase
{
    private const string SINCE = '2026-11-08T00:00:00Z';

    #[Test]
    public function readsUnseenMailOldestFirst(): void
    {
        [$dir, $server] = self::mailbox(3);
        touch($dir . '/2.seen');
        $japanese = "Subject: Re: test\r\nContent-Type: text/plain; charset=ISO-2022-JP\r\n\r\n" . (string) mb_convert_encoding("無事です\r\n", 'ISO-2022-JP', 'UTF-8');
        file_put_contents($dir . '/3.eml', $japanese);

        try {
            $client = new ImapClient(self::settings($server->port()));
            $client->open();

            $this->assertSame([1, 3], $client->unseenSince(new DateTimeImmutable(self::SINCE)));
            $this->assertSame($japanese, $client->fetch(3));
            $client->close();
            $this->assertStringContainsString('UID SEARCH UNSEEN SINCE 8-Nov-2026', $server->transcript());
        } finally {
            $server->stop();
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function marksMailSeenWithoutDeletingIt(): void
    {
        [$dir, $server] = self::mailbox(3);

        try {
            $client = new ImapClient(self::settings($server->port()));
            $client->open();
            $client->markSeen(1);

            $this->assertSame([2, 3], $client->unseenSince(new DateTimeImmutable(self::SINCE)));
            $this->assertFileExists($dir . '/1.eml');
            $this->assertStringNotContainsString('EXPUNGE', $server->transcript());
            $this->assertStringContainsString('UID STORE 1 +FLAGS.SILENT (\Seen)', $server->transcript());
        } finally {
            $server->stop();
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function findsAnswersReadOrNotAfterAskingForNewMail(): void
    {
        [$dir, $server] = self::answerMailbox();

        try {
            $client = new ImapClient(self::settings($server->port()));
            $client->open();

            $this->assertSame([1, 3], $client->answersSince(new DateTimeImmutable(self::SINCE), 't1@example.jp', '#ab12cd34'));
            $transcript = $server->transcript();
            $this->assertStringContainsString('UID SEARCH SINCE 8-Nov-2026 OR HEADER IN-REPLY-TO "t1@example.jp" OR HEADER REFERENCES "t1@example.jp" HEADER SUBJECT "#ab12cd34"', $transcript);
            $this->assertLessThan(strpos($transcript, 'UID SEARCH SINCE'), strpos($transcript, ' NOOP'));
        } finally {
            $server->stop();
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function searchesOnlyWhatItIsGiven(): void
    {
        [$dir, $server] = self::answerMailbox();

        try {
            $client = new ImapClient(self::settings($server->port()));
            $client->open();

            $this->assertSame([3], $client->answersSince(new DateTimeImmutable(self::SINCE), '', '#ab12cd34'));
            $this->assertStringContainsString('UID SEARCH SINCE 8-Nov-2026 HEADER SUBJECT "#ab12cd34"', $server->transcript());
            $this->assertSame([], $client->answersSince(new DateTimeImmutable(self::SINCE), '', ''));
            // With nothing to look for, the server is not asked at all
            $this->assertSame(1, substr_count($server->transcript(), 'UID SEARCH'));
        } finally {
            $server->stop();
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function quotesTheLogIn(): void
    {
        [$dir, $server] = self::mailbox(0);

        try {
            (new ImapClient(new ImapSettings('127.0.0.1', $server->port(), 'none', 'anpi', 'pa"ss\word')))->open();

            $this->assertStringContainsString('LOGIN "anpi" "pa\"ss\\\\word"', $server->transcript());
        } finally {
            $server->stop();
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function reportsARefusedLogIn(): void
    {
        [$dir, $server] = self::mailbox(0, ['rejectLogin' => true]);

        try {
            try {
                (new ImapClient(self::settings($server->port())))->open();
                $this->fail('A refused log-in should be reported');
            } catch (MailboxUnavailable $exception) {
                $this->assertStringContainsString('AUTHENTICATIONFAILED', $exception->getMessage());
                $this->assertStringNotContainsString('secret', $exception->getMessage());
            }
        } finally {
            $server->stop();
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function reportsAServerNobodyAnswers(): void
    {
        $this->expectException(MailboxUnavailable::class);

        // Nothing listens on port 9 (discard).
        (new ImapClient(self::settings(9)))->open();
    }

    #[Test]
    public function refusesStartTlsWhenTheServerDoesNotOfferIt(): void
    {
        [$dir, $server] = self::mailbox(0);

        try {
            try {
                (new ImapClient(new ImapSettings('127.0.0.1', $server->port(), 'starttls', 'anpi', 'secret')))->open();
                $this->fail('A server without STARTTLS should not get the password');
            } catch (MailboxUnavailable) {
                // expected
            }

            $this->assertStringNotContainsString('LOGIN', $server->transcript());
        } finally {
            $server->stop();
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function explainsAFailedTlsHandshake(): void
    {
        // The fake server speaks plain IMAP, so the TLS handshake fails at once.
        [$dir, $server] = self::mailbox(0);

        try {
            try {
                (new ImapClient(new ImapSettings('127.0.0.1', $server->port(), 'ssl', 'anpi', 'secret')))->open();
                $this->fail('A failed TLS handshake should be reported');
            } catch (MailboxUnavailable $exception) {
                $this->assertMatchesRegularExpression('/^127\.0\.0\.1:\d+ につなげません：SSL operation failed/u', $exception->getMessage());
                $this->assertStringNotContainsString("\n", $exception->getMessage());
            }

            $this->assertStringNotContainsString('LOGIN', $server->transcript());
        } finally {
            $server->stop();
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function explainsAFailedStartTls(): void
    {
        [$dir, $server] = self::mailbox(0, ['failStartTls' => true]);

        try {
            try {
                (new ImapClient(new ImapSettings('127.0.0.1', $server->port(), 'starttls', 'anpi', 'secret')))->open();
                $this->fail('A failed STARTTLS should be reported');
            } catch (MailboxUnavailable $exception) {
                $this->assertMatchesRegularExpression('/^IMAP の TLS を始められませんでした：SSL/u', $exception->getMessage());
                $this->assertStringNotContainsString('理由は分かりません', $exception->getMessage());
                $this->assertStringNotContainsString("\n", $exception->getMessage());
            }

            $this->assertStringNotContainsString('LOGIN', $server->transcript());
        } finally {
            $server->stop();
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function readsAtMostTheLimit(): void
    {
        [$dir, $server] = self::mailbox(5);

        try {
            $mails = iterator_to_array((new ImapMailbox(self::settings($server->port())))->unseen(new DateTimeImmutable(self::SINCE), 2), false);

            $this->assertSame([1, 2], array_map(static fn($mail): int => $mail->uid, $mails));
            $this->assertSame((string) file_get_contents($dir . '/1.eml'), $mails[0]->raw);
        } finally {
            $server->stop();
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function fetchesAtMostAMebibyteOfAMail(): void
    {
        [$dir, $server] = self::mailbox(1);
        file_put_contents($dir . '/1.eml', "Subject: photo\r\n\r\n" . str_repeat('A', 2 * 1048576));

        try {
            $client = new ImapClient(self::settings($server->port()));
            $client->open();

            $this->assertSame(1048576, strlen($client->fetch(1)));
            $this->assertStringContainsString('UID FETCH 1 (BODY.PEEK[]<0.1048576>)', $server->transcript());
        } finally {
            $server->stop();
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function fetchesOneMailAtATime(): void
    {
        [$dir, $server] = self::mailbox(3);

        try {
            foreach ((new ImapMailbox(self::settings($server->port())))->unseen(new DateTimeImmutable(self::SINCE), 50) as $mail) {
                $this->assertSame(1, $mail->uid);
                $this->assertSame(1, substr_count($server->transcript(), 'UID FETCH'));

                break;
            }
        } finally {
            $server->stop();
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function saysWhenImapIsNotSetUp(): void
    {
        $this->expectException(MailboxUnavailable::class);
        $this->expectExceptionMessage('返信を読む設定（IMAP）がありません');

        (new ImapMailbox(null))->unseen(new DateTimeImmutable(self::SINCE), 50);
    }

    #[Test]
    public function trustsTheGivenCa(): void
    {
        $with = new ImapSettings('imap.example.co.jp', 993, 'ssl', 'anpi', 'secret', '/certs/ca.pem');
        $without = new ImapSettings('imap.example.co.jp', 993, 'ssl', 'anpi', 'secret');

        $this->assertSame('/certs/ca.pem', ImapClient::sslOptions($with)['cafile'] ?? null);
        $this->assertSame('imap.example.co.jp', ImapClient::sslOptions($with)['peer_name'] ?? null);
        $this->assertArrayNotHasKey('cafile', ImapClient::sslOptions($without));
    }

    private static function settings(int $port): ImapSettings
    {
        return new ImapSettings('127.0.0.1', $port, 'none', 'anpi', 'secret');
    }

    /**
     * 1: a read reply (In-Reply-To), 2: unrelated, 3: answered only by the number in its subject.
     *
     * @return array{string, FakeImapServer}
     */
    private static function answerMailbox(): array
    {
        [$dir, $server] = self::mailbox(3);
        file_put_contents($dir . '/1.eml', "Subject: Re: hello\r\nIn-Reply-To: <t1@example.jp>\r\n\r\nbody 1\r\n");
        file_put_contents($dir . '/3.eml', "Subject: Re: [Re:無事 の確認 #ab12cd34]\r\n\r\nbody 3\r\n");
        touch($dir . '/1.seen');

        return [$dir, $server];
    }

    /**
     * An inbox of $count small messages, served by a fake server.
     *
     * @param array{rejectLogin?: bool, failStartTls?: bool} $options
     *
     * @return array{string, FakeImapServer}
     */
    private static function mailbox(int $count, array $options = []): array
    {
        $dir = TemporaryDirectory::create();

        for ($uid = 1; $uid <= $count; $uid++) {
            file_put_contents($dir . '/' . $uid . '.eml', "Subject: mail {$uid}\r\nMessage-ID: <m{$uid}@example.com>\r\n\r\nbody {$uid}\r\n");
        }

        return [$dir, FakeImapServer::start($dir, $options)];
    }
}
