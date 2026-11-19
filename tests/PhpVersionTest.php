<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Tests\Support\TemporaryDirectory;

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

        $this->assertSame('Re:無事 には PHP 8.3 以上が必要です（今は 8.2.29）。サーバーの管理画面で PHP の版を変えてください。', $check(80229, '8.2.29', false));
        $this->assertSame(
            'Re:無事 には PHP 8.3 以上が必要です（今は 5.6.40）。cron に登録したコマンドで、PHP 8.3 以上の php を指定してください（場所はサーバーによって違います。さくらのレンタルサーバでは /usr/local/php/8.3/bin/php）。',
            $check(50640, '5.6.40', true),
        );
        $this->assertNull($check(80300, '8.3.0', false));
        $this->assertNull($check(80300, '8.3.0', true));
        $this->assertNull($check(PHP_VERSION_ID, PHP_VERSION, true));
    }

    #[Test]
    public function answersTheTwoArgumentsOfAnOlderEntryPoint(): void
    {
        // v0.1.5's public/index.php and bin/cron.php pass two arguments. During an upgrade, or where public/index.php
        // was not copied again, they meet this file.
        $check = require dirname(__DIR__) . '/src/php-version.php';
        $this->assertInstanceOf(Closure::class, $check);

        $this->assertSame('Re:無事 には PHP 8.3 以上が必要です（今は 8.2.29）。サーバーの管理画面で PHP の版を変えてください。', $check(80229, '8.2.29'));
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

    #[Test]
    public function tellsEachEntryPointItsOwnFix(): void
    {
        $root = TemporaryDirectory::create();
        $www = TemporaryDirectory::create();
        $app = dirname(__DIR__);

        try {
            mkdir($root . '/public');
            mkdir($root . '/bin');
            mkdir($root . '/src');
            copy($app . '/public/index.php', $root . '/public/index.php');
            copy($app . '/bin/cron.php', $root . '/bin/cron.php');
            // Turns away whatever PHP runs the test, and says which entry point asked.
            file_put_contents($root . '/src/php-version.php', "<?php\n\nreturn static fn(int \$versionId, string \$version, bool \$cron): string => \$cron ? '[cron]' : '[web]';\n");
            // Only public/ in the document root, pointing at the app with rebuji-root.php.
            copy($app . '/public/index.php', $www . '/index.php');
            file_put_contents($www . '/rebuji-root.php', "<?php return '" . $root . "';\n");

            $this->assertSame([0, '[web]'], self::runFile($root . '/public/index.php'));
            $this->assertSame([1, '[cron]'], self::runFile($root . '/bin/cron.php'));
            $this->assertSame([0, '[web]'], self::runFile($www . '/index.php'));
        } finally {
            TemporaryDirectory::remove($root);
            TemporaryDirectory::remove($www);
        }
    }

    /**
     * Runs a file as its own PHP process, as cron (no REQUEST_METHOD) would.
     *
     * @return array{int, string} the exit code, and stdout and stderr together, trimmed
     */
    private static function runFile(string $file): array
    {
        $environment = array_filter(getenv(), static fn(int|string $key): bool => $key !== 'REQUEST_METHOD', ARRAY_FILTER_USE_KEY);
        $process = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, dirname($file), $environment);
        assert(is_resource($process));
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), trim($output)];
    }
}
