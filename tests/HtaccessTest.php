<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every directory outside public/ refuses web access on its own, in case the root .htaccess is not used.
 *
 * @author shimomo
 */
final class HtaccessTest extends TestCase
{
    #[Test]
    public function everyNonPublicDirectoryDeniesAccess(): void
    {
        foreach (['src', 'templates', 'migrations', 'bin', 'storage', 'tests', 'docs'] as $directory) {
            $file = dirname(__DIR__) . '/' . $directory . '/.htaccess';

            $this->assertFileExists($file, $directory);
            $contents = (string) file_get_contents($file);
            $this->assertStringContainsString('Require all denied', $contents, $directory);
            $this->assertStringContainsString('Deny from all', $contents, $directory);
        }
    }
}
