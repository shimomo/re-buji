<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @author shimomo
 */
final class PhpVersionTest extends TestCase
{
    #[Test]
    public function turnsAwayAPhpOlderThan83(): void
    {
        $check = require dirname(__DIR__) . '/src/php-version.php';
        $this->assertInstanceOf(Closure::class, $check);

        $this->assertSame('Re:無事 には PHP 8.3 以上が必要です（今は 8.2.29）。サーバーの管理画面で PHP の版を変えてください。', $check(80229, '8.2.29'));
        $this->assertStringContainsString('（今は 7.4.33）', (string) $check(70433, '7.4.33'));
        $this->assertNull($check(80300, '8.3.0'));
        $this->assertNull($check(PHP_VERSION_ID, PHP_VERSION));
    }

    #[Test]
    public function checksBeforeLoadingTheApp(): void
    {
        foreach (['public/index.php', 'bin/cron.php'] as $file) {
            $code = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
            $check = strpos($code, '/src/php-version.php');
            $autoload = strpos($code, '/vendor/autoload.php');

            $this->assertNotFalse($check, $file);
            $this->assertNotFalse($autoload, $file);
            $this->assertLessThan($autoload, $check, $file);
        }
    }
}
