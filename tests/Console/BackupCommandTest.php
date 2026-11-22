<?php

declare(strict_types=1);

namespace ReBuji\Tests\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Config\Config;
use ReBuji\Config\ConfigFile;
use ReBuji\Config\Environment;
use ReBuji\Console\Console;
use ReBuji\Console\ConsoleIo;
use ReBuji\Container;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Employees\EmployeeInput;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Paths;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * @author shimomo
 */
final class BackupCommandTest extends TestCase
{
    #[Test]
    public function copiesTheSqliteDatabase(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io, $container] = $this->backup($root, [], [$root . '/out.sqlite'], static function (ContainerInterface $container): void {
                $db = $container->get(Database::class);
                $clock = $container->get(ClockInterface::class);
                assert($db instanceof Database && $clock instanceof ClockInterface);
                (new EmployeeRepository($db, $clock))->create(EmployeeInput::from('山田', '', ['yamada@example.jp'], [], '', true));
            });

            $this->assertSame(0, $code);
            $this->assertStringContainsString('バックアップを作りました：' . $root . '/out.sqlite', $io->output());
            $this->assertSame('0600', substr(sprintf('%o', (int) fileperms($root . '/out.sqlite')), -4));
            $this->assertSame(1, (int) Database::sqliteFile($root . '/out.sqlite')->fetchValue('SELECT COUNT(*) FROM employees'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesExistingFile(): void
    {
        $root = TemporaryDirectory::create();

        try {
            file_put_contents($root . '/out.sqlite', 'keep');
            [$code, $io] = $this->backup($root, [], [$root . '/out.sqlite']);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('ファイルがもうあります：' . $root . '/out.sqlite', $io->errors());
            $this->assertSame('keep', file_get_contents($root . '/out.sqlite'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesMysql(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io] = $this->backup($root, ['REBUJI_DB_DRIVER' => 'mysql'], [$root . '/out.sql']);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('MySQL では mysqldump でバックアップを取ってください。', $io->errors());
            $this->assertFileDoesNotExist($root . '/out.sql');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function needsAPath(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io] = $this->backup($root, [], []);

            $this->assertSame(2, $code);
            $this->assertStringContainsString('使い方：console backup <出力先のパス>', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * @param array<string, string> $vars
     * @param list<string> $args
     * @param (callable(ContainerInterface): void)|null $seed
     *
     * @return array{int, ConsoleIo, ContainerInterface}
     */
    private function backup(string $root, array $vars, array $args, ?callable $seed = null): array
    {
        $paths = new Paths($root);
        $env = new Environment($vars + ['REBUJI_ENV_CONFIG' => '1']);
        $clock = new FrozenClock();
        is_dir($root . '/storage') || mkdir($root . '/storage');
        (new ConfigFile($paths->storage('config.php')))->save(new Config([]));
        $container = Container::build($paths, [Environment::class => $env, ClockInterface::class => $clock]);

        if (($vars['REBUJI_DB_DRIVER'] ?? 'sqlite') === 'sqlite') {
            $db = $container->get(Database::class);
            $this->assertInstanceOf(Database::class, $db);
            (new Migrator($db, Migrations::all(), $clock))->migrate();
        }

        if ($seed !== null) {
            $seed($container);
        }

        $io = ConsoleIo::memory();

        return [(new Console($paths, $env, $container))->run(['backup', ...$args], $io), $io, $container];
    }
}
