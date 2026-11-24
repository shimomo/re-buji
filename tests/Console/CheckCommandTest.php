<?php

declare(strict_types=1);

namespace ReBuji\Tests\Console;

use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Auth\AdminRepository;
use ReBuji\Config\Config;
use ReBuji\Config\ConfigFile;
use ReBuji\Config\Environment;
use ReBuji\Console\CheckCommand;
use ReBuji\Console\ConsoleIo;
use ReBuji\Container;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\Imap\RawMail;
use ReBuji\Mail\MailSender;
use ReBuji\Paths;
use ReBuji\SystemState;
use ReBuji\Tests\Support\FakeMailbox;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\MailFixtures;
use ReBuji\Tests\Support\RecordingMailSender;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * @author shimomo
 */
final class CheckCommandTest extends TestCase
{
    #[Test]
    public function passesWithWorkingMail(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            $mailbox = new FakeMailbox();
            [$code, $io] = $this->check($root, ['--wait-reply', '--timeout=10'], $sender, $this->answering($mailbox, $sender), true);

            $this->assertSame(0, $code);
            $this->assertSame(5, substr_count($io->output(), '✓ '));
            $this->assertStringNotContainsString('✕', $io->output());
            $this->assertStringContainsString('✓ テストメールを admin@example.jp に送った', $io->output());
            $this->assertSame(1, preg_match('/^message-id: (\S+)$/m', $io->output(), $match));
            $this->assertSame($sender->sent[0]->messageId, $match[1]);
            $this->assertStringContainsString('admin@example.jp からの返信を待っています（最長 10 秒）…', $io->output());
            $this->assertStringContainsString('✓ 返信を読めた', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function sendsToTheGivenAddress(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            [$code] = $this->check($root, ['--to=someone@example.jp'], $sender, new FakeMailbox(), false);

            $this->assertSame(0, $code);
            $this->assertSame(['someone@example.jp'], $sender->recipients());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function keepsTheNumberForTheReplyJob(): void
    {
        // The reply job leaves a reply known only by this number for the wait (Docker).
        $root = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            [$code, , $container] = $this->check($root, ['--to=someone@example.jp'], $sender, new FakeMailbox(), false);

            $this->assertSame(0, $code);
            $this->assertSame(1, preg_match('/#([0-9a-f]{8})\]/', $sender->sent[0]->subject, $match));
            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(SystemState::class, $state);
            $this->assertSame($match[1], $state->get('check.test_number'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function failsWithoutAdmin(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            [$code, $io] = $this->check($root, [], $sender, new FakeMailbox(), false);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('管理者がいません。先に bin/console admin:create を流してください。', $io->errors());
            $this->assertSame([], $sender->attempts);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function failsWhenImapIsDown(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            $mailbox = new FakeMailbox();
            $mailbox->unavailable = true;
            [$code, $io] = $this->check($root, ['--wait-reply'], $sender, $mailbox, true);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('✕ 返信用のメールボックス（IMAP）にログインできる：Connection to imap.example.co.jp:993 timed out', $io->output());
            $this->assertSame(['admin@example.jp'], $sender->attempts);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function failsWhenTheMailCannotBeSent(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            $sender->unavailable = true;
            [$code, $io] = $this->check($root, [], $sender, new FakeMailbox(), true);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('✕ テストメールを admin@example.jp に送った：', $io->output());
            $this->assertStringNotContainsString('message-id:', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function saysTheWaitWasSkippedWhenImapIsDown(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $mailbox = new FakeMailbox();
            $mailbox->unavailable = true;
            [$code, $io] = $this->check($root, ['--wait-reply'], new RecordingMailSender(), $mailbox, true);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('✕ 返信の確認はしませんでした（受信か送信に失敗したため）', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function saysNothingOfTheWaitWithoutWaitReply(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            $sender->unavailable = true;
            [, $io] = $this->check($root, [], $sender, new FakeMailbox(), true);

            $this->assertStringNotContainsString('返信の確認', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function saysTheWaitWasSkippedWhenSendingFails(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            $sender->unavailable = true;
            [$code, $io] = $this->check($root, ['--wait-reply'], $sender, new FakeMailbox(), true);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('✕ 返信の確認はしませんでした（受信か送信に失敗したため）', $io->output());
            $this->assertStringNotContainsString('返信を待っています', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function timesOutWithoutReply(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io] = $this->check($root, ['--wait-reply', '--timeout=0'], new RecordingMailSender(), new FakeMailbox(), true);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('✕ 返信が見つかりませんでした', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function pollsEveryFiveSecondsUntilTheTimeout(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $slept = [];
            [$code] = $this->check($root, ['--wait-reply', '--timeout=12'], new RecordingMailSender(), new FakeMailbox(), true, $slept);

            $this->assertSame(1, $code);
            $this->assertSame([5, 5, 2], $slept);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function rejectsUnknownOption(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io] = $this->check($root, ['--nope'], new RecordingMailSender(), new FakeMailbox(), false);

            $this->assertSame(2, $code);
            $this->assertStringContainsString('使い方：console check', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * A mailbox that holds a reply to whatever was sent: the Message-ID is only known once the mail has gone out.
     */
    private function answering(FakeMailbox $inner, RecordingMailSender $sender): Mailbox
    {
        return new class ($inner, $sender) implements Mailbox {
            private int $answered = 0;

            public function __construct(private readonly FakeMailbox $inner, private readonly RecordingMailSender $sender)
            {
                //
            }

            #[Override]
            public function unseen(DateTimeImmutable $since, int $limit): iterable
            {
                foreach (array_slice($this->sender->sent, $this->answered) as $mail) {
                    $this->inner->add(MailFixtures::reply('admin@example.jp', '', $mail->messageId));
                    $this->answered++;
                }

                return $this->inner->unseen($since, $limit);
            }

            #[Override]
            public function answersTo(DateTimeImmutable $since, string $messageId, string $token, int $limit): iterable
            {
                foreach (array_slice($this->sender->sent, $this->answered) as $mail) {
                    $this->inner->add(MailFixtures::reply('admin@example.jp', '', $mail->messageId));
                    $this->answered++;
                }

                return $this->inner->answersTo($since, $messageId, $token, $limit);
            }

            #[Override]
            public function markSeen(RawMail $mail): void
            {
                $this->inner->markSeen($mail);
            }
        };
    }

    /**
     * @param list<string> $args
     * @param list<int> $slept
     *
     * @return array{int, ConsoleIo, ContainerInterface}
     */
    private function check(string $root, array $args, RecordingMailSender $sender, Mailbox $mailbox, bool $withAdmin, array &$slept = []): array
    {
        $paths = new Paths($root);
        $env = new Environment([
            'REBUJI_ENV_CONFIG' => '1',
            'REBUJI_SMTP_HOST' => 'smtp.example.jp',
            'REBUJI_MAIL_FROM_ADDRESS' => 'anpi@example.jp',
            'REBUJI_IMAP_HOST' => 'imap.example.jp',
        ]);
        $clock = new FrozenClock();
        is_dir($root . '/storage') || mkdir($root . '/storage');
        (new ConfigFile($paths->storage('config.php')))->save(new Config([]));
        $container = Container::build($paths, [
            Environment::class => $env,
            ClockInterface::class => $clock,
            MailSender::class => $sender,
            Mailbox::class => $mailbox,
        ]);
        $db = $container->get(Database::class);
        $this->assertInstanceOf(Database::class, $db);
        (new Migrator($db, Migrations::all(), $clock))->migrate();

        if ($withAdmin) {
            (new AdminRepository($db, $clock))->create('管理 太郎', 'admin@example.jp', 'long-enough-pass');
        }

        $io = ConsoleIo::memory();
        $command = new CheckCommand($paths, $env, $container, static function (int $seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        return [$command->run($args, $io), $io, $container];
    }
}
