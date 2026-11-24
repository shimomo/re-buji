<?php

declare(strict_types=1);

namespace ReBuji\Tests\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Console\ConsoleIo;

/**
 * @author shimomo
 */
final class ConsoleIoTest extends TestCase
{
    #[Test]
    public function tellsAnEmptyLineFromTheEndOfTheInput(): void
    {
        $io = ConsoleIo::memory("\n");

        $this->assertSame('', $io->ask('一つめ：'));
        $this->assertFalse($io->ended());
        $this->assertSame('', $io->ask('二つめ：'));
        $this->assertTrue($io->ended());
    }
}
