<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Throwaway directories for tests. Remove them in a finally block.
 *
 * @author shimomo
 */
final class TemporaryDirectory
{
    public static function create(): string
    {
        $directory = sys_get_temp_dir() . '/rebuji-test-' . bin2hex(random_bytes(6));

        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }

        return $directory;
    }

    public static function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                chmod($entry->getPathname(), 0700);
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($directory);
    }
}
