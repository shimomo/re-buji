<?php

declare(strict_types=1);

namespace ReBuji\Console;

use Closure;
use DateTimeImmutable;
use DI\FactoryInterface;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Auth\AdminRepository;
use ReBuji\Config\Config;
use ReBuji\Config\ConfigFile;
use ReBuji\Config\Environment;
use ReBuji\Database\Database;
use ReBuji\Install\ConsoleExposure;
use ReBuji\Install\Crontab;
use ReBuji\Install\ExposureCheck;
use ReBuji\Install\InstallContext;
use ReBuji\Install\InstallKey;
use ReBuji\Install\MailQuestions;
use ReBuji\Install\ProbeVisit;
use ReBuji\Install\PublicDir;
use ReBuji\Install\ReachedUrl;
use ReBuji\Install\RebuildingContainer;
use ReBuji\Install\SetupFile;
use ReBuji\Install\SetupReplyFinder;
use ReBuji\Install\Steps\AdminStep;
use ReBuji\Install\Steps\DatabaseStep;
use ReBuji\Install\Steps\FinishStep;
use ReBuji\Install\Steps\MailStep;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\Imap\MailboxUnavailable;
use ReBuji\Paths;
use ReBuji\Settings\Settings;
use ReBuji\SystemState;
use ReBuji\Time;
use ReBuji\Upgrade\Upgrade;
use RuntimeException;

/**
 * Run by bin/install.sh from the copy it unpacked: finishes the setup over SSH through the web installer's own steps.
 * Everything a person answers is asked before the wait for the reply to the test mail, and the copy is marked as
 * installed only once that reply is read. Run again after a stop, it goes on from where the copy is.
 *
 * @author shimomo
 */
final class InstallCommand implements Command
{
    /**
     * A question whose answer is refused is asked this many times at most: once input from a pipe runs out, every
     * answer is empty.
     */
    private const int TRIES = 3;

    private const int POLL_SECONDS = 5;

    private const int REPLY_WAIT_SECONDS = 300;

    private const int CRON_MINUTES = 5;

    private const string GAVE_UP = '3 回とも通らなかったので、止めます。同じコマンドで、もう一度流せます。';

    private const string URL_FORM = 'URL は https://example.jp/anpi のように、http:// か https:// で始めてください。';

    /**
     * DatabaseStep's inputs, by the config key a run before saved them under.
     */
    private const array SAVED_DATABASE = [
        'driver' => 'db.driver',
        'mysql_host' => 'db.mysql.host',
        'mysql_port' => 'db.mysql.port',
        'mysql_database' => 'db.mysql.database',
        'mysql_username' => 'db.mysql.username',
        'mysql_password' => 'db.mysql.password',
    ];

    /**
     * What is asked for MySQL when neither the settings file nor a run before gives it. The port is 3306 unless given.
     */
    private const array MYSQL_QUESTIONS = [
        'mysql_host' => '  ホスト：',
        'mysql_database' => '  データベース名：',
        'mysql_username' => '  ユーザー名：',
        'mysql_password' => '  パスワード：',
    ];

    /**
     * @var Closure(int): void
     */
    private readonly Closure $sleep;

    private readonly Crontab $crontab;

