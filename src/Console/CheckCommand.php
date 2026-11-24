<?php

declare(strict_types=1);

namespace ReBuji\Console;

use Closure;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Auth\AdminRepository;
use ReBuji\Config\Environment;
use ReBuji\Database\Migrator;
use ReBuji\Install\SetupReplyFinder;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\MailSender;
use ReBuji\Mail\MessageRepository;
use ReBuji\Mail\NotificationMail;
use ReBuji\Paths;
use ReBuji\Settings\Settings;
use ReBuji\SystemState;
use ReBuji\Time;
use Throwable;

/**
 * Checks that a copy works end to end: the database, storage/, the reply mailbox, and a test mail that can be answered.
 * With --wait-reply it also waits for that answer, which is how a script proves that mail goes out and comes back.
 *
 * @author shimomo
 */
final class CheckCommand implements Command
{
    private const int POLL_SECONDS = 5;

    private const int DEFAULT_TIMEOUT = 300;

    /**
     * @param (Closure(int): void)|null $sleep waits that many seconds; replaced in tests so they need not wait
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly Environment $environment,
        private readonly ContainerInterface $container,
        private readonly ?Closure $sleep = null,
    ) {
        //
    }

    #[Override]
    public function name(): string
    {
        return 'check';
    }

    #[Override]
    public function summary(): string
    {
        return 'DB・storage/・メールを確かめる（引数：--to=アドレス --wait-reply --timeout=秒）';
    }

    #[Override]
    public function run(array $args, ConsoleIo $io): int
    {
        $to = null;
        $wait = false;
        $timeout = self::DEFAULT_TIMEOUT;

        foreach ($args as $arg) {
            if ($arg === '--wait-reply') {
                $wait = true;
            } elseif (preg_match('/\A--to=(.+)\z/su', $arg, $match) === 1) {
                $to = trim($match[1]);
            } elseif (preg_match('/\A--timeout=(\d+)\z/', $arg, $match) === 1) {
                $timeout = (int) $match[1];
            } else {
                $io->error('使い方：console check [--to=アドレス] [--wait-reply] [--timeout=秒]');

                return 2;
            }
        }

        $database = $this->attempt(function (): void {
            $migrator = $this->container->get(Migrator::class);
            assert($migrator instanceof Migrator);

            if ($migrator->pending() !== []) {
                throw new \RuntimeException('まだ更新していない変更があります。boot を流してください。');
            }
        });

        if ($this->report($io, 'データベースにつながり、更新が済んでいる', $database)) {
            return 1;
        }

        $failed = $this->report($io, 'storage/ に書ける', $this->storageProblem());
        $mailboxProblem = $this->attempt(function (): void {
            $mailbox = $this->container->get(Mailbox::class);
            assert($mailbox instanceof Mailbox);
            $mailbox->unseen($this->clock()->now(), 1);
        });
        $failed = $this->report($io, '返信用のメールボックス（IMAP）にログインできる', $mailboxProblem) || $failed;

        $sent = $this->sendTestMail($to, $io);

        if ($sent === null) {
            return 1;
        }

        $failed = $sent['failed'] || $failed;

        if ($wait && $sent['messageId'] !== null && $mailboxProblem === null) {
            $failed = !$this->waitForReply($sent['to'], $sent['messageId'], $sent['number'], $timeout, $io) || $failed;
        } elseif ($wait) {
            $io->line('✕ 返信の確認はしませんでした（受信か送信に失敗したため）');
        }

        return $failed ? 1 : 0;
    }

    /**
     * Sends the test mail. Null when there is nobody to send it to (already reported).
     *
     * @return array{failed: bool, to: string, messageId: ?string, number: string}|null
     */
    private function sendTestMail(?string $to, ConsoleIo $io): ?array
    {
        $admin = null;

        try {
            $admins = $this->container->get(AdminRepository::class);
            assert($admins instanceof AdminRepository);
            $all = $admins->all();
            $admin = $all[0] ?? null;

            if ($to !== null) {
                $admin = null;

                foreach ($all as $candidate) {
                    if (AdminRepository::normalize($candidate['email']) === AdminRepository::normalize($to)) {
                        $admin = $candidate;
                    }
                }
            }
        } catch (Throwable $exception) {
            $io->line('✕ テストメールを送る相手を調べられません：' . $exception->getMessage());

            return null;
        }

        if ($to === null && $admin === null) {
            $io->error('管理者がいません。先に bin/console admin:create を流してください。');

            return null;
        }

        $to ??= $admin['email'] ?? '';
        $name = $admin['name'] ?? $to;
        $number = bin2hex(random_bytes(4));
        $messageId = null;
        $problem = $this->attempt(function () use ($to, $name, $number, &$messageId): void {
            $settings = $this->container->get(Settings::class);
            $sender = $this->container->get(MailSender::class);
            $db = $this->container->get(\ReBuji\Database\Database::class);
            assert($settings instanceof Settings && $sender instanceof MailSender && $db instanceof \ReBuji\Database\Database);
            $mail = (new NotificationMail($settings, $this->clock()))->setupMail($to, $name, $number);
            $sender->send($mail);
            $messageId = $mail->messageId;
            (new MessageRepository($db))->record(null, 'test', $to, $mail->messageId, true, '', $this->clock()->now());
            // In Docker, cron's reply job leaves a reply that only carries this number in its subject for the wait.
            $state = $this->container->get(SystemState::class);
            assert($state instanceof SystemState);
            $state->set('check.test_number', $number);
        });
        $failed = $this->report($io, 'テストメールを ' . $to . ' に送った', $problem);

        if ($messageId !== null) {
            // The script that answers the mail reads this line, so it goes out before the wait begins.
            $io->line('message-id: ' . $messageId);
        }

        return ['failed' => $failed, 'to' => $to, 'messageId' => $messageId, 'number' => $number];
    }

