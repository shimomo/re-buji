<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\PrivateFile;
use ReBuji\Tests\Support\TemporaryDirectory;
use RuntimeException;

/**
 * Files holding secrets are made readable by the owner alone from the start, whatever the umask.
 *
 * @author shimomo
 */
final class PrivateFileTest extends TestCase
{
    #[Test]
    public function createsAFileOnlyTheOwnerCanRead(): void
    {
        $directory = TemporaryDirectory::create();
        $umask = umask(0);

        try {
            PrivateFile::write($directory . '/a', 'secret');

            $this->assertSame(0600, self::mode($directory . '/a'));
            $this->assertSame('secret', file_get_contents($directory . '/a'));
        } finally {
            umask($umask);
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function refusesToWriteOverAnExistingFile(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            file_put_contents($directory . '/a', 'old');

            try {
                PrivateFile::write($directory . '/a', 'new');
                $this->fail('An existing file was written over.');
            } catch (RuntimeException) {
                $this->assertSame('old', file_get_contents($directory . '/a'));
            }
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function leavesAnExistingFileAlone(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            file_put_contents($directory . '/a', 'old');
            chmod($directory . '/a', 0644);

            $this->assertFalse(PrivateFile::create($directory . '/a'));
            $this->assertSame(0644, self::mode($directory . '/a'));
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    public function saysWhenItCannotCreateTheFile(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            $this->assertFalse(PrivateFile::create($directory . '/missing/a'));
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    public static function mode(string $path): int
    {
        clearstatcache();
        $permissions = fileperms($path);

        return $permissions === false ? -1 : $permissions & 0777;
    }
}
