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
use ReBuji\Install\ReachedUrl;
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

    private const string DIR = '/home/u/www/anpi';

    #[Test]
    public function passesWhenTheUrlReachesThisCopyAndNothingIsExposed(): void
    {
        $root = $this->root();

        try {
            $client = new FakeHttpClient();
            $io = ConsoleIo::memory();

            $this->assertTrue($this->through($this->exposure($root, $client), $io));
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

            $this->assertFalse($this->exposure($root, new FakeHttpClient(404, [], false))->reach(self::URL, self::DIR, $io));
            $this->assertStringContainsString('（状態 404）', $io->errors());
            $this->assertStringContainsString('その URL が /home/u/www/anpi を開くか、ウェブの PHP が 8.3 以上かを確かめてください。', $io->errors());
            $this->assertStringNotContainsString('[y/N]', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function namesTheDirectoryWhenAnotherAnswerComes(): void
    {
        $root = $this->root();

        try {
            $io = ConsoleIo::memory();
            $reached = $this->exposure($root, new FakeHttpClient(404, [], false))->reach(self::URL, self::DIR, $io);

            $this->assertFalse($reached);
            $this->assertStringContainsString('（状態 404）', $io->errors());
            $this->assertStringContainsString('その URL が /home/u/www/anpi を開くか、ウェブの PHP が 8.3 以上かを確かめてください。', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function followsARedirectThatKeepsTheProbe(): void
    {
        $root = $this->root();

        try {
            $client = $this->routed(static fn(string $url, string $nonce): ?Response => match (true) {
                str_starts_with($url, 'http://example.jp/anpi/install/probe/') => new Response(302, ['Location' => 'https://example.jp/anpi/install/probe/' . $nonce . '?from=console']),
                str_starts_with($url, 'https://example.jp/anpi/install/probe/') => self::probeAnswer($nonce),
                default => null,
            });
            $io = ConsoleIo::memory();
            $reached = $this->exposure($root, $client)->reach('http://example.jp/anpi', self::DIR, $io);

            $this->assertInstanceOf(ReachedUrl::class, $reached);
            $this->assertSame('https://example.jp/anpi', $reached->url);
            $this->assertFalse($reached->byBrowser);
            $this->assertStringContainsString('→ https://example.jp/anpi に転送されたので、こちらを使います', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function followsARelativeRedirectThatKeepsTheProbe(): void
    {
        $root = $this->root();

        try {
            $client = $this->routed(static fn(string $url, string $nonce): ?Response => match (true) {
                str_starts_with($url, 'http://example.jp/install/probe/') => new Response(302, ['Location' => '/anpi/install/probe/' . $nonce . '?from=console']),
                str_starts_with($url, 'http://example.jp/anpi/install/probe/') => self::probeAnswer($nonce),
                default => null,
            });
            $reached = $this->exposure($root, $client)->reach('http://example.jp', self::DIR, ConsoleIo::memory());

            $this->assertInstanceOf(ReachedUrl::class, $reached);
            $this->assertSame('http://example.jp/anpi', $reached->url);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function reportsARedirectElsewhereWithoutTheProbesQuery(): void
    {
        $root = $this->root();

        try {
            $client = $this->routed(static fn(string $url, string $nonce): Response => new Response(302, ['Location' => 'https://shimomo.net/?from=console']));
            $io = ConsoleIo::memory();

            $this->assertFalse($this->exposure($root, $client)->reach('https://example.jp/re-buji-test', self::DIR, $io));
            $this->assertStringContainsString('https://example.jp/re-buji-test は https://shimomo.net/ に転送されました。/home/u/www/anpi に届いていません。', $io->errors());
            $this->assertStringNotContainsString('from=console', $io->errors() . $io->output());
            $this->assertStringNotContainsString('--url', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function doesNotFollowALoginRedirectThatOnlyCarriesTheProbeInItsQuery(): void
    {
        $root = $this->root();

        try {
            $client = $this->routed(static fn(string $url, string $nonce): Response => new Response(302, ['Location' => 'https://example.jp/login?next=/anpi/install/probe/' . $nonce . '?from=console']));
            $io = ConsoleIo::memory();

            $this->assertFalse($this->exposure($root, $client)->reach('http://example.jp/anpi', self::DIR, $io));
            $this->assertStringContainsString('http://example.jp/anpi は https://example.jp/login?next=/anpi/install/probe/', $io->errors());
            $this->assertStringNotContainsString('from=console', $io->errors());
            $this->assertStringNotContainsString('に転送されたので', $io->output());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function treatsASecondRedirectAsNotReached(): void
    {
        $root = $this->root();

        try {
            $client = $this->routed(static fn(string $url, string $nonce): ?Response => match (true) {
                str_starts_with($url, 'http://example.jp/anpi/install/probe/') => new Response(302, ['Location' => 'https://example.jp/anpi/install/probe/' . $nonce . '?from=console']),
                str_starts_with($url, 'https://example.jp/anpi/install/probe/') => new Response(302, ['Location' => 'https://example.jp/login']),
                default => null,
            });
            $io = ConsoleIo::memory();
            $reached = $this->exposure($root, $client)->reach('http://example.jp/anpi', self::DIR, $io);

            $this->assertFalse($reached);
            $this->assertStringContainsString('https://example.jp/anpi は https://example.jp/login に転送されました。', $io->errors());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function asksTheBrowserToOpenTheUrlItWasLedTo(): void
    {
        $root = $this->root();

        try {
            $client = $this->routed(static fn(string $url, string $nonce): ?Response => match (true) {
                str_starts_with($url, 'http://example.jp/anpi/install/probe/') => new Response(302, ['Location' => 'https://example.jp/anpi/install/probe/' . $nonce . '?from=console']),
                str_starts_with($url, 'https://example.jp/anpi/install/probe/') => throw new FakeNetworkException('timed out'),
                default => null,
            });
            $io = ConsoleIo::memory();
            $reached = $this->exposure($root, $client, $this->browserOpens($root))->reach('http://example.jp/anpi', self::DIR, $io);

            $this->assertInstanceOf(ReachedUrl::class, $reached);
            $this->assertTrue($reached->byBrowser);
            $this->assertSame('https://example.jp/anpi', $reached->url);
            $this->assertStringContainsString('  https://example.jp/anpi/install/probe/', $io->output());
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

            $this->assertFalse($this->through($this->exposure($root, $client), $io));
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

            $this->assertFalse($this->through($this->exposure($root, new FakeHttpClient(404, [], true, new WebSide(null, ['mysql'], true))), $io));
            $this->assertStringContainsString('ウェブの PHP に pdo_sqlite がありません', $io->errors());

            $io = ConsoleIo::memory();

            $this->assertFalse($this->through($this->exposure($root, new FakeHttpClient(404, [], true, new WebSide(null, ['sqlite'], false))), $io));
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

            $this->assertTrue($this->through($exposure, $io));
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
    public function showsTheTimeLeftWhileWaitingForTheBrowser(): void
    {
        $root = $this->root();

        try {
            $slept = 0;
            $opens = $this->browserOpens($root);
            $io = ConsoleIo::memory("y\n");
            $exposure = $this->exposure($root, new FakeHttpClient(new FakeNetworkException('timed out'), [], false), static function (int $seconds) use (&$slept, $opens): void {
                $slept += $seconds;

                if ($slept > 70) {
                    $opens($seconds);
                }
            });

            $this->assertTrue($this->through($exposure, $io));
            $this->assertSame(1, substr_count($io->output(), '  まだ開かれていません（あと 9 分待ちます）'));
            $this->assertStringNotContainsString('あと 8 分待ちます', $io->output());
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

            $this->assertFalse($this->through($exposure, $io));
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

            $this->assertFalse($this->through($exposure, $io));
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

            $this->assertTrue($this->through($this->exposure($root, $client), $io));
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

    /**
     * Reaches the URL and, when it is reached, finishes the check.
     */
    private function through(ConsoleExposure $exposure, ConsoleIo $io): bool
    {
        $reached = $exposure->reach(self::URL, self::DIR, $io);

        return $reached instanceof ReachedUrl && $exposure->finish($reached, 'sqlite', $io);
    }

    /**
     * A client that tells the probe's nonce from the URL, and answers with what $route gives; 404 when it gives null.
     *
     * @param Closure(string, string): ?Response $route
     */
    private function routed(Closure $route): ClientInterface
    {
        return new class ($route) implements ClientInterface {
            /**
             * @param Closure(string, string): ?Response $route
             */
            public function __construct(private readonly Closure $route)
            {
                //
            }

            #[Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $url = (string) $request->getUri();
                $nonce = preg_match('#/install/probe/([0-9a-f]+)#', $url, $match) === 1 ? $match[1] : '';

                return ($this->route)($url, $nonce) ?? new Response(404);
            }
        };
    }

    private static function probeAnswer(string $nonce): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (new WebSide(null, ['sqlite', 'mysql'], true))->answer($nonce));
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
