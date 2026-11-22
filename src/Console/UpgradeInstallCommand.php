<?php

declare(strict_types=1);

namespace ReBuji\Console;

use DateTimeZone;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Config\Environment;
use ReBuji\Database\Migration;
use ReBuji\Paths;
use ReBuji\Upgrade\Upgrade;
use ReBuji\Version;
use Throwable;

/**
 * Run by bin/upgrade.sh from the new copy it unpacked: puts that copy in place of the installed one, backs up first,
 * applies the database changes and updates the public directory. Holds the cron lock throughout, so cron skips its
 * runs meanwhile instead of meeting half-replaced files.
 *
 * @author shimomo
 */
final class UpgradeInstallCommand implements Command
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Environment $environment,
        private readonly ContainerInterface $container,
    ) {
        //
    }

    #[Override]
    public function name(): string
    {
        return 'upgrade:install';
    }

    #[Override]
    public function summary(): string
    {
        return 'bin/upgrade.sh が使う：この版を、設置してある Re:無事 に上書きする';
    }

    #[Override]
    public function run(array $args, ConsoleIo $io): int
    {
        if ($this->environment->enabled()) {
            $io->error('Docker では使いません。docker compose pull で新しいイメージを取ってください。');

            return 1;
        }

        $options = self::options($args);

        if ($options === null || $options['target'] === '') {
            $io->error('使い方：console upgrade:install --target=<設置した場所> [--public=<公開ディレクトリ>] [--yes]');

            return 2;
        }

        $target = rtrim($options['target'], '/');
        $from = Upgrade::versionOf($target);

        if ($from === null || !is_file($target . '/storage/config.php')) {
            $io->error($target . ' に設置した Re:無事 が見つかりません。');

            return 1;
        }

        $to = Version::CURRENT;

        if (version_compare($to, $from, '<=')) {
            $io->line('最新です（' . $from . '）。');

            return 0;
        }

        $clock = $this->container->get(ClockInterface::class);
        $migrations = $this->container->get('migrations');
        assert($clock instanceof ClockInterface && is_array($migrations));
        /** @var list<Migration> $migrations */
        $upgrade = new Upgrade($this->paths->root(), $target, $migrations, $clock);

        try {
            $config = $upgrade->config()->load();
            $remembered = is_string($config->get(Upgrade::PUBLIC_DIR)) ? (string) $config->get(Upgrade::PUBLIC_DIR) : null;
            $public = $options['public'] !== '' ? rtrim($options['public'], '/') : $remembered;

            if ($public !== null && ($problem = $upgrade->publicDirProblem($public)) !== null) {
                $io->error($problem);

                return 1;
            }

            $pending = $upgrade->pendingMigrations();
            $mysql = $config->get('db.driver') === 'mysql';
        } catch (Throwable $exception) {
            $io->error('設置した Re:無事 を読めませんでした：' . $exception->getMessage());

            return 1;
        }

        $io->line(sprintf('Re:無事 を %s から %s に上げます。', $from, $to));
        $io->line('設置した場所：' . $target);
        $io->line('公開ディレクトリ：' . ($public ?? '（設置した場所の public/ をそのまま使う）'));
        $io->line('DB の更新：' . ($pending === [] ? 'なし' : implode('、', array_map(static fn(Migration $m): string => $m->version() . ' ' . $m->description(), $pending))));

        if ($mysql) {
            $io->line('MySQL の DB は写しません。先に mysqldump でバックアップを取ってください。');
        }

        if (!$options['yes'] && !in_array(strtolower(trim($io->ask('上げますか？ [y/N] '))), ['y', 'yes'], true)) {
            $io->line('やめました。何も変えていません。');

            return 1;
        }

        $lock = $this->lockCron($target, $io);

        if ($lock === null) {
            return 1;
        }

        $backup = $target . '/storage/' . Upgrade::BACKUPS . '/' . $from . '-' . $clock->now()->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Ymd-His');

        try {
            $upgrade->backUp($backup);
            $io->line('今の中身を写しました：' . $backup);
        } catch (Throwable $exception) {
            $io->error('写せなかったので、上げるのをやめました。何も置き換えていません：' . $exception->getMessage());
            flock($lock, LOCK_UN);

            return 1;
        }

        try {
            $upgrade->replace();
            $io->line('ファイルを置き換えました。');
            $applied = $upgrade->migrate();
            $io->line($applied === [] ? 'DB の更新はありませんでした。' : 'DB を更新しました：' . implode('、', $applied));

            if ($public !== null) {
                $upgrade->updatePublicDir($public);
                $upgrade->rememberPublicDir($public);
                $io->line('公開ディレクトリを置き換えました：' . $public);
            }
        } catch (Throwable $exception) {
            $io->error('途中で失敗しました：' . $exception->getMessage());
            $this->howToRestore($io, $target, $backup, $mysql);

            return 1;
        } finally {
            flock($lock, LOCK_UN);
        }

        $io->line(sprintf('%s に上げました。', $to));
        $this->howToRestore($io, $target, $backup, $mysql);

        return 0;
    }

    /**
     * @param list<string> $args
     *
     * @return array{target: string, public: string, yes: bool}|null
     */
    private static function options(array $args): ?array
    {
        $options = ['target' => '', 'public' => '', 'yes' => false];

        foreach ($args as $arg) {
            if ($arg === '--yes') {
                $options['yes'] = true;
            } elseif (str_starts_with($arg, '--target=')) {
                $options['target'] = substr($arg, 9);
            } elseif (str_starts_with($arg, '--public=')) {
                $options['public'] = substr($arg, 9);
            } else {
                return null;
            }
        }

        return $options;
    }

    /**
     * Takes the lock bin/cron.php runs under, waiting for a run in progress to finish.
     *
     * @return resource|null
     */
    private function lockCron(string $target, ConsoleIo $io)
    {
        $lock = @fopen($target . '/storage/cron.lock', 'c');

        if ($lock === false) {
            $io->error('cron のロックを開けません：' . $target . '/storage/cron.lock');

            return null;
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            $io->line('cron が動いています。終わるのを待っています…');
            flock($lock, LOCK_EX);
        }

        return $lock;
    }

    private function howToRestore(ConsoleIo $io, string $target, string $backup, bool $mysql): void
    {
        $io->line('');
        $io->line('元に戻すときは：');
        $io->line('  ' . $target . ' の storage/ 以外を、' . $backup . '/files の中身で置き換える');

        if (!$mysql) {
            $io->line('  ' . $target . '/storage/database.sqlite を、' . $backup . '/database.sqlite で置き換える');
        }

        $io->line('問題なく動いていれば、' . $backup . ' は消して構いません。');
    }
}
