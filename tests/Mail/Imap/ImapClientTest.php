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
    public function readsAtMostTheLimit(): void
    {
        [$dir, $server] = self::mailbox(5);

        try {
            $mails = (new ImapMailbox(self::settings($server->port())))->unseen(new DateTimeImmutable(self::SINCE), 2);

            $this->assertSame([1, 2], array_map(static fn($mail): int => $mail->uid, $mails));
            $this->assertSame((string) file_get_contents($dir . '/1.eml'), $mails[0]->raw);
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

    private static function settings(int $port): ImapSettings
    {
        return new ImapSettings('127.0.0.1', $port, 'none', 'anpi', 'secret');
    }

    /**
     * An inbox of $count small messages, served by a fake server.
     *
     * @param array{rejectLogin?: bool} $options
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
