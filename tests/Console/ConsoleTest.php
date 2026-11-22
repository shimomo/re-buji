<?php

declare(strict_types=1);

namespace ReBuji\Tests\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Config\Environment;
use ReBuji\Console\Console;
use ReBuji\Console\ConsoleIo;
use ReBuji\Container;
use ReBuji\Paths;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * @author shimomo
 */
final class ConsoleTest extends TestCase
{
    #[Test]
    public function listsCommands(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $io = ConsoleIo::memory();
            $console = new Console(new Paths($root), new Environment([]), Container::build(new Paths($root)));

            $this->assertSame(0, $console->run([], $io));
            $this->assertSame(0, $console->run(['help'], $io));

            $this->assertStringContainsString('boot', $io->output());
            $this->assertStringContainsString('status-url', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function rejectsUnknownCommand(): void
    {
        $root = TemporaryDirectory::create();

        try {
            $io = ConsoleIo::memory();
            $console = new Console(new Paths($root), new Environment([]), Container::build(new Paths($root)));

            $this->assertSame(2, $console->run(['nope'], $io));
            $this->assertStringContainsString('知らないコマンドです：nope', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
