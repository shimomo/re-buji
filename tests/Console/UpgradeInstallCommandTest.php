<?php

declare(strict_types=1);

namespace ReBuji\Tests\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReBuji\Config\Config;
use ReBuji\Config\ConfigFile;
use ReBuji\Config\Environment;
use ReBuji\Console\Console;
use ReBuji\Console\ConsoleIo;
use ReBuji\Container;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Paths;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Upgrade\Upgrade;
use ReBuji\Version;

/**
 * @author shimomo
 */
final class UpgradeInstallCommandTest extends TestCase
{
    #[Test]
    public function replacesEverythingButStorageAndAppliesTheDatabaseChanges(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            [$source, $target] = $this->copies($dir, appliedMigrations: 3);
            [$code, $io] = $this->install($source, ['--target=' . $target, '--yes']);

            $this->assertSame(0, $code, $io->errors());
            $this->assertSame(Version::CURRENT, Upgrade::versionOf($target));
            $this->assertSame('new', file_get_contents($target . '/src/marker.txt'));
            $this->assertFileDoesNotExist($target . '/src/old-only.txt');
            $this->assertSame('keep', file_get_contents($target . '/storage/keep.txt'));
            $this->assertSame([], $this->migrator($target)->pending());
            $this->assertStringContainsString('から ' . Version::CURRENT . ' に上げます。', $io->output());
            $this->assertStringContainsString('DB を更新しました：', $io->output());
            $this->assertStringContainsString(Version::CURRENT . ' に上げました。', $io->output());
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    /**
     * The command runs from the new copy: moving its files away would leave the running process unable to load the
     * classes it has not loaded yet (the migrations need some).
     */
    #[Test]
    public function leavesTheRunningCopyWhereItIs(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            [$source, $target] = $this->copies($dir, appliedMigrations: 3);
            [$code, $io] = $this->install($source, ['--target=' . $target, '--yes']);

            $this->assertSame(0, $code, $io->errors());
            $this->assertSame('new', file_get_contents($source . '/src/marker.txt'));
            $this->assertSame('new index', file_get_contents($source . '/public/index.php'));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    /**
     * Everything is copied next to the copy before the first entry is swapped, so a copy that fails (a full disk, an
     * unreadable file) leaves the installed copy as it was rather than half new.
     */
    #[Test]
    public function aFailedCopyReplacesNothing(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            [$source, $target] = $this->copies($dir);
            mkdir($source . '/vendor');
            file_put_contents($source . '/vendor/unreadable.php', 'x');
            chmod($source . '/vendor/unreadable.php', 0000);

            [$code, $io] = $this->install($source, ['--target=' . $target, '--yes']);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('ファイルを置き換えられなかったので、元に戻しました', $io->errors());
            $this->assertSame('old', file_get_contents($target . '/src/marker.txt'));
            $this->assertSame('old index', file_get_contents($target . '/public/index.php'));
            $this->assertSame(Upgrade::versionOf($target), '0.1.0');
            $this->assertSame([], glob($target . '/storage/upgrade-trash-*'));
        } finally {
            @chmod($dir . '/new/vendor/unreadable.php', 0644);
            TemporaryDirectory::remove($dir);
        }
    }

    /**
     * mkdir() is masked by the umask of the SSH session: with 077 the web server could no longer read public/ and
     * assets/.
     */
    #[Test]
    public function directoriesKeepTheirModesWhateverTheUmask(): void
    {
        $dir = TemporaryDirectory::create();
        $umask = umask(0077);

        try {
            [$source, $target] = $this->copies($dir);
            chmod($source . '/public', 0755);
            chmod($source . '/public/assets', 0755);
            $public = $dir . '/www';
            mkdir($public);
            file_put_contents($public . '/index.php', 'old index');
            file_put_contents($public . '/rebuji-root.php', "<?php return '" . $target . "';\n");

            [$code, $io] = $this->install($source, ['--target=' . $target, '--public=' . $public, '--yes']);

            $this->assertSame(0, $code, $io->errors());
            $this->assertSame('0755', substr(sprintf('%o', (int) fileperms($target . '/public')), -4));
            $this->assertSame('0755', substr(sprintf('%o', (int) fileperms($target . '/public/assets')), -4));
            $this->assertSame('0755', substr(sprintf('%o', (int) fileperms($public . '/assets')), -4));
        } finally {
            umask($umask);
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function remembersTheRealPathOfThePublicDirectory(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            [$source, $target] = $this->copies($dir);
            mkdir($dir . '/www');
            file_put_contents($dir . '/www/index.php', 'old index');
            file_put_contents($dir . '/www/rebuji-root.php', "<?php return '" . $target . "';\n");

            [$code, $io] = $this->install($source, ['--target=' . $target, '--public=' . $dir . '/www/../www/', '--yes']);

            $this->assertSame(0, $code, $io->errors());
            $this->assertSame(realpath($dir . '/www'), (new ConfigFile($target . '/storage/config.php'))->load()->get(Upgrade::PUBLIC_DIR));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function keepsACopyOfTheOldFilesAndDatabase(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            [$source, $target] = $this->copies($dir, appliedMigrations: 3);
            [$code, $io] = $this->install($source, ['--target=' . $target, '--yes']);

            $backup = $target . '/storage/' . Upgrade::BACKUPS . '/0.1.0-20261105-121200';
            $this->assertSame(0, $code, $io->errors());
            $this->assertSame('old', file_get_contents($backup . '/files/src/marker.txt'));
            $this->assertFileDoesNotExist($backup . '/files/storage');
            $this->assertSame('0600', substr(sprintf('%o', (int) fileperms($backup . '/database.sqlite')), -4));
            $this->assertSame(3, (int) Database::sqliteFile($backup . '/database.sqlite')->fetchValue('SELECT COUNT(*) FROM schema_migrations'));
            $this->assertStringContainsString('今の中身を写しました：' . $backup, $io->output());
            $this->assertStringContainsString('元に戻すときは：', $io->output());
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function updatesThePublicDirectoryAndRemembersIt(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            [$source, $target] = $this->copies($dir);
            $public = $dir . '/www/re-buji';
            mkdir($public . '/assets', 0755, true);
            file_put_contents($public . '/index.php', 'old index');
            file_put_contents($public . '/assets/old.css', 'old');
            file_put_contents($public . '/.htaccess', 'mine');
            file_put_contents($public . '/rebuji-root.php', "<?php return '" . $target . "';\n");

            [$code, $io] = $this->install($source, ['--target=' . $target, '--public=' . $public, '--yes']);

            $this->assertSame(0, $code, $io->errors());
            $this->assertSame('new index', file_get_contents($public . '/index.php'));
            $this->assertSame('new', file_get_contents($public . '/assets/app.css'));
            $this->assertFileDoesNotExist($public . '/assets/old.css');
            $this->assertSame('mine', file_get_contents($public . '/.htaccess'));
            $this->assertStringContainsString($target, (string) file_get_contents($public . '/rebuji-root.php'));
            $this->assertSame($public, (new ConfigFile($target . '/storage/config.php'))->load()->get(Upgrade::PUBLIC_DIR));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function usesTheRememberedPublicDirectoryNextTime(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            [$source, $target] = $this->copies($dir);
            $public = $dir . '/www';
            mkdir($public);
            file_put_contents($public . '/index.php', 'old index');
            file_put_contents($public . '/rebuji-root.php', "<?php return '" . $target . "';\n");
            $config = new ConfigFile($target . '/storage/config.php');
            $config->save(new Config([Upgrade::PUBLIC_DIR => $public] + $config->load()->all()));

            [$code, $io] = $this->install($source, ['--target=' . $target, '--yes']);

            $this->assertSame(0, $code, $io->errors());
            $this->assertStringContainsString('公開ディレクトリ：' . $public, $io->output());
            $this->assertSame('new index', file_get_contents($public . '/index.php'));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function refusesAPublicDirectoryThatPointsElsewhere(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            [$source, $target] = $this->copies($dir);
            $public = $dir . '/www';
            mkdir($public);
            file_put_contents($public . '/index.php', 'old index');
            file_put_contents($public . '/rebuji-root.php', "<?php return '/somewhere/else';\n");

            [$code, $io] = $this->install($source, ['--target=' . $target, '--public=' . $public, '--yes']);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('rebuji-root.php が ' . $target . ' を指していません', $io->errors());
            $this->assertSame('old', file_get_contents($target . '/src/marker.txt'));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function keepsARootPointerLeftInPublic(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            [$source, $target] = $this->copies($dir);
            file_put_contents($target . '/public/rebuji-root.php', "<?php return '/x';\n");

            [$code, $io] = $this->install($source, ['--target=' . $target, '--yes']);

            $this->assertSame(0, $code, $io->errors());
            $this->assertSame("<?php return '/x';\n", file_get_contents($target . '/public/rebuji-root.php'));
            $this->assertSame('new index', file_get_contents($target . '/public/index.php'));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function asksAndStopsWithoutYes(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            [$source, $target] = $this->copies($dir);
            [$code, $io] = $this->install($source, ['--target=' . $target], "n\n");

            $this->assertSame(1, $code);
            $this->assertStringContainsString('上げますか？ [y/N] ', $io->output());
            $this->assertStringContainsString('やめました。何も置き換えていません。', $io->output());
            $this->assertSame('old', file_get_contents($target . '/src/marker.txt'));
            $this->assertDirectoryDoesNotExist($target . '/storage/' . Upgrade::BACKUPS);
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function goesAheadWhenAnsweredYes(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            [$source, $target] = $this->copies($dir);
            [$code, $io] = $this->install($source, ['--target=' . $target], "y\n");

            $this->assertSame(0, $code, $io->errors());
            $this->assertSame('new', file_get_contents($target . '/src/marker.txt'));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function saysUpToDateWhenNotNewer(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            [$source, $target] = $this->copies($dir, installed: Version::CURRENT);
            [$code, $io] = $this->install($source, ['--target=' . $target, '--yes']);

            $this->assertSame(0, $code);
            $this->assertStringContainsString('最新です（' . Version::CURRENT . '）。', $io->output());
            $this->assertSame('old', file_get_contents($target . '/src/marker.txt'));
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function refusesInDocker(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            [$source, $target] = $this->copies($dir);
            [$code, $io] = $this->install($source, ['--target=' . $target, '--yes'], '', ['REBUJI_ENV_CONFIG' => '1']);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('Docker では使いません。', $io->errors());
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    #[Test]
    public function refusesAMissingInstallation(): void
    {
        $dir = TemporaryDirectory::create();

        try {
            [$source] = $this->copies($dir);
            [$code, $io] = $this->install($source, ['--target=' . $dir . '/nothing', '--yes']);

            $this->assertSame(1, $code);
            $this->assertStringContainsString($dir . '/nothing に設置した Re:無事 が見つかりません。', $io->errors());

            [$code, $io] = $this->install($source, ['--yes']);
            $this->assertSame(2, $code);
            $this->assertStringContainsString('使い方：', $io->errors());
        } finally {
            TemporaryDirectory::remove($dir);
        }
    }

    /**
     * A new copy at $dir/new (this version) and an installed one at $dir/re-buji with its own files and a SQLite
     * database that has the first $appliedMigrations changes.
     *
     * @return array{0: string, 1: string}
     */
    private function copies(string $dir, string $installed = '0.1.0', int $appliedMigrations = 5): array
    {
        $source = $dir . '/new';
        $target = $dir . '/re-buji';

        foreach ([$source => [Version::CURRENT, 'new'], $target => [$installed, 'old']] as $root => [$version, $marker]) {
            mkdir($root . '/src', 0755, true);
            mkdir($root . '/public/assets', 0755, true);
            mkdir($root . '/storage');
            file_put_contents($root . '/src/Version.php', "<?php\nfinal class Version\n{\n    public const string CURRENT = '" . $version . "';\n}\n");
            file_put_contents($root . '/src/marker.txt', $marker);
            file_put_contents($root . '/public/index.php', $marker . ' index');
            file_put_contents($root . '/public/assets/app.css', $marker);
        }

        file_put_contents($target . '/src/old-only.txt', 'gone');
        file_put_contents($target . '/storage/keep.txt', 'keep');
        (new ConfigFile($target . '/storage/config.php'))->save(new Config([
            'db.driver' => 'sqlite',
            'db.sqlite.path' => 'database.sqlite',
            'installed_at' => '2026-11-01T00:00:00Z',
        ]));
        (new Migrator(Database::sqliteFile($target . '/storage/database.sqlite'), array_slice(Migrations::all(), 0, $appliedMigrations), new FrozenClock()))->migrate();

        return [$source, $target];
    }

    private function migrator(string $target): Migrator
    {
        return new Migrator(Database::sqliteFile($target . '/storage/database.sqlite'), Migrations::all(), new FrozenClock());
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $vars
     *
     * @return array{0: int, 1: ConsoleIo}
     */
    private function install(string $source, array $args, string $input = '', array $vars = []): array
    {
        $paths = new Paths($source);
        $env = new Environment($vars);
        $container = Container::build($paths, [Environment::class => $env, ClockInterface::class => new FrozenClock()]);
        $io = ConsoleIo::memory($input);

        return [(new Console($paths, $env, $container))->run(['upgrade:install', ...$args], $io), $io];
    }
}