    /**
     * @param (Closure(int): void)|null $sleep waits that many seconds; replaced in tests so they need not wait
     * @param ?Crontab $crontab replaced in tests so they never touch a real crontab
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly Environment $environment,
        private readonly ContainerInterface $container,
        ?Closure $sleep = null,
        ?Crontab $crontab = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->crontab = $crontab ?? new Crontab();
    }

    #[Override]
    public function name(): string
    {
        return 'install';
    }

    #[Override]
    public function summary(): string
    {
        return 'install.sh が使う：SSH で設定を済ませ、設置を終える';
    }

    #[Override]
    public function run(array $args, ConsoleIo $io): int
    {
        $options = self::options($args);

        if ($options === null) {
            $io->error('使い方：console install [--public=<公開ディレクトリ>] [--url=<公開する URL>] [--config=<設定ファイル>] [--php=<cron で使う php>]');

            return 2;
        }

        if ($this->environment->enabled()) {
            $io->error('Docker では使いません。Docker では docs/docker.md の手順で設定してください。');

            return 1;
        }

        $configFile = $this->container->get(ConfigFile::class);
        assert($configFile instanceof ConfigFile);
        $config = $configFile->exists() ? $configFile->load() : new Config([]);

        if ($config->get('installed_at') !== null) {
            $io->error('設置を終えています。上げるときは sh \'' . $this->paths->root() . '/bin/upgrade.sh\' を流してください。');

            return 1;
        }

        $setup = $options['config'] === null ? null : $this->readSetupFile($options['config'], $io);

        if ($options['config'] !== null && $setup === null) {
            return 1;
        }

        $remembered = self::text($config->get(Upgrade::PUBLIC_DIR));
        $public = $options['public'] ?? ($remembered === '' ? null : $remembered);

        if ($public !== null && !$this->placePublicFiles($public, $configFile, $io)) {
            return 1;
        }

        $exposure = $this->exposure();
        $reached = $this->reachedUrl($exposure, $options['url'], $public ?? $this->paths->root(), $io);

        if ($reached === null) {
            return 1;
        }

        $context = InstallContext::forConsole($this->paths, $configFile, $this->stepContainer(), $reached->url);
        $driver = $this->setUpDatabase($context, $setup, $io);

        // Before the admin and the mail: a wrong URL or web PHP stops the run before any password is typed.
        if ($driver === null || !$this->checkExposure($exposure, $reached, $driver, $io)) {
            return 1;
        }

        if (!$this->createAdmin($context, $io) || !$this->setUpMail($context, $setup, $io)) {
            return 1;
        }

        $cron = $this->askCron($options['php'] ?? PHP_BINARY, $io);

        if ($cron === null || !$this->waitForReply($context, $io)) {
            return 1;
        }

        $finish = new FinishStep(new InstallKey($this->paths), $this->clock());
        $statusUrl = $finish->viewData($context)['statusUrl'];
        assert(is_string($statusUrl));
        $finished = $finish->submit($context, []);

        if (!$finished->ok()) {
            self::errors($io, $finished->errors);

            return 1;
        }

        $settings = $this->settings($context);
        $io->line('✓ 設置を終えました（しきい値は' . $settings->threshold()->label() . '、1 時間に送る上限は ' . $settings->get('hourly_limit') . ' 通。設定の画面で変えられます）');
        $this->writeCron($cron, $io);
        $io->line('状態の URL：' . $statusUrl);
        $io->line('  UptimeRobot などの監視サービスに登録してください。cron が止まると、この URL が 503 を返します。');
        $io->line('ログイン：' . $context->baseUrl() . '/');

        if ($setup !== null && $setup->holdsPassword()) {
            $io->line('パスワードを書いた設定ファイルは、もう要りません。消してください：' . $setup->path());
        }

        return 0;
    }

    /**
     * The arguments bin/install.sh passes, whose form never changes: an empty value is the same as none.
     *
     * @param list<string> $args
     *
     * @return array{public: ?string, url: ?string, config: ?string, php: ?string}|null null for any other argument
     */
    private static function options(array $args): ?array
    {
        $given = [];

        foreach ($args as $arg) {
            if (preg_match('/\A--(public|url|config|php)=(.*)\z/s', $arg, $match) !== 1) {
                return null;
            }

            if ($match[2] !== '') {
                $given[$match[1]] = $match[2];
            }
        }

        return ['public' => $given['public'] ?? null, 'url' => $given['url'] ?? null, 'config' => $given['config'] ?? null, 'php' => $given['php'] ?? null];
    }

    private function readSetupFile(string $path, ConsoleIo $io): ?SetupFile
    {
        try {
            $setup = SetupFile::read($path);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return null;
        }

        foreach ($setup->notices() as $notice) {
            $io->line($notice);
        }

        if ($setup->summary() !== '') {
            $io->line($setup->summary());
        }

        if ($setup->holdsPassword() && $setup->readableByOthers()) {
            $io->line('注意：設定ファイルにパスワードが書いてあり、ほかの利用者にも読めます。chmod 600 \'' . $setup->path() . '\' で、自分だけが読めるようにしてください。');
        }

        return $setup;
    }

    private function placePublicFiles(string $directory, ConfigFile $configFile, ConsoleIo $io): bool
    {
        $publicDir = new PublicDir($this->paths);
        $problem = $publicDir->problem($directory);

        if ($problem !== null) {
            $io->error($problem);

            return false;
        }

        $io->line('✓ ' . $publicDir->place($directory, $configFile) . ' に公開するファイルを置きました');

        return true;
    }

