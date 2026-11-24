<?php

declare(strict_types=1);

namespace ReBuji\Tests\Console;

use Closure;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use ReBuji\Auth\AdminRepository;
use ReBuji\Config\Config;
use ReBuji\Config\ConfigFile;
use ReBuji\Config\Environment;
use ReBuji\Console\ConsoleIo;
use ReBuji\Console\InstallCommand;
use ReBuji\Container;
use ReBuji\Database\Database;
use ReBuji\Install\Crontab;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\Imap\MailboxUnavailable;
use ReBuji\Mail\Imap\RawMail;
use ReBuji\Mail\MailSender;
use ReBuji\Paths;
use ReBuji\Settings\Settings;
use ReBuji\Tests\Support\AnsweringMailbox;
use ReBuji\Tests\Support\FakeHttpClient;
use ReBuji\Tests\Support\FakeMailbox;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\LoopbackHttpClient;
use ReBuji\Tests\Support\RecordingMailSender;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;

/**
 * bin/console install from start to finish, with mail in memory, the web side answered by the app itself (whose base
 * path is empty, hence https://example.jp) and crontab answered without running it.
 *
 * @author shimomo
 */
final class InstallCommandTest extends TestCase
{
    private const string PHP = '/usr/local/php/8.3/bin/php';

    /**
     * The first admin's name, address and password.
     */
    private const string ADMIN = "管理 太郎\nadmin@example.jp\nlong-enough-pass\n";

    /**
     * The sender's address, the SMTP server, the defaults for port, encryption and user name, the password, and the
     * defaults for the IMAP server, port, encryption and user name.
     */
    private const string MAIL = "anpi@example.jp\nsmtp.example.jp\n\n\n\nsmtp-pass\n\n\n\n\n";

    /**
     * Every 5 minutes (the default), and yes, add the line.
     */
    private const string CRON = "\ny\n";

    private const string ANSWERS = self::ADMIN . self::MAIL . self::CRON;

