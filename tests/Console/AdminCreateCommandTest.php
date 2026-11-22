<?php

declare(strict_types=1);

namespace ReBuji\Tests\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Auth\AdminRepository;
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

/**
 * @author shimomo
 */
final class AdminCreateCommandTest extends TestCase
{
    #[Test]
    public function createsFromOptionsAndPipedPassword(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io, $container] = $this->create($root, ['--name=山田', '--email=yamada@example.jp'], "long-enough-pass\n");

            $this->assertSame(0, $code);
            $this->assertStringContainsString('管理者 山田（yamada@example.jp）を作りました。', $io->output());
            $this->assertStringNotContainsString('long-enough-pass', $io->output() . $io->errors());
            $admin = $this->admins($container)->findByEmail('yamada@example.jp');
            $this->assertNotNull($admin);
            $this->assertSame('山田', $admin['name']);
            $this->assertTrue(password_verify('long-enough-pass', $admin['password_hash']));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function asksForMissingNameAndEmail(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, , $container] = $this->create($root, [], "山田\nyamada@example.jp\nlong-enough-pass\n");

            $this->assertSame(0, $code);
            $this->assertNotNull($this->admins($container)->findByEmail('yamada@example.jp'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function rejectsShortPassword(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io, $container] = $this->create($root, ['--name=山田', '--email=yamada@example.jp'], "short\n");

            $this->assertSame(1, $code);
            $this->assertStringContainsString('パスワードは 12 文字以上にしてください', $io->errors());
            $this->assertSame(0, $this->admins($container)->count());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function rejectsMalformedEmail(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io] = $this->create($root, ['--name=山田', '--email=nope'], "long-enough-pass\n");

            $this->assertSame(1, $code);
            $this->assertStringContainsString('メールアドレスの形が正しくありません。', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function rejectsEmptyName(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io] = $this->create($root, ['--name=', '--email=yamada@example.jp'], "long-enough-pass\n");

            $this->assertSame(1, $code);
            $this->assertStringContainsString('名前を入れてください。', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function rejectsDuplicateEmail(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $this->create($root, ['--name=山田', '--email=yamada@example.jp'], "long-enough-pass\n");
            [$code, $io, $container] = $this->create($root, ['--name=別人', '--email=Yamada@example.jp'], "long-enough-pass\n");

            $this->assertSame(1, $code);
            $this->assertStringContainsString('このメールアドレスの管理者はもういます。', $io->errors());
            $this->assertSame(1, $this->admins($container)->count());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    private function admins(ContainerInterface $container): AdminRepository
    {
        $admins = $container->get(AdminRepository::class);
        $this->assertInstanceOf(AdminRepository::class, $admins);

        return $admins;
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, ConsoleIo, ContainerInterface}
     */
    private function create(string $root, array $args, string $input): array
    {
        $paths = new Paths($root);
        $env = new Environment(['REBUJI_ENV_CONFIG' => '1']);
        $clock = new FrozenClock();
        // What boot leaves behind: storage/ and an empty config file, the database coming from the environment.
        is_dir($root . '/storage') || mkdir($root . '/storage');
        (new ConfigFile($paths->storage('config.php')))->save(new Config([]));
        $container = Container::build($paths, [Environment::class => $env, ClockInterface::class => $clock]);
        $db = $container->get(Database::class);
        $this->assertInstanceOf(Database::class, $db);
        (new Migrator($db, Migrations::all(), $clock))->migrate();
        $io = ConsoleIo::memory($input);

        return [(new Console($paths, $env, $container))->run(['admin:create', ...$args], $io), $io, $container];
    }
}
