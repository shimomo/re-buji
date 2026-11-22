<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Tests\Support\TemporaryDirectory;
use ZipArchive;

/**
 * The release zip says how each entry may be written. Unpacked with unzip and copied up with scp or rsync, those
 * modes reach the server as they are, so no entry may be writable by the group or by others.
 *
 * @author shimomo
 */
final class BuildZipTest extends TestCase
{
    #[Test]
    public function noEntryIsWritableByTheGroupOrOthers(): void
    {
        $work = TemporaryDirectory::create();

        try {
            mkdir($work . '/re-buji/public/assets', 0o777, true);
            chmod($work . '/re-buji/public/assets', 0o777);
            file_put_contents($work . '/re-buji/public/index.php', '<?php');
            chmod($work . '/re-buji/public/index.php', 0o666);
            file_put_contents($work . '/re-buji/run.sh', '#!/bin/sh');
            chmod($work . '/re-buji/run.sh', 0o775);

            $modes = $this->build($work . '/re-buji', $work . '/out.zip');

            $this->assertSame(0o040755, $modes['re-buji/']);
            $this->assertSame(0o040755, $modes['re-buji/public/']);
            $this->assertSame(0o040755, $modes['re-buji/public/assets/']);
            $this->assertSame(0o100644, $modes['re-buji/public/index.php']);
            $this->assertSame(0o100755, $modes['re-buji/run.sh']);
        } finally {
            TemporaryDirectory::remove($work);
        }
    }

    #[Test]
    public function makesTheSameZipFromTheSameFilesAtAnyTime(): void
    {
        // So the hash shown at `bin/release.sh check` is the hash of the zip `publish` uploads.
        $work = TemporaryDirectory::create();

        $zone = false;

        try {
            mkdir($work . '/app/sub', 0o755, true);
            file_put_contents($work . '/app/a.txt', 'a');
            file_put_contents($work . '/app/sub/b.txt', 'b');

            $this->build($work . '/app', $work . '/one.zip');
            touch($work . '/app/a.txt', time() + 3600);
            $this->build($work . '/app', $work . '/two.zip');

            $this->assertSame(hash_file('sha256', $work . '/one.zip'), hash_file('sha256', $work . '/two.zip'));
            // A zip's times carry no zone; zip.php writes them in UTC, so they are read in UTC.
            $zone = getenv('TZ');
            putenv('TZ=UTC');
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($work . '/two.zip'));

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $this->assertIsArray($stat);
                $this->assertSame(1700000000, $stat['mtime'], (string) $stat['name']);
            }

            $zip->close();
        } finally {
            putenv($zone === false ? 'TZ' : 'TZ=' . $zone);
            TemporaryDirectory::remove($work);
        }
    }

    #[Test]
    public function ordersTheEntriesWhateverOrderTheFilesWereMadeIn(): void
    {
        // A folder lists its files in the order the file system keeps them, which on tmpfs is the order they were made.
        $shm = is_dir('/dev/shm') && is_writable('/dev/shm');
        $work = $shm ? '/dev/shm/rebuji-test-' . bin2hex(random_bytes(6)) : TemporaryDirectory::create();
        $shm && mkdir($work);

        try {
            foreach (['one' => ['b.txt', 'a.txt', 'c/d.txt'], 'two' => ['c/d.txt', 'a.txt', 'b.txt']] as $copy => $files) {
                foreach ($files as $file) {
                    @mkdir(dirname($work . '/' . $copy . '/app/' . $file), 0o755, true);
                    file_put_contents($work . '/' . $copy . '/app/' . $file, $file);
                }

                $this->build($work . '/' . $copy . '/app', $work . '/' . $copy . '.zip');
            }

            $this->assertSame(hash_file('sha256', $work . '/one.zip'), hash_file('sha256', $work . '/two.zip'));
        } finally {
            TemporaryDirectory::remove($work);
        }
    }

    #[Test]
    public function datesTheEntriesTheSameInAnyTimeZone(): void
    {
        $work = TemporaryDirectory::create();

        try {
            mkdir($work . '/app');
            file_put_contents($work . '/app/a.txt', 'a');
            $script = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/build-files/zip.php') . ' ' . escapeshellarg($work . '/app') . ' ';
            exec('TZ=UTC ' . $script . escapeshellarg($work . '/utc.zip') . ' 1700000000', $output, $first);
            exec('TZ=Asia/Tokyo ' . $script . escapeshellarg($work . '/tokyo.zip') . ' 1700000000', $output, $second);

            $this->assertSame([0, 0], [$first, $second]);
            $this->assertSame(hash_file('sha256', $work . '/utc.zip'), hash_file('sha256', $work . '/tokyo.zip'));
        } finally {
            TemporaryDirectory::remove($work);
        }
    }

    #[Test]
    public function asksForTheTime(): void
    {
        $work = TemporaryDirectory::create();

        try {
            mkdir($work . '/app');
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/build-files/zip.php') . ' '
                . escapeshellarg($work . '/app') . ' ' . escapeshellarg($work . '/three.zip') . ' 2>&1', $output, $status);

            $this->assertSame(1, $status);
            $this->assertContains('Usage: php build-files/zip.php <source directory> <target zip> <mtime>', array_map(strval(...), $output));
        } finally {
            TemporaryDirectory::remove($work);
        }
    }

    /**
     * @return array<string, int> each entry's Unix mode, by name
     */
    private function build(string $source, string $target): array
    {
        $script = dirname(__DIR__) . '/build-files/zip.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($source) . ' '
            . escapeshellarg($target) . ' 1700000000 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", array_map(strval(...), $output)));

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($target));
        $modes = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $zip->getExternalAttributesIndex($i, $system, $attributes);
            $this->assertSame(ZipArchive::OPSYS_UNIX, $system, $name);
            $modes[$name] = ($attributes >> 16) & 0xFFFF;
        }

        $zip->close();

        return $modes;
    }
}