    /**
     * The URL that reaches this copy. A URL given with --url is tried once; one asked for is asked again when it does
     * not reach this copy, up to TRIES times together with the ones typed in the wrong form.
     */
    private function reachedUrl(ConsoleExposure $exposure, ?string $given, string $directory, ConsoleIo $io): ?ReachedUrl
    {
        if ($given !== null) {
            $url = self::normalizedUrl($given);

            if ($url === null) {
                $io->error(self::URL_FORM);

                return null;
            }

            $reached = $this->tryUrl($exposure, $url, $directory, $io);

            return $reached instanceof ReachedUrl ? $this->warnedAboutHttp($reached, $io) : null;
        }

        for ($try = 0; $try < self::TRIES; $try++) {
            $url = self::normalizedUrl($io->ask($directory . ' を開く URL（例：https://anpi.example.jp）：'));

            if ($url === null) {
                $io->error(self::URL_FORM);

                continue;
            }

            $reached = $this->tryUrl($exposure, $url, $directory, $io);

            if ($reached === null) {
                return null;
            }

            if ($reached !== false) {
                return $this->warnedAboutHttp($reached, $io);
            }
        }

        $io->error(self::GAVE_UP);

        return null;
    }

    /**
     * @return ReachedUrl|false|null false when the URL goes elsewhere, null when the check stopped (the reason written)
     */
    private function tryUrl(ConsoleExposure $exposure, string $url, string $directory, ConsoleIo $io): ReachedUrl|false|null
    {
        try {
            return $exposure->reach($url, $directory, $io);
        } catch (RuntimeException $exception) {
            // Waiting for the browser needs a file in storage/.
            $io->error($exception->getMessage());

            return null;
        }
    }

    private function warnedAboutHttp(ReachedUrl $reached, ConsoleIo $io): ReachedUrl
    {
        if (str_starts_with($reached->url, 'http://')) {
            $io->line('注意：http の URL です。ログインのパスワードが暗号化されずに送られます。https が使えるなら、https の URL にしてください。');
        }

        return $reached;
    }

    /**
     * $given in the one form used from here on: scheme and host in lower case, the port as given, and no / at the end
     * of the path. Null unless it is an http or https URL with a host and no user name, query or fragment.
     */
    private static function normalizedUrl(string $given): ?string
    {
        $parts = parse_url(trim($given));

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (($scheme !== 'http' && $scheme !== 'https') || $parts['host'] === '') {
            return null;
        }

        return $scheme . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim($parts['path'] ?? '', '/');
    }

    /**
     * The steps' container: one that builds the mail services anew each time when it can.
     */
    private function stepContainer(): ContainerInterface
    {
        return $this->container instanceof FactoryInterface ? new RebuildingContainer($this->container) : $this->container;
    }

    /**
     * Connects to the database and creates the tables. The settings file's values come before what a run before saved;
     * MySQL only when one of them says so.
     *
     * @return ?string the driver, or null when it stopped (the reason written)
     */
    private function setUpDatabase(InstallContext $context, ?SetupFile $setup, ConsoleIo $io): ?string
    {
        $input = ($setup?->database() ?? []) + self::savedDatabase($context->config());
        $driver = ($input['driver'] ?? '') === 'mysql' ? 'mysql' : 'sqlite';
        $input = $driver === 'mysql' ? $input : ['driver' => 'sqlite'];
        $missing = $driver === 'mysql' ? array_diff_key(self::MYSQL_QUESTIONS, $input) : [];
        $step = new DatabaseStep($this->clock());

        for ($try = 0; $try < self::TRIES; $try++) {
            $answers = [];

            if ($missing !== []) {
                $io->line('データベース（MySQL）');

                foreach ($missing as $field => $question) {
                    $answers[$field] = $field === 'mysql_password' ? $io->secret($question) : $io->ask($question);
                }
            }

            $result = $step->submit($context, $answers + $input);

            if ($result->ok()) {
                $io->line($driver === 'mysql' ? '✓ データベース（MySQL）につなぎ、テーブルを作りました' : '✓ データベース（SQLite）を作りました');

                return $driver;
            }

            self::errors($io, $result->errors);

            if ($missing === []) {
                $io->error('データベースにつなげません。正しい値を --config の設定ファイル（REBUJI_DB_*）に書いて、もう一度流してください。');

                return null;
            }
        }

        $io->error(self::GAVE_UP);

        return null;
    }

