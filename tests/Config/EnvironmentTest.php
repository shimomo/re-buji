<?php

declare(strict_types=1);

namespace ReBuji\Tests\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Config\Environment;
use ReBuji\Tests\Support\TemporaryDirectory;
use RuntimeException;

/**
 * @author shimomo
 */
final class EnvironmentTest extends TestCase
{
    #[Test]
    public function isDisabledWithoutTheSwitch(): void
    {
        $environment = new Environment(['REBUJI_DB_DRIVER' => 'mysql']);

        $this->assertFalse($environment->enabled());
        $this->assertSame([], $environment->databaseConfig());
    }

    #[Test]
    public function readsAValueOrItsFile(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            file_put_contents($directory . '/secret', "secret\n");
            $file = ['REBUJI_SMTP_PASSWORD_FILE' => $directory . '/secret'];

            $this->assertSame('secret', (new Environment($file))->get('REBUJI_SMTP_PASSWORD'));
            $this->assertSame('plain', (new Environment($file + ['REBUJI_SMTP_PASSWORD' => 'plain']))->get('REBUJI_SMTP_PASSWORD'));
            $this->assertNull((new Environment([]))->get('REBUJI_SMTP_PASSWORD'));
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function failsOnAnUnreadableFile(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            $environment = new Environment(['REBUJI_SMTP_PASSWORD_FILE' => $directory . '/missing']);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('REBUJI_SMTP_PASSWORD_FILE');
            $environment->get('REBUJI_SMTP_PASSWORD');
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function buildsSqliteByDefault(): void
    {
        $environment = new Environment(['REBUJI_ENV_CONFIG' => '1']);

        $this->assertSame(['db.driver' => 'sqlite', 'db.sqlite.path' => 'database.sqlite'], $environment->databaseConfig());
    }

    #[Test]
    public function buildsMysql(): void
    {
        $vars = [
            'REBUJI_ENV_CONFIG' => '1',
            'REBUJI_DB_DRIVER' => 'mysql',
            'REBUJI_DB_HOST' => 'db',
            'REBUJI_DB_NAME' => 'rebuji',
            'REBUJI_DB_USERNAME' => 'user',
            'REBUJI_DB_PASSWORD' => 'pass',
        ];
        $expected = [
            'db.driver' => 'mysql',
            'db.mysql.host' => 'db',
            'db.mysql.port' => 3306,
            'db.mysql.database' => 'rebuji',
            'db.mysql.username' => 'user',
            'db.mysql.password' => 'pass',
        ];

        $this->assertSame($expected, (new Environment($vars))->databaseConfig());
        $this->assertSame(3307, (new Environment($vars + ['REBUJI_DB_PORT' => '3307']))->databaseConfig()['db.mysql.port']);
    }
}
