<?php

declare(strict_types=1);

namespace ReBuji\Tests\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Config\Config;
use ReBuji\Config\ConfigFile;
use ReBuji\Config\Environment;
use ReBuji\Tests\PrivateFileTest;
use ReBuji\Tests\Support\TemporaryDirectory;
use RuntimeException;

/**
 * @author shimomo
 */
final class ConfigFileTest extends TestCase
{
    #[Test]
    public function savesAndLoadsValues(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            $file = new ConfigFile($directory . '/config.php');
            $file->save(new Config(['db.driver' => 'sqlite', 'status.token' => 'abc']));

            $this->assertTrue($file->exists());
            $this->assertSame('sqlite', $file->load()->get('db.driver'));
            $this->assertSame('abc', $file->load()->get('status.token'));
            $this->assertSame('x', $file->load()->get('missing', 'x'));
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function savedFileIsReadableOnlyByTheOwner(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            (new ConfigFile($directory . '/config.php'))->save(new Config(['a' => 1]));
            clearstatcache();

            $permissions = fileperms($directory . '/config.php');
            $this->assertNotFalse($permissions);
            $this->assertSame(0600, $permissions & 0777);
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function keepsTheFilePrivateWhenSavedAgain(): void
    {
        $directory = TemporaryDirectory::create();
        $umask = umask(0);

        try {
            $file = new ConfigFile($directory . '/config.php');
            $file->save(new Config(['a' => 1]));
            $file->save(new Config(['a' => 2]));

            $this->assertSame(0600, PrivateFileTest::mode($directory . '/config.php'));
        } finally {
            umask($umask);
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function saveReplacesTheFileAtomically(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            $file = new ConfigFile($directory . '/config.php');
            $file->save(new Config(['a' => 1]));
            $file->save(new Config(['a' => 2]));

            $this->assertSame(['config.php'], array_values(array_diff((array) scandir($directory), ['.', '..'])));
            $this->assertSame(2, $file->load()->get('a'));
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function missingFileDoesNotExist(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            $file = new ConfigFile($directory . '/config.php');

            $this->assertFalse($file->exists());
            $this->expectException(RuntimeException::class);
            $file->load();
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function environmentWinsOverTheFile(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            (new ConfigFile($directory . '/config.php'))->save(new Config(['db.driver' => 'mysql', 'status.token' => 'abc']));
            $file = new ConfigFile($directory . '/config.php', new Environment(['REBUJI_ENV_CONFIG' => '1']));

            $this->assertSame('sqlite', $file->load()->get('db.driver'));
            $this->assertSame('abc', $file->load()->get('status.token'));
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function saveLeavesEnvironmentValuesOut(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            $environment = new Environment(['REBUJI_ENV_CONFIG' => '1']);
            $file = new ConfigFile($directory . '/config.php', $environment);
            $file->save(new Config(['status.token' => 'x'] + $environment->databaseConfig()));

            // A file without the environment shows what was written.
            $this->assertSame(['status.token' => 'x'], (new ConfigFile($directory . '/config.php'))->load()->all());
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }
}