    /**
     * @return array<string, string> the database inputs a run before saved, by DatabaseStep's names
     */
    private static function savedDatabase(Config $config): array
    {
        $saved = [];

        foreach (self::SAVED_DATABASE as $input => $key) {
            $value = self::text($config->get($key));

            if ($value !== '') {
                $saved[$input] = $value;
            }
        }

        return $saved;
    }

    private function exposure(): ConsoleExposure
    {
        $check = $this->container->get(ExposureCheck::class);
        assert($check instanceof ExposureCheck);

        return new ConsoleExposure($check, new ProbeVisit($this->paths), $this->sleep);
    }

    private function checkExposure(ConsoleExposure $exposure, ReachedUrl $reached, string $driver, ConsoleIo $io): bool
    {
        try {
            return $exposure->finish($reached, $driver, $io);
        } catch (RuntimeException $exception) {
            // Waiting for the browser needs a file in storage/.
            $io->error($exception->getMessage());

            return false;
        }
    }

    private function createAdmin(InstallContext $context, ConsoleIo $io): bool
    {
        $first = (new AdminRepository($this->database($context), $this->clock()))->all()[0] ?? null;

        if ($first !== null) {
            $io->line('最初の管理者：' . $first['name'] . '（' . $first['email'] . '）はもういます');

            return true;
        }

        $step = new AdminStep($this->clock());

        for ($try = 0; $try < self::TRIES; $try++) {
            $io->line('最初の管理者');
            $input = [
                'name' => $io->ask('  名前：'),
                'email' => $io->ask('  メールアドレス：'),
                'password' => $io->secret('  パスワード（12 文字以上）：'),
            ];
            // A pipe gives one line only: there is nobody to type it twice.
            $input['password_confirmation'] = $io->interactive() ? $io->secret('  もう一度：') : $input['password'];
            // The step checks with AdminStep::validate() before it creates anyone.
            $result = $step->submit($context, $input);

            if ($result->ok()) {
                return true;
            }

            self::errors($io, $result->errors);
        }

        $io->error(self::GAVE_UP);

        return false;
    }

    /**
     * Saves the mail settings, logs in to the mailbox and sends the test mail, asking what the settings file leaves out.
     */
    private function setUpMail(InstallContext $context, ?SetupFile $setup, ConsoleIo $io): bool
    {
        $file = $setup?->mail() ?? [];
        $step = new MailStep($this->clock());
        $previous = [];

        for ($try = 0; $try < self::TRIES; $try++) {
            $answers = MailQuestions::ask($this->settings($context), $file, $previous, $io);
            $result = $step->submit($context, $answers + $file);

            if ($result->ok()) {
                $io->line('✓ 返信用のメールボックスにログインできました');
                $io->line('✓ テストメールを ' . self::draft($context, 'mail.test_to') . ' に送りました');

                return true;
            }

            self::errors($io, $result->errors);

            if ($answers === []) {
                $io->error('設定ファイルの値を直して、もう一度流してください。');

                return false;
            }

            $previous = $answers;
        }

        $io->error(self::GAVE_UP);

        return false;
    }

    /**
     * Asks how often cron runs and whether to add its line once installed; nothing is written yet.
     *
     * @return array{line: string, asked: bool, add: bool}|null null when no interval was accepted
     */
    private function askCron(string $php, ConsoleIo $io): ?array
    {
        if ($this->crontab->problem() !== null) {
            return ['line' => Crontab::line(self::CRON_MINUTES, $php, $this->cronPath()), 'asked' => false, 'add' => false];
        }

        for ($try = 0; $try < self::TRIES; $try++) {
            $answer = trim($io->ask('cron を何分ごとに動かしますか？ 1 分ごとにできるサーバーなら 1 [5]：'));
            $minutes = $answer === '' ? self::CRON_MINUTES : (ctype_digit($answer) ? (int) $answer : 0);

            // Below the 15 minutes after which the status URL answers 503.
            if ($minutes >= 1 && $minutes <= 10) {
                $line = Crontab::line($minutes, $php, $this->cronPath());
                $io->line('cron に足す行：');
                $io->line('  ' . $line);
                $add = in_array(strtolower(trim($io->ask('設置を終えたら、この行を cron に足しますか？ [y/N] '))), ['y', 'yes'], true);

                return ['line' => $line, 'asked' => true, 'add' => $add];
            }

            $io->error('1〜10 の数を入れてください。');
        }

        $io->error(self::GAVE_UP);

        return null;
    }

