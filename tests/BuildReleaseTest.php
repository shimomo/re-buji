<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The release zip carries the version its code says it is: the version shows on the database update screen and in
 * the User-Agent sent to JMA.
 *
 * @author shimomo
 */
final class BuildReleaseTest extends TestCase
{
    #[Test]
    public function refusesAVersionTheCodeDoesNotCarry(): void
    {
        $output = [];
        exec(escapeshellarg(dirname(__DIR__) . '/bin/build-release.sh') . ' 9.9.9 2>&1', $output, $status);

        $this->assertSame(1, $status);
        // The script reads the committed version, which may differ from this working tree's.
        $this->assertMatchesRegularExpression('/^src\/Version\.php says \S+, not 9\.9\.9;/m', implode("\n", array_map(strval(...), $output)));
        $this->assertFileDoesNotExist(dirname(__DIR__) . '/build/re-buji-9.9.9.zip');
    }
}
