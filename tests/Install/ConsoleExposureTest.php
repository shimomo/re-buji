<?php

declare(strict_types=1);

namespace ReBuji\Tests\Install;

use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReBuji\Console\ConsoleIo;
use ReBuji\Install\ConsoleExposure;
use ReBuji\Install\ExposureCheck;
use ReBuji\Install\ProbeVisit;
use ReBuji\Install\WebSide;
use ReBuji\Paths;
use ReBuji\Tests\Support\FakeHttpClient;
use ReBuji\Tests\Support\FakeNetworkException;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * @author shimomo
 */
final class ConsoleExposureTest extends TestCase
{
    private const string URL = 'https://example.jp/anpi';

    #[Test]
    public function passesWhenTheUrlReachesThisCopyAndNothingIsExposed(): void
    {
        $root = $this->root();

        try {
            $client = new FakeHttpClient();
            $io = ConsoleIo::memory();

            $this->assertTrue($this->exposure($root, $client)->run(self::URL, 'sqlite', $io));
            $this->assertStringContainsString('✓ この URL がこの Re:無事 に届き、データベースや設定がウェブから読めないことを確かめました', $io->output());
            $this->assertContains('https://example.jp/anpi/storage/config.php', $client->requested);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function stopsOnAnotherAnswerWithoutOfferingToCheckByHand(): void
    {
        $root = $this->root();

        try {
            $io = ConsoleIo::memory("y\n");

            $this->assertFalse($this->exposure($root, new FakeHttpClient(404, [], false))->run(self::URL, 'sqlite', $io));
            $this->assertStringContainsString('（状態 404）', $io->errors());
            $this->assertStringContainsString('URL が正しいか', $io->errors());
            $this->assertStringNotContainsString('[y/N]', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function stopsOnARedirectAndSaysWhichUrlToUse(): void
    {
        $root = $this->root();

        try {
            $client = new class () implements ClientInterface {
                #[Override]
                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    return new Response(302, ['Location' => 'https://example.jp/anpi/install/probe/x?from=console']);
                }
            };
            $io = ConsoleIo::memory();

            $this->assertFalse($this->exposure($root, $client)->run(self::URL, 'sqlite', $io));
            $this->assertStringContainsString('--url https://example.jp/anpi を付けて', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function stopsWhenThePackageCanBeReadFromTheWeb(): void
    {
        $root = $this->root();

        try {
            $client = new FakeHttpClient(404, ['https://example.jp/re-buji/storage/config.php' => 200], true, new WebSide('/re-buji', ['sqlite'], true));
            $io = ConsoleIo::memory();

            $this->assertFalse($this->exposure($root, $client)->run(self::URL, 'sqlite', $io));
            $this->assertStringContainsString('ウェブから見えています：https://example.jp/re-buji/storage/config.php（状態 200）', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function stopsWithoutTheDatabaseDriverOrStorageOnTheWeb(): void
    {
        $root = $this->root();

        try {
            $io = ConsoleIo::memory();

            $this->assertFalse($this->exposure($root, new FakeHttpClient(404, [], true, new WebSide(null, ['mysql'], true)))->run(self::URL, 'sqlite', $io));
            $this->assertStringContainsString('ウェブの PHP に pdo_sqlite がありません', $io->errors());

            $io = ConsoleIo::memory();

            $this->assertFalse($this->exposure($root, new FakeHttpClient(404, [], true, new WebSide(null, ['sqlite'], false)))->run(self::URL, 'sqlite', $io));
            $this->assertStringContainsString('ウェブの PHP から storage/ に書けません', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function asksTheBrowserWhenTheServerCannotReachItself(): void
    {
        $root = $this->root();

        try {
            $io = ConsoleIo::memory("y\n");
            $exposure = $this->exposure($root, new FakeHttpClient(new FakeNetworkException('timed out'), [], false), $this->browserOpens($root));

            $this->assertTrue($exposure->run(self::URL, 'sqlite', $io));
            $this->assertStringContainsString('サーバーから https://example.jp/anpi に接続できませんでした（timed out）。', $io->output());
            $this->assertStringContainsString('?from=browser', $io->output());
            $this->assertStringContainsString('✓ ブラウザで開かれました', $io->output());
            $this->assertStringContainsString('  https://example.jp/anpi/storage/config.php', $io->output());
            $this->assertStringContainsString('✓ ブラウザで確かめました', $io->output());
            $this->assertFilesAreGone($root);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function stopsWhenTheBrowserNeverOpensIt(): void
    {
        $root = $this->root();

        try {
            $slept = 0;
            $io = ConsoleIo::memory("y\n");
            $exposure = $this->exposure($root, new FakeHttpClient(new FakeNetworkException('timed out'), [], false), static function (int $seconds) use (&$slept): void {
                $slept += $seconds;
            });

            $this->assertFalse($exposure->run(self::URL, 'sqlite', $io));
            $this->assertSame(600, $slept);
            $this->assertStringContainsString('10 分のあいだに、ブラウザで開かれませんでした', $io->errors());
            $this->assertFilesAreGone($root);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function stopsWhenTheOwnerDoesNotConfirm(): void
    {
        $root = $this->root();

        try {
            $io = ConsoleIo::memory("\n");
            $exposure = $this->exposure($root, new FakeHttpClient(new FakeNetworkException('timed out'), [], false), $this->browserOpens($root));

            $this->assertFalse($exposure->run(self::URL, 'sqlite', $io));
            $this->assertStringContainsString('確かめが済んでいないので、止めます', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function asksByHandForFilesItCouldNotReach(): void
    {
        $root = $this->root();

        try {
            $io = ConsoleIo::memory("y\n");
            $client = new FakeHttpClient(403, ['https://example.jp/anpi/bin/cron.php' => new FakeNetworkException('reset')]);

            $this->assertTrue($this->exposure($root, $client)->run(self::URL, 'sqlite', $io));
            $this->assertStringContainsString('サーバーから次の URL を確かめられませんでした', $io->output());
            $this->assertStringContainsString('  https://example.jp/anpi/bin/cron.php', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * @return Closure(int): void
     */
    private function browserOpens(string $root): Closure
    {
        return static function () use ($root): void {
            $nonce = trim((string) file_get_contents($root . '/storage/install-probe.txt'));
            (new ProbeVisit(new Paths($root)))->record($nonce, new WebSide(null, ['sqlite'], true));
        };
    }

    /**
     * @param ?Closure(int): void $sleep
     */
    private function exposure(string $root, ClientInterface $client, ?Closure $sleep = null): ConsoleExposure
    {
        $paths = new Paths($root);

        return new ConsoleExposure(new ExposureCheck($client, new Psr17Factory(), $paths), new ProbeVisit($paths), $sleep ?? static function (int $seconds): void {
            //
        });
    }

    private function assertFilesAreGone(string $root): void
    {
        $this->assertFileDoesNotExist($root . '/storage/install-probe.txt');
        $this->assertFileDoesNotExist($root . '/storage/install-probe-visit.json');
    }

    private function root(): string
    {
        $root = TemporaryDirectory::create();
        mkdir($root . '/storage');

        return $root;
    }
}
