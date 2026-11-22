<?php

declare(strict_types=1);

namespace ReBuji\Tests\Console;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReBuji\Config\ConfigFile;
use ReBuji\Config\Environment;
use ReBuji\Console\Console;
use ReBuji\Console\ConsoleIo;
use ReBuji\Container;
use ReBuji\Database\Migration;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Paths;
use ReBuji\SystemState;
use ReBuji\Tests\Support\EnvExample;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * @author shimomo
 */
final class BootCommandTest extends TestCase
{
    private const array MAIL = [
        'REBUJI_SMTP_HOST' => 'smtp.example.jp',
        'REBUJI_MAIL_FROM_ADDRESS' => 'anpi@example.jp',
        'REBUJI_SMTP_USERNAME' => 'anpi@example.jp',
        'REBUJI_SMTP_PASSWORD' => 'secret',
        'REBUJI_IMAP_HOST' => 'imap.example.jp',
    ];

    #[Test]
    public function refusesWithoutEnvConfig(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io] = $this->boot($root, ['REBUJI_ENV_CONFIG' => '0']);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('この命令は Docker の設置でだけ使います。', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function firstBootPreparesEverything(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $clock = new FrozenClock();
            [$code, , $container] = $this->boot($root, self::MAIL, $clock);

            $this->assertSame(0, $code);
            $config = (new ConfigFile((new Paths($root))->storage('config.php')))->load();
            $this->assertNotNull($config->get('installed_at'));
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', (string) $config->get('status.token'));
            $migrator = $container->get(Migrator::class);
            $this->assertInstanceOf(Migrator::class, $migrator);
            $this->assertSame([], $migrator->pending());
            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(SystemState::class, $state);
            $this->assertNotNull($state->get('imap.since'));
            $this->assertSame("imap.example.jp\nanpi@example.jp", $state->get('imap.account'));
            $this->assertDirectoryExists($root . '/storage/logs');
            $this->assertDirectoryExists($root . '/storage/sessions');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function secondBootKeepsTokenAndSince(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $clock = new FrozenClock();
            [, , $first] = $this->boot($root, self::MAIL, $clock);
            $token = $this->token($root);
            $since = $this->state($first)->get('imap.since');
            $clock->advance('+1 hour');

            [$code, , $second] = $this->boot($root, self::MAIL, $clock);

            $this->assertSame(0, $code);
            $this->assertSame($token, $this->token($root));
            $this->assertSame($since, $this->state($second)->get('imap.since'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function changedMailboxResetsSince(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $clock = new FrozenClock();
            $this->boot($root, self::MAIL, $clock);
            $clock->advance('+1 hour');

            [, , $second] = $this->boot($root, ['REBUJI_IMAP_USERNAME' => 'other@example.jp'] + self::MAIL, $clock);

            $state = $this->state($second);
            $this->assertSame('2026-11-05T04:12:00Z', $state->get('imap.since'));
            $this->assertSame("imap.example.jp\nother@example.jp", $state->get('imap.account'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function migratesPendingOnUpgrade(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $this->boot($root, self::MAIL);
            $extra = new class () implements Migration {
                #[Override]
                public function version(): string
                {
                    return '9999';
                }

                #[Override]
                public function description(): string
                {
                    return 'test';
                }

                #[Override]
                public function statements(string $driver): array
                {
                    return ['CREATE TABLE upgrade_probe (id INTEGER)'];
                }
            };

            [$code, $io, $container] = $this->boot($root, self::MAIL, null, ['migrations' => [...Migrations::all(), $extra]]);

            $this->assertSame(0, $code);
            $this->assertStringContainsString('9999', $io->output());
            $migrator = $container->get(Migrator::class);
            $this->assertInstanceOf(Migrator::class, $migrator);
            $this->assertSame([], $migrator->pending());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function stopsOnMissingMysqlValues(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io] = $this->boot($root, ['REBUJI_DB_DRIVER' => 'mysql', 'REBUJI_DB_HOST' => 'db']);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('環境変数 REBUJI_DB_NAME がありません。', $io->errors());
            $this->assertStringContainsString('環境変数 REBUJI_DB_USERNAME がありません。', $io->errors());
            $this->assertStringNotContainsString('REBUJI_DB_HOST がありません', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function stopsOnUnknownDriver(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io] = $this->boot($root, ['REBUJI_DB_DRIVER' => 'postgres']);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('REBUJI_DB_DRIVER は sqlite か mysql にしてください。', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function stopsOnMalformedMail(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io] = $this->boot($root, ['REBUJI_SMTP_PORT' => 'abc'] + self::MAIL);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('REBUJI_SMTP_PORT は 1〜65535 の数にしてください。', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function warnsWithoutMail(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io] = $this->boot($root, []);

            $this->assertSame(0, $code);
            $this->assertStringContainsString('メールの設定がありません。ホーム画面にも出ます。', $io->output() . $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function bootsWithTheEnvExample(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io] = $this->boot($root, ['REBUJI_DOMAIN' => 'anpi.example.jp'] + EnvExample::vars());

            $this->assertSame(0, $code, $io->errors());
            $this->assertStringContainsString('メールの設定がありません。ホーム画面にも出ます。', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function bootsWithBlankMailValues(): void
    {
        $root = TemporaryDirectory::create();

        try {
            [$code, $io] = $this->boot($root, EnvExample::blankMail());

            $this->assertSame(0, $code, $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    private function token(string $root): string
    {
        return (string) (new ConfigFile((new Paths($root))->storage('config.php')))->load()->get('status.token');
    }

    private function state(\Psr\Container\ContainerInterface $container): SystemState
    {
        $state = $container->get(SystemState::class);
        $this->assertInstanceOf(SystemState::class, $state);

        return $state;
    }

    /**
     * @param array<string, string> $vars
     * @param array<string, mixed> $overrides
     *
     * @return array{int, ConsoleIo, \Psr\Container\ContainerInterface}
     */
    private function boot(string $root, array $vars, ?ClockInterface $clock = null, array $overrides = []): array
    {
        $paths = new Paths($root);
        $env = new Environment($vars + ['REBUJI_ENV_CONFIG' => '1']);
        $container = Container::build($paths, $overrides + [Environment::class => $env, ClockInterface::class => $clock ?? new FrozenClock()]);
        $io = ConsoleIo::memory();

        return [(new Console($paths, $env, $container))->run(['boot'], $io), $io, $container];
    }
}
