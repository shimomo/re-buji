<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The release zip says how each entry may be written. Unpacked with unzip and copied up with scp or rsync, those
 * modes reach the server as they are, so no entry may be writable by the group or by others.
 *
 * @author shimomo
 */
final class BuildZipTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/re-buji-zip-' . bin2hex(random_bytes(4));
        mkdir($this->work . '/re-buji/public/assets', 0o777, true);
        chmod($this->work . '/re-buji/public/assets', 0o777);
        file_put_contents($this->work . '/re-buji/public/index.php', '<?php');
        chmod($this->work . '/re-buji/public/index.php', 0o666);
        file_put_contents($this->work . '/re-buji/run.sh', '#!/bin/sh');
        chmod($this->work . '/re-buji/run.sh', 0o775);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->work));
    }

    #[Test]
    public function noEntryIsWritableByTheGroupOrOthers(): void
    {
        $modes = $this->build();

        $this->assertSame(0o040755, $modes['re-buji/']);
        $this->assertSame(0o040755, $modes['re-buji/public/']);
        $this->assertSame(0o040755, $modes['re-buji/public/assets/']);
        $this->assertSame(0o100644, $modes['re-buji/public/index.php']);
        $this->assertSame(0o100755, $modes['re-buji/run.sh']);
    }

    /**
     * @return array<string, int> each entry's Unix mode, by name
     */
    private function build(): array
    {
        $target = $this->work . '/out.zip';
        $script = dirname(__DIR__) . '/build-files/zip.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($this->work . '/re-buji') . ' '
            . escapeshellarg($target) . ' 2>&1', $output, $status);
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