    private function waitForReply(string $to, string $messageId, string $number, int $timeout, ConsoleIo $io): bool
    {
        $io->line($to . ' からの返信を待っています（最長 ' . $timeout . ' 秒）…');
        $waited = 0;

        try {
            $state = $this->container->get(SystemState::class);
            assert($state instanceof SystemState);
            $mailbox = $this->container->get(Mailbox::class);
            assert($mailbox instanceof Mailbox);
            $since = (string) $state->get('imap.since');
            $since = $since === '' ? $this->clock()->now() : Time::parse($since);
            $finder = new SetupReplyFinder($mailbox);

            while (true) {
                if ($finder->find($since, $messageId, $number)) {
                    $io->line('✓ 返信を読めた');

                    return true;
                }

                if ($waited >= $timeout) {
                    break;
                }

                $seconds = min(self::POLL_SECONDS, $timeout - $waited);
                $this->sleep($seconds);
                $waited += $seconds;
                $left = TimeLeft::line('まだ届いていません', $waited - $seconds, $waited, $timeout);

                if ($left !== null) {
                    $io->line($left);
                }
            }
        } catch (Throwable $exception) {
            $io->line('✕ 返信を読めませんでした：' . $exception->getMessage());

            return false;
        }

        $io->line('✕ 返信が見つかりませんでした');

        return false;
    }

    /**
     * Prints one result line and says whether it failed.
     */
    private function report(ConsoleIo $io, string $description, ?string $problem): bool
    {
        $io->line($problem === null ? '✓ ' . $description : '✕ ' . $description . '：' . $problem);

        return $problem !== null;
    }

    /**
     * @param Closure(): void $work
     *
     * @return ?string why it failed, null when it worked
     */
    private function attempt(Closure $work): ?string
    {
        try {
            $work();
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    private function storageProblem(): ?string
    {
        $directory = $this->paths->storage();
        $file = is_dir($directory) ? @tempnam($directory, 'check') : false;

        if ($file === false) {
            return $directory . ' に書けません';
        }

        unlink($file);

        return null;
    }

    private function sleep(int $seconds): void
    {
        if ($this->sleep !== null) {
            ($this->sleep)($seconds);

            return;
        }

        sleep($seconds);
    }

    private function clock(): ClockInterface
    {
        $clock = $this->container->get(ClockInterface::class);
        assert($clock instanceof ClockInterface);

        return $clock;
    }
}