    #[Test]
    public function installsFromAnswersAlone(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            [$code, $io, $calls] = $this->install($root, $this->args($dir), self::ANSWERS, $sender, new AnsweringMailbox($sender));

            $this->assertSame(0, $code, $io->errors());
            $config = $this->config($root);
            $token = $config->get('status.token');
            $this->assertIsString($config->get('installed_at'));
            $this->assertIsString($token);
            $this->assertSame(realpath($dir . '/www'), $config->get('upgrade.public_dir'));
            $this->assertSame(1, $this->admins($root));
            $settings = $this->settings($root);
            $this->assertSame('smtp.example.jp', $settings->get('smtp.host'));
            $this->assertSame('smtp.example.jp', $settings->get('imap.host'));
            $this->assertSame('smtp-pass', $settings->get('smtp.password'));
            $this->assertFileExists($dir . '/www/index.php');
            $this->assertFileExists($dir . '/www/rebuji-root.php');
            $this->assertFileDoesNotExist($root . '/storage/install-key.txt');
            $this->assertSame(['*/5 * * * * ' . self::PHP . ' ' . $root . "/bin/cron.php\n"], $this->written($calls));
            $this->assertStringContainsString('✓ 返信を読めました', $io->output());
            $this->assertStringContainsString('✓ 設置を終えました（しきい値は震度5弱、1 時間に送る上限は 100 通。設定の画面で変えられます）', $io->output());
            $this->assertStringContainsString('✓ cron に足しました', $io->output());
            $this->assertStringContainsString('見張りの URL：https://example.jp/status/' . $token, $io->output());
            $this->assertStringContainsString('ログイン：https://example.jp/', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function asksOnlyWhatTheSetupFileLeavesOut(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            $file = $this->setupFile($dir, "REBUJI_DOMAIN=anpi.example.jp\nREBUJI_SMTP_HOST=smtp.example.jp\nREBUJI_SMTP_PORT=465\nREBUJI_SMTP_ENCRYPTION=ssl\n"
                . "REBUJI_SMTP_USERNAME=anpi@example.jp\nREBUJI_MAIL_FROM_ADDRESS=anpi@example.jp\nREBUJI_IMAP_HOST=imap.example.jp\n"
                . "REBUJI_IMAP_PORT=993\nREBUJI_IMAP_ENCRYPTION=ssl\n");
            $sender = new RecordingMailSender();
            // The admin, the SMTP password, the default IMAP user name (the sender's), and cron.
            $input = self::ADMIN . "smtp-pass\n\n" . self::CRON;
            [$code, $io] = $this->install($root, [...$this->args($dir), '--config=' . $file], $input, $sender, new AnsweringMailbox($sender));

            $this->assertSame(0, $code, $io->errors());
            $this->assertStringContainsString('設定ファイルの REBUJI_DOMAIN は Docker だけで使う設定なので、使いません。', $io->output());
            $this->assertStringContainsString('設定ファイルから使います：REBUJI_SMTP_HOST=smtp.example.jp', $io->output());
            $this->assertStringNotContainsString('  サーバー名', $io->output());
            $this->assertStringNotContainsString('  ポート', $io->output());
            $this->assertStringNotContainsString('  暗号化', $io->output());
            $this->assertStringNotContainsString('  送信元のアドレス', $io->output());
            $this->assertStringContainsString('  パスワード：', $io->output());
            $this->assertStringContainsString('  ユーザー名 [送信と同じ]：', $io->output());
            $settings = $this->settings($root);
            $this->assertSame('465', $settings->get('smtp.port'));
            $this->assertSame('imap.example.jp', $settings->get('imap.host'));
            $this->assertStringNotContainsString('消してください', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function asksEveryQuestionBeforeWaitingForTheReply(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            [$code, $io] = $this->install($root, $this->args($dir), self::ANSWERS, $sender, new AnsweringMailbox($sender));

            $this->assertSame(0, $code, $io->errors());
            $output = $io->output();
            $waiting = strpos($output, '返信を待っています');
            $interval = strpos($output, 'cron を何分ごとに');
            $adding = strpos($output, '設置を終えたら、この行を');
            $read = strpos($output, '✓ 返信を読めました');
            $this->assertIsInt($waiting);
            $this->assertIsInt($interval);
            $this->assertIsInt($adding);
            $this->assertIsInt($read);
            $this->assertLessThan($waiting, $interval);
            $this->assertLessThan($waiting, $adding);
            $afterTheReply = substr($output, $read);
            $this->assertStringNotContainsString('[y/N]', $afterTheReply);
            $this->assertStringNotContainsString('[Y/n]', $afterTheReply);
            $this->assertStringNotContainsString('[5]', $afterTheReply);
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function finishesOnlyAfterTheReplyIsRead(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            [$code, $io, $calls, $slept] = $this->install($root, $this->args($dir), self::ANSWERS . "n\n", new RecordingMailSender(), new FakeMailbox());

            $this->assertSame(1, $code);
            $this->assertStringContainsString('返信を読めていないので、設置を終えていません。同じコマンドで、続きから流せます。', $io->errors());
            $this->assertNull($this->config($root)->get('installed_at'));
            $this->assertSame([], $this->written($calls));
            $this->assertSame(300, $slept);
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function waitsAnotherFiveMinutesWhenAsked(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            $mailbox = new AnsweringMailbox($sender);
            $mailbox->answering = false;
            $answerLater = static function (int $slept) use ($mailbox): void {
                if ($slept > 300) {
                    $mailbox->answering = true;
                }
            };
            // Enter at "もう少し待ちますか？" waits again.
            [$code, $io] = $this->install($root, $this->args($dir), self::ANSWERS . "\n", $sender, $mailbox, onSleep: $answerLater);

            $this->assertSame(0, $code, $io->errors());
            $this->assertSame(1, substr_count($io->output(), 'もう少し待ちますか？'));
            $this->assertSame(2, substr_count($io->output(), 'テストメールに返信してください。返信を待っています（5 分まで）…'));
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function looksWithANewMailboxAfterTheConnectionDrops(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            $answering = new AnsweringMailbox($sender);
            $made = 0;
            // Made for the mail step, for the first round of the wait (whose connection drops), and for the second.
            $mailbox = static function () use (&$made, $answering): Mailbox {
                if (++$made !== 2) {
                    return $answering;
                }

                $dropped = new FakeMailbox();
                $dropped->unavailable = true;

                return $dropped;
            };
            // Enter at "もう少し待ちますか？" waits again.
            [$code, $io] = $this->install($root, $this->args($dir), self::ANSWERS . "\n", $sender, $mailbox);

            $this->assertSame(0, $code, $io->errors());
            $this->assertSame(1, substr_count($io->output(), '✕ 返信を読めませんでした'));
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function stopsWaitingWhenTheInputHasEnded(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            // Nothing is left to answer "もう少し待ちますか？", so waiting again would never stop.
            $endless = function (int $slept): void {
                if ($slept > 300) {
                    $this->fail('Kept waiting after the input had ended.');
                }
            };
            [$code, $io, , $slept] = $this->install($root, $this->args($dir), self::ANSWERS, new RecordingMailSender(), new FakeMailbox(), onSleep: $endless);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('返信を読めていないので、設置を終えていません。同じコマンドで、続きから流せます。', $io->errors());
            $this->assertSame(300, $slept);
            $this->assertNull($this->config($root)->get('installed_at'));
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function reportsAMailboxThatFailsDuringTheWait(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            // The mail step's log-in works; every look for the reply after it fails.
            $mailbox = new class () implements Mailbox {
                private int $asked = 0;

                #[Override]
                public function unseen(DateTimeImmutable $since, int $limit): iterable
                {
                    if ($this->asked++ > 0) {
                        throw new MailboxUnavailable('Connection reset');
                    }

                    return [];
                }

                #[Override]
                public function answersTo(DateTimeImmutable $since, string $messageId, string $token, int $limit): iterable
                {
                    if ($this->asked++ > 0) {
                        throw new MailboxUnavailable('Connection reset');
                    }

                    return [];
                }

                #[Override]
                public function markSeen(RawMail $mail): void
                {
                    //
                }
            };
            [$code, $io] = $this->install($root, $this->args($dir), self::ANSWERS . "n\n", new RecordingMailSender(), $mailbox);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('✕ 返信を読めませんでした：Connection reset', $io->output());
            $this->assertStringContainsString('もう少し待ちますか？', $io->output());
            $this->assertNull($this->config($root)->get('installed_at'));
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function continuesFromWhereItStoppedWithoutASecondAdmin(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            [$code] = $this->install($root, $this->args($dir), self::ANSWERS . "n\n", new RecordingMailSender(), new FakeMailbox());
            $this->assertSame(1, $code);

            $sender = new RecordingMailSender();
            // Without --public; every mail question and the interval by Enter (the password kept), then cron.
            $args = ['--url=https://example.jp', '--php=' . self::PHP];
            [$code, $io] = $this->install($root, $args, str_repeat("\n", 11) . "y\n", $sender, new AnsweringMailbox($sender));

            $this->assertSame(0, $code, $io->errors());
            $public = realpath($dir . '/www');
            $this->assertIsString($public);
            $this->assertStringContainsString('最初の管理者：管理 太郎（admin@example.jp）はもういます', $io->output());
            $this->assertStringContainsString('✓ ' . $public . ' に公開するファイルを置きました', $io->output());
            $this->assertStringContainsString('  パスワード [前のまま]：', $io->output());
            $this->assertSame(1, $this->admins($root));
            $this->assertIsString($this->config($root)->get('installed_at'));
            $this->assertSame('smtp-pass', $this->settings($root)->get('smtp.password'));
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function asksForTheMailAgainAfterAFailure(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            $answering = new AnsweringMailbox($sender);
            $made = 0;
            // The first mailbox is made with the first answers and cannot log in; the ones made after it can.
            $mailbox = static function () use (&$made, $answering): Mailbox {
                if ($made++ > 0) {
                    return $answering;
                }

                $failing = new FakeMailbox();
                $failing->unavailable = true;

                return $failing;
            };
            // Asked again, Enter alone keeps each answer before (the password too).
            $input = self::ADMIN . self::MAIL . str_repeat("\n", 10) . self::CRON;
            [$code, $io] = $this->install($root, $this->args($dir), $input, $sender, $mailbox);

            $this->assertSame(0, $code, $io->errors());
            $this->assertStringContainsString('メールを受信できませんでした：', $io->errors());
            $this->assertSame(1, substr_count($io->output(), '✓ 返信用のメールボックスにログインできました'));
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function stopsWhenTheSetupFileHoldsEveryMailValueAndTheyFail(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            $file = $this->setupFile($dir, "REBUJI_SMTP_HOST=smtp.example.jp\nREBUJI_SMTP_PORT=587\nREBUJI_SMTP_ENCRYPTION=starttls\n"
                . "REBUJI_SMTP_USERNAME=anpi@example.jp\nREBUJI_SMTP_PASSWORD=smtp-pass\nREBUJI_MAIL_FROM_ADDRESS=anpi@example.jp\n"
                . "REBUJI_MAIL_FROM_NAME=安否確認\nREBUJI_IMAP_HOST=imap.example.jp\nREBUJI_IMAP_PORT=993\nREBUJI_IMAP_ENCRYPTION=ssl\n"
                . "REBUJI_IMAP_USERNAME=anpi@example.jp\nREBUJI_IMAP_PASSWORD=imap-pass\n");
            $mailbox = new FakeMailbox();
            $mailbox->unavailable = true;
            [$code, $io] = $this->install($root, [...$this->args($dir), '--config=' . $file], self::ADMIN, new RecordingMailSender(), $mailbox);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('設定ファイルの値を直して、もう一度流してください。', $io->errors());
            $this->assertNull($this->config($root)->get('installed_at'));
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function stopsBeforeAnyQuestionWhenTheUrlDoesNotReachThisCopy(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            $overrides = [ClientInterface::class => new FakeHttpClient(404, [], false)];
            [$code, $io] = $this->install($root, $this->args($dir), self::ANSWERS, new RecordingMailSender(), new FakeMailbox(), $overrides);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('（状態 404）', $io->errors());
            $this->assertStringNotContainsString('最初の管理者', $io->output());
            $this->assertNull($this->config($root)->get('installed_at'));
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function refusesAPublicDirectoryWithOtherFiles(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            mkdir($dir . '/www');
            file_put_contents($dir . '/www/index.html', 'the host placed this');
            [$code, $io] = $this->install($root, $this->args($dir), self::ANSWERS, new RecordingMailSender(), new FakeMailbox());

            $this->assertSame(1, $code);
            $this->assertStringContainsString('にほかのファイルがあります（index.html）', $io->errors());
            $this->assertFileDoesNotExist($root . '/storage/config.php');
            $this->assertSame('the host placed this', file_get_contents($dir . '/www/index.html'));
            $this->assertFileDoesNotExist($dir . '/www/rebuji-root.php');
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function refusesAnInstalledCopyDockerAndUnknownArguments(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            (new ConfigFile($root . '/storage/config.php'))->save(new Config(['installed_at' => '2026-11-05T03:00:00Z']));
            [$code, $io] = $this->install($root, $this->args($dir), self::ANSWERS, new RecordingMailSender(), new FakeMailbox());

            $this->assertSame(1, $code);
            $this->assertStringContainsString('sh ' . $root . '/bin/upgrade.sh', $io->errors());

            $docker = new Environment(['REBUJI_ENV_CONFIG' => '1']);
            [$code, $io] = $this->install($root, $this->args($dir), self::ANSWERS, new RecordingMailSender(), new FakeMailbox(), env: $docker);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('Docker では使いません', $io->errors());

            [$code, $io] = $this->install($root, ['--yes'], '', new RecordingMailSender(), new FakeMailbox());

            $this->assertSame(2, $code);
            $this->assertStringContainsString('使い方：console install', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function tellsHowToRegisterCronWithoutCrontab(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            [$code, $io] = $this->install($root, $this->args($dir), self::ADMIN . self::MAIL, $sender, new AnsweringMailbox($sender), listed: [127, '', '']);

            $this->assertSame(0, $code, $io->errors());
            $this->assertStringNotContainsString('cron を何分ごとに', $io->output());
            $this->assertStringContainsString('cron に次の行を登録してください（サーバーの管理画面か crontab -e で）：', $io->output());
            $this->assertStringContainsString('  */5 * * * * ' . self::PHP . ' ' . $root . '/bin/cron.php', $io->output());
            $this->assertStringContainsString('  1 分ごとにできるサーバーなら、1 分ごとにしてください。', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function leavesCrontabAloneWhenAnsweredNo(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            [$code, $io, $calls] = $this->install($root, $this->args($dir), self::ADMIN . self::MAIL . "1\nn\n", $sender, new AnsweringMailbox($sender));

            $this->assertSame(0, $code, $io->errors());
            $this->assertSame([], $this->written($calls));
            $this->assertStringContainsString('  * * * * * ' . self::PHP . ' ', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function stillFinishesWhenCrontabCannotBeWritten(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            [$code, $io] = $this->install($root, $this->args($dir), self::ANSWERS, $sender, new AnsweringMailbox($sender), written: [1, '', 'errors in crontab file']);

            $this->assertSame(0, $code, $io->errors());
            $this->assertIsString($this->config($root)->get('installed_at'));
            $this->assertStringContainsString('✕ cron に足せませんでした：errors in crontab file', $io->output());
            $this->assertStringContainsString('cron に次の行を登録してください', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function asksForTheUrlAndUsesItInOneForm(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            $args = ['--public=' . $dir . '/www', '--php=' . self::PHP];
            // No scheme, then a query, then one to use: scheme and host in lower case, the port kept, no / at the end.
            $input = "example.jp\nhttps://example.jp/?from=mail\nHTTP://Example.JP:8080/anpi/\n" . self::ANSWERS;
            // The check that follows names the URL it used.
            $overrides = [ClientInterface::class => new FakeHttpClient(404, [], false)];
            [$code, $io] = $this->install($root, $args, $input, new RecordingMailSender(), new FakeMailbox(), $overrides);

            $this->assertSame(1, $code);
            $this->assertSame(2, substr_count($io->errors(), 'URL は https://example.jp/anpi のように、http:// か https:// で始めてください。'));
            $this->assertStringContainsString('注意：http の URL です。', $io->output());
            $this->assertStringContainsString('サーバーから http://example.jp:8080/anpi を開きましたが', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function stopsAfterThreeRefusedAnswers(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            $sender = new RecordingMailSender();
            [$code, $io] = $this->install($root, $this->args($dir), self::ADMIN . self::MAIL . "0\n11\nfive\n", $sender, new AnsweringMailbox($sender));

            $this->assertSame(1, $code);
            $this->assertSame(3, substr_count($io->errors(), '1〜10 の数を入れてください。'));
            $this->assertStringContainsString('3 回とも通らなかったので、止めます。同じコマンドで、もう一度流せます。', $io->errors());
            $this->assertStringNotContainsString('返信を待っています', $io->output());
            $this->assertNull($this->config($root)->get('installed_at'));
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function hidesTheMysqlPasswordWhenItCannotConnect(): void
    {
        $root = $this->root();
        $dir = TemporaryDirectory::create();

        try {
            $file = $this->setupFile($dir, "REBUJI_DB_DRIVER=mysql\nREBUJI_DB_HOST=127.0.0.1\nREBUJI_DB_PORT=1\nREBUJI_DB_NAME=anpi\n"
                . "REBUJI_DB_USERNAME=anpi\nREBUJI_DB_PASSWORD=db-secret-pw\n");
            [$code, $io] = $this->install($root, [...$this->args($dir), '--config=' . $file], self::ANSWERS, new RecordingMailSender(), new FakeMailbox());

            // A PHP without pdo_mysql stops with the same words.
            $this->assertSame(1, $code);
            $this->assertStringContainsString('データベースに接続できません', $io->errors());
            $this->assertStringContainsString('正しい値を --config の設定ファイル（REBUJI_DB_*）に書いて', $io->errors());
            $this->assertStringNotContainsString('db-secret-pw', $io->output());
            $this->assertStringNotContainsString('db-secret-pw', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($dir);
        }
    }

    /**
     * Runs bin/console install on $root.
     *
     * @param list<string> $args
     * @param Mailbox|(Closure(): Mailbox) $mailbox the mailbox, or how to make one each time it is built
     * @param array<string, mixed> $overrides more container entries
     * @param array{int, string, string} $listed what crontab -l answers
     * @param array{int, string, string} $written what crontab - answers
     * @param (Closure(int): void)|null $onSleep told the seconds slept so far, after each sleep
     *
     * @return array{int, ConsoleIo, list<array{list<string>, string}>, int} the status, the streams, the crontab commands run with their input, and the seconds slept
     */
    private function install(
        string $root,
        array $args,
        string $input,
        RecordingMailSender $sender,
        Mailbox|Closure $mailbox,
        array $overrides = [],
        array $listed = [1, '', 'no crontab for you'],
        array $written = [0, '', ''],
        ?Closure $onSleep = null,
        Environment $env = new Environment([]),
    ): array {
        $paths = new Paths($root);
        $container = Container::build($paths, $overrides + [
            Environment::class => $env,
            ClockInterface::class => new FrozenClock(),
            ClientInterface::class => new LoopbackHttpClient($root),
            MailSender::class => $sender,
            Mailbox::class => $mailbox,
        ]);
        /** @var list<array{list<string>, string}> $calls */
        $calls = [];
        $slept = 0;
        $crontab = new Crontab(static function (array $command, string $stdin) use (&$calls, $listed, $written): array {
            $calls[] = [$command, $stdin];

            return $command === ['crontab', '-l'] ? $listed : $written;
        });
        $sleep = static function (int $seconds) use (&$slept, $onSleep): void {
            $slept += $seconds;

            if ($onSleep !== null) {
                $onSleep($slept);
            }
        };
        $io = ConsoleIo::memory($input);
        $code = (new InstallCommand($paths, $env, $container, $sleep, $crontab))->run($args, $io);

        return [$code, $io, $calls, $slept];
    }

    /**
     * A root with the public files to place.
     */
    private function root(): string
    {
        $root = TestApp::root();
        mkdir($root . '/public/assets', 0755, true);
        file_put_contents($root . '/public/index.php', '<?php // index');
        file_put_contents($root . '/public/.htaccess', 'RewriteEngine On');
        file_put_contents($root . '/public/assets/app.css', 'body {}');

        return $root;
    }

    /**
     * @return list<string>
     */
    private function args(string $dir): array
    {
        return ['--public=' . $dir . '/www', '--url=https://example.jp', '--php=' . self::PHP];
    }

    private function setupFile(string $dir, string $contents): string
    {
        $path = $dir . '/rebuji.env';
        file_put_contents($path, $contents);
        chmod($path, 0600);

        return $path;
    }

    private function config(string $root): Config
    {
        return (new ConfigFile($root . '/storage/config.php'))->load();
    }

    private function settings(string $root): Settings
    {
        return new Settings(Database::fromConfig($this->config($root), new Paths($root)), new FrozenClock());
    }

    private function admins(string $root): int
    {
        return (new AdminRepository(Database::fromConfig($this->config($root), new Paths($root)), new FrozenClock()))->count();
    }

    /**
     * @param list<array{list<string>, string}> $calls
     *
     * @return list<string> what was given to crontab - each time
     */
    private function written(array $calls): array
    {
        $written = [];

        foreach ($calls as [$command, $stdin]) {
            if ($command === ['crontab', '-']) {
                $written[] = $stdin;
            }
        }

        return $written;
    }
}
