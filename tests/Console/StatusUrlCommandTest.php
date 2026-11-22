<?php

declare(strict_types=1);

namespace ReBuji\Tests\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Config\Config;
use ReBuji\Config\ConfigFile;
use ReBuji\Config\Environment;
use ReBuji\Console\Console;
use ReBuji\Console\ConsoleIo;
use ReBuji\Container;
use ReBuji\Paths;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * @author shimomo
 */
final class StatusUrlCommandTest extends TestCase
{
    #[Test]
    public function printsTheUrl(): void
    {
        $root = TemporaryDirectory::create();

        try {
            mkdir($root . '/storage');
            $paths = new Paths($root);
            (new ConfigFile($paths->storage('config.php')))->save(new Config(['status.token' => 'abc123']));
            $io = ConsoleIo::memory();

            $code = (new Console($paths, new Environment([]), Container::build($paths)))->run(['status-url', 'https://anpi.example.jp/'], $io);

            $this->assertSame(0, $code);
            $this->assertSame("https://anpi.example.jp/status/abc123\n", $io->output());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function saysWhenBootIsNotDone(): void
    {
        $root = TemporaryDirectory::create();

        try {
            mkdir($root . '/storage');
            $paths = new Paths($root);
            $io = ConsoleIo::memory();

            $code = (new Console($paths, new Environment([]), Container::build($paths)))->run(['status-url', 'https://anpi.example.jp'], $io);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('まだ boot が済んでいません。', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
