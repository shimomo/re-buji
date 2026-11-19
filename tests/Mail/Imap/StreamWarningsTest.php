<?php

declare(strict_types=1);

namespace ReBuji\Tests\Mail\Imap;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Mail\Imap\StreamWarnings;
use RuntimeException;

/**
 * @author shimomo
 */
final class StreamWarningsTest extends TestCase
{
    #[Test]
    public function givesTheFirstWarningAsItWasWritten(): void
    {
        $path = sys_get_temp_dir() . '/rebuji-missing "a" & <b>.txt';
        // As under a web server, where PHP hands error handlers the message escaped for HTML.
        $previous = ini_set('html_errors', '1');

        try {
            [$result, $reason] = StreamWarnings::catch(static function () use ($path): string|false {
                trigger_error('old way', E_USER_DEPRECATED);

                return file_get_contents($path);
            });

            $this->assertFalse($result);
            $this->assertSame('file_get_contents(' . $path . '): Failed to open stream: No such file or directory', $reason);
            $this->assertSame('1', ini_get('html_errors'));
        } finally {
            ini_set('html_errors', (string) $previous);
        }
    }

    #[Test]
    public function putsThingsBackWhenTheCallThrows(): void
    {
        $previous = ini_set('html_errors', '1');
        $sentinel = static fn(): bool => true;
        set_error_handler($sentinel);

        try {
            try {
                StreamWarnings::catch(static fn(): never => throw new RuntimeException('boom'));
                $this->fail('The exception should reach the caller');
            } catch (RuntimeException $exception) {
                $this->assertSame('boom', $exception->getMessage());
            }

            $this->assertSame('1', ini_get('html_errors'));
            $current = set_error_handler(static fn(): bool => true);
            restore_error_handler();
            $this->assertSame($sentinel, $current);
        } finally {
            restore_error_handler();
            ini_set('html_errors', (string) $previous);
        }
    }
}
