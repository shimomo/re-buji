<?php

declare(strict_types=1);

namespace ReBuji\Tests\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Log\FileLogger;
use ReBuji\Tests\PrivateFileTest;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * @author shimomo
 */
final class FileLoggerTest extends TestCase
{
    #[Test]
    public function createsTheLogOnlyTheOwnerCanRead(): void
    {
        $directory = TemporaryDirectory::create();
        $umask = umask(0);

        try {
            (new FileLogger($directory . '/re-buji.log', new FrozenClock()))->error('x');

            $this->assertSame(0600, PrivateFileTest::mode($directory . '/re-buji.log'));
        } finally {
            umask($umask);
            TemporaryDirectory::remove($directory);
        }
    }

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
