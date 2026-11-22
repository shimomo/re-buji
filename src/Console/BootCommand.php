<?php

declare(strict_types=1);

namespace ReBuji\Console;

use Closure;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Config\Config;
use ReBuji\Config\ConfigFile;
use ReBuji\Config\Environment;
use ReBuji\Database\Database;
use ReBuji\Database\Migrator;
use ReBuji\Monitor\StatusToken;
use ReBuji\Paths;
use ReBuji\Settings\Settings;
use ReBuji\SystemState;
use ReBuji\Time;
use Throwable;

/**
 * What a Docker container does at every start: checks the environment, makes the directories, applies the database
 * changes and records the first start. Safe to run again.
 *
 * @author shimomo
 */
final class BootCommand implements Command
{
    /**
     * Seconds between tries to reach the database. With DATABASE_ATTEMPTS, about a minute in all: a database started
     * next to the app may take that long to accept connections.
     */
    private const int DATABASE_PAUSE = 2;

    private const int DATABASE_ATTEMPTS = 30;

    /**
     * @param (Closure(int): void)|null $sleep waits that many seconds; replaced in tests so they need not wait
     * @param int $databaseAttempts how many times to try the database before giving up
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly Environment $environment,
        private readonly ContainerInterface $container,
        private readonly ?Closure $sleep = null,
        private readonly int $databaseAttempts = self::DATABASE_ATTEMPTS,
    ) {
        //
    }

    #[Override]
    public function name(): string
    {
        return 'boot';
    }

    #[Override]
    public function summary(): string
    {
        return 'コンテナの起動時の準備（ディレクトリ・DB の更新・初回の記録）';
    }

    #[Override]
    public function run(array $args, ConsoleIo $io): int
    {
        if (!$this->environment->enabled()) {
            $io->error('この命令は Docker の設置でだけ使います。');

            return 1;
        }

        $problems = $this->databaseProblems();

        if ($problems !== []) {
            return $this->fail($problems, $io);
        }

        // Before the container is asked for anything: the database lives under storage/, and the config file (empty at
        // first, the database coming from the environment) is what the container reads.
        foreach (['', 'logs', 'sessions'] as $directory) {
            $path = $this->paths->storage($directory);

            if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
                $io->error('ディレクトリを作れません：' . $path);

                return 1;
            }
        }

        $configFile = $this->service(ConfigFile::class);

        if (!$configFile->exists()) {
            $configFile->save(new Config([]));
        }

        $unreachable = $this->waitForDatabase();

        if ($unreachable !== null) {
            return $this->fail(['データベースにつながりません：' . $unreachable], $io);
        }

        $settings = $this->service(Settings::class);
        $problems = $settings->environmentErrors();

        if ($problems !== []) {
            return $this->fail($problems, $io);
        }

        try {
            foreach ($this->service(Migrator::class)->migrate() as $version) {
                $io->line('データベースを更新しました：' . $version);
            }
        } catch (Throwable $exception) {
            return $this->fail(['データベースを更新できません：' . $exception->getMessage()], $io);
        }

        $clock = $this->service(ClockInterface::class);
        $config = $configFile->load();

        if ($config->get('installed_at') === null) {
            $configFile->save(new Config(['installed_at' => Time::format($clock->now()), 'status.token' => StatusToken::generate()] + $config->all()));
        }

        $imap = $settings->imap();
        $state = $this->service(SystemState::class);
        // Empty until a mailbox is given. The settings page keeps it in step when the mailbox is changed there.
        $account = $imap?->account() ?? '';

        if ($state->get('imap.account') !== $account) {
            // Replies are read from the time the mailbox became this one: mail from before is not ours.
            if ($account !== '') {
                $state->set('imap.since', Time::format($clock->now()));
            }

            $state->set('imap.account', $account);
        }

        if ($settings->smtp() === null || $imap === null) {
            $io->line('メールの設定がありません。ホーム画面にも出ます。');
        }

        return 0;
    }

    /**
     * Tries the database until it answers. Null once it does, or why the last try failed.
     */
    private function waitForDatabase(): ?string
    {
        $problem = null;

        for ($attempt = 1; $attempt <= $this->databaseAttempts; $attempt++) {
            if ($attempt > 1) {
                $this->sleep(self::DATABASE_PAUSE);
            }

            try {
                $this->service(Database::class)->fetchValue('SELECT 1');

                return null;
            } catch (Throwable $exception) {
                $problem = $exception->getMessage();
            }
        }

        return $problem;
    }

    private function sleep(int $seconds): void
    {
        if ($this->sleep !== null) {
            ($this->sleep)($seconds);

            return;
        }

        sleep($seconds);
    }

    /**
     * @return list<string>
     */
    private function databaseProblems(): array
    {
        $driver = $this->environment->get('REBUJI_DB_DRIVER') ?? 'sqlite';

        if ($driver !== 'sqlite' && $driver !== 'mysql') {
            return ['REBUJI_DB_DRIVER は sqlite か mysql にしてください。'];
        }

        if ($driver === 'sqlite') {
            return [];
        }

        $problems = [];

        foreach (['REBUJI_DB_HOST', 'REBUJI_DB_NAME', 'REBUJI_DB_USERNAME'] as $name) {
            if (($this->environment->get($name) ?? '') === '') {
                $problems[] = '環境変数 ' . $name . ' がありません。';
            }
        }

        return $problems;
    }

    /**
     * @param list<string> $problems
     */
    private function fail(array $problems, ConsoleIo $io): int
    {
        foreach ($problems as $problem) {
            $io->error($problem);
        }

        return 1;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = $this->container->get($id);
        assert($service instanceof $id);

        return $service;
    }
}
