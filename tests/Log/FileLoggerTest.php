<?php

declare(strict_types=1);

namespace ReBuji\Tests\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Log\FileLogger;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * @author shimomo
 */
final class FileLoggerTest extends TestCase
{
    #[Test]
    public function appendsOneLinePerEntry(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            $logger = new FileLogger($directory . '/logs/re-buji.log', new FrozenClock());
            $logger->error('x', ['a' => 1]);
            $logger->error('x', ['a' => 1]);
            $lines = file($directory . '/logs/re-buji.log', FILE_IGNORE_NEW_LINES);

            $this->assertIsArray($lines);
            $this->assertCount(2, $lines);

            foreach ($lines as $line) {
                $this->assertStringContainsString('2026-11-05T03:12:00Z', $line);
                $this->assertStringContainsString('ERROR', $line);
                $this->assertStringContainsString(' x ', $line);
                $this->assertStringContainsString('{"a":1}', $line);
            }
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }
}
