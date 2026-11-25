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

    #[Test]
    public function readsASecretVisiblyWhenItCannotBeHidden(): void
    {
        $io = ConsoleIo::memory("first-secret\nsecond-secret\n", terminal: true, canHide: false);

        $this->assertSame('first-secret', $io->secret('パスワード：'));
        $this->assertSame('second-secret', $io->secret('もう一度：'));
        $this->assertSame(1, substr_count($io->output(), '  この PHP では shell_exec が止められているため、打ったパスワードが画面に見えます。'));
    }
}