    /**
     * Waits for the reply to the test mail, five minutes at a time for as long as the owner wants. The last question.
     */
    private function waitForReply(InstallContext $context, ConsoleIo $io): bool
    {
        $since = (new SystemState($this->database($context), $this->clock()))->get('imap.since');
        $since = $since === null || $since === '' ? $this->clock()->now() : Time::parse($since);
        $messageId = self::draft($context, 'mail.test_message_id');
        $number = self::draft($context, 'mail.test_number');

        while (true) {
            $io->line('テストメールに返信してください。返信を待っています（5 分まで）…');
            // A mailbox built anew for each round (RebuildingContainer): once its connection drops, one never reconnects.
            $mailbox = $context->container->get(Mailbox::class);
            assert($mailbox instanceof Mailbox);

            if ($this->replyArrives(new SetupReplyFinder($mailbox), $since, $messageId, $number, $io)) {
                $io->line('✓ 返信を読めました');

                return true;
            }

            $answer = strtolower(trim($io->ask('もう少し待ちますか？ [Y/n] ')));

            // With the input ended, nobody is left to answer, and waiting again would never stop.
            if ($io->ended() || in_array($answer, ['n', 'no'], true)) {
                $io->error('返信を読めていないので、設置を終えていません。同じコマンドで、続きから流せます。');

                return false;
            }
        }
    }

    /**
     * Looks for the reply every few seconds for five minutes. Only the time slept counts, as in bin/console check.
     */
    private function replyArrives(SetupReplyFinder $finder, DateTimeImmutable $since, string $messageId, string $number, ConsoleIo $io): bool
    {
        $waited = 0;

        while (true) {
            try {
                if ($finder->find($since, $messageId, $number)) {
                    return true;
                }
            } catch (MailboxUnavailable $exception) {
                $io->line('✕ 返信を読めませんでした：' . $exception->getMessage());

                return false;
            }

            if ($waited >= self::REPLY_WAIT_SECONDS) {
                return false;
            }

            ($this->sleep)(self::POLL_SECONDS);
            $waited += self::POLL_SECONDS;
            $left = TimeLeft::line('まだ届いていません', $waited - self::POLL_SECONDS, $waited, self::REPLY_WAIT_SECONDS);

            if ($left !== null) {
                $io->line($left);
            }
        }
    }

    /**
     * @param array{line: string, asked: bool, add: bool} $cron
     */
    private function writeCron(array $cron, ConsoleIo $io): void
    {
        if ($cron['add']) {
            try {
                if ($this->crontab->add($cron['line'], $this->cronPath())) {
                    $io->line('✓ cron に足しました：' . $cron['line']);
                    $io->line('  サーバーの管理画面で cron を編集すると、この行が消えることがあります。消えても、状態の URL が 15 分で 503 を返すので気づけます。');
                } else {
                    $io->line('✓ cron には、もう ' . $this->cronPath() . ' の行があります。足していません。');
                }

                return;
            } catch (RuntimeException $exception) {
                $io->line('✕ cron に足せませんでした：' . $exception->getMessage());
            }
        }

        $io->line('cron に次の行を登録してください（サーバーの管理画面か crontab -e で）：');
        $io->line('  ' . $cron['line']);

        if (!$cron['asked']) {
            $io->line('  1 分ごとにできるサーバーなら、1 分ごとにしてください。');
        }
    }

    private function cronPath(): string
    {
        return $this->paths->root() . '/bin/cron.php';
    }

    /**
     * The database the steps have set up, opened anew as the steps do: the container's would keep the config it was
     * built with.
     */
    private function database(InstallContext $context): Database
    {
        return Database::fromConfig($context->config(), $this->paths);
    }

    private function settings(InstallContext $context): Settings
    {
        return new Settings($this->database($context), $this->clock());
    }

    private function clock(): ClockInterface
    {
        $clock = $this->container->get(ClockInterface::class);
        assert($clock instanceof ClockInterface);

        return $clock;
    }

    private static function draft(InstallContext $context, string $key): string
    {
        return self::text($context->draft()[$key] ?? null);
    }

    /**
     * A saved text or number as text, '' for anything else (nothing saved).
     */
    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    /**
     * @param list<string> $errors
     */
    private static function errors(ConsoleIo $io, array $errors): void
    {
        foreach ($errors as $error) {
            $io->error($error);
        }
    }
}
