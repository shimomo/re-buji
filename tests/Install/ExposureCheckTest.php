<?php

declare(strict_types=1);

namespace ReBuji\Tests\Install;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReBuji\Install\ExposureCheck;
use ReBuji\Install\ProbeOutcome;
use ReBuji\Install\WebSide;
use ReBuji\Paths;
use ReBuji\Tests\Support\FakeHttpClient;
use ReBuji\Tests\Support\FakeNetworkException;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * @author shimomo
 */
final class ExposureCheckTest extends TestCase
{
    private const string BASE = 'https://example.com/re-buji';

    #[Test]
    public function passesWhenEveryPathIsDenied(): void
    {
        $root = $this->root();

        try {
            $client = new FakeHttpClient(403, [self::BASE . '/bin/cron.php' => 404]);
            $result = $this->check($root, $client)->check(self::BASE . '/');

            $this->assertTrue($result->passed());
            $this->assertContains(self::BASE . '/storage/config.php', $client->requested);
            $this->assertContains(self::BASE . '/storage/database.sqlite', $client->requested);
            $this->assertContains(self::BASE . '/storage/install-key.txt', $client->requested);
            $this->assertContains(self::BASE . '/src/Version.php', $client->requested);
            $this->assertContains(self::BASE . '/vendor/composer/installed.json', $client->requested);
            $this->assertContains(self::BASE . '/bin/cron.php', $client->requested);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function failsWhenTheDatabaseIsReadable(): void
    {
        $root = $this->root();

        try {
            $result = $this->check($root, new FakeHttpClient(403, [self::BASE . '/storage/database.sqlite' => 200]))->check(self::BASE);

            $this->assertFalse($result->passed());
            $this->assertSame([self::BASE . '/storage/database.sqlite'], $result->exposed);
            $this->assertSame(200, $result->statuses[self::BASE . '/storage/database.sqlite']);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function treatsUnexpectedStatusesAsExposed(): void
    {
        $root = $this->root();

        try {
            $result = $this->check($root, new FakeHttpClient(403, [self::BASE . '/bin/cron.php' => 500, self::BASE . '/src/Version.php' => 301]))->check(self::BASE);

            $this->assertFalse($result->passed());
            $this->assertSame([self::BASE . '/src/Version.php', self::BASE . '/bin/cron.php'], $result->exposed);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function failsWhenAPathCannotBeReached(): void
    {
        $root = $this->root();

        try {
            $result = $this->check($root, new FakeHttpClient(403, [self::BASE . '/storage/config.php' => new FakeNetworkException('timed out')]))->check(self::BASE);

            $this->assertFalse($result->passed());
            $this->assertSame([], $result->exposed);
            $this->assertSame([self::BASE . '/storage/config.php'], $result->unreachable);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function asksForItsOwnProbeFirstAndRemovesIt(): void
    {
        $root = $this->root();

        try {
            $client = new FakeHttpClient();
            $result = $this->check($root, $client)->check(self::BASE);

            $this->assertTrue($result->passed());
            $this->assertNull($result->probeFailure);
            $this->assertMatchesRegularExpression('#^https://example\.com/re-buji/install/probe/[0-9a-f]{32}$#', $client->requested[0]);
            $this->assertFileDoesNotExist($root . '/storage/install-probe.txt');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesWhenTheProbeDoesNotComeBack(): void
    {
        $root = $this->root();

        try {
            // A server without this copy answers 404 to everything, which would otherwise look like protection.
            $client = new FakeHttpClient(404, [], false);
            $result = $this->check($root, $client)->check(self::BASE);

            $this->assertFalse($result->passed());
            $this->assertSame('状態 404', $result->probeFailure);
            $this->assertSame([], $result->exposed);
            $this->assertCount(count(ExposureCheck::PATHS), $result->unreachable);
            $this->assertCount(1, $client->requested);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesAProbeAnsweredWithOtherContent(): void
    {
        $root = $this->root();

        try {
            $result = $this->check($root, new FakeHttpClient(200, [], false))->check(self::BASE);

            $this->assertFalse($result->passed());
            $this->assertSame('別の内容（状態 200）', $result->probeFailure);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesWhenTheProbeCannotBeSent(): void
    {
        $root = $this->root();

        try {
            $result = $this->check($root, new FakeHttpClient(new FakeNetworkException('timed out'), [], false))->check(self::BASE);

            $this->assertFalse($result->passed());
            $this->assertSame('接続できません：timed out', $result->probeFailure);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function probesFilesUnderThePackageUrl(): void
    {
        $root = $this->root();

        try {
            $client = new FakeHttpClient();
            $this->check($root, $client)->check('https://example.com', 'https://example.com/app');

            $this->assertStringStartsWith('https://example.com/install/probe/', $client->requested[0]);
            $this->assertContains('https://example.com/app/storage/config.php', $client->requested);
            $this->assertNotContains('https://example.com/storage/config.php', $client->requested);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function checksOnlyTheGivenFiles(): void
    {
        $root = $this->root();

        try {
            $client = new FakeHttpClient();
            $this->check($root, $client)->check(self::BASE, null, ['storage/install-key.txt']);

            $this->assertSame([self::BASE . '/storage/install-key.txt'], array_slice($client->requested, 1));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function reachesThisCopyAndReadsItsWebSide(): void
    {
        $root = $this->root();

        try {
            $client = new FakeHttpClient(403, [], true, new WebSide('/re-buji', ['sqlite'], false));
            $answer = $this->check($root, $client)->reach('https://example.jp/anpi');

            $this->assertSame(ProbeOutcome::Reached, $answer->outcome);
            $this->assertNotNull($answer->web);
            $this->assertSame('/re-buji', $answer->web->packagePath);
            $this->assertFalse($answer->web->storageWritable);
            $this->assertStringStartsWith('https://example.jp/anpi/install/probe/', $client->requested[0]);
            $this->assertStringEndsWith('?from=console', $client->requested[0]);
            $this->assertFileDoesNotExist($root . '/storage/install-probe.txt');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesAnotherAnswerOfTheUrl(): void
    {
        $root = $this->root();

        try {
            $notFound = $this->check($root, new FakeHttpClient(404, [], false))->reach('https://example.jp/anpi');
            $this->assertSame(ProbeOutcome::Refused, $notFound->outcome);
            $this->assertSame('状態 404', $notFound->detail);

            $other = $this->check($root, new FakeHttpClient(200, [], false))->reach('https://example.jp/anpi');
            $this->assertSame(ProbeOutcome::Refused, $other->outcome);
            $this->assertSame('別の内容（状態 200）', $other->detail);

            $message = 'Re:無事 には PHP 8.3 以上が必要です（今は 8.2.0）。サーバーの管理画面で PHP の版を変えてください。';
            $old = $this->check($root, $this->answering(new Response(500, ['Content-Type' => 'text/plain'], $message . "\n")))->reach('https://example.jp/anpi');
            $this->assertSame(ProbeOutcome::Refused, $old->outcome);
            $this->assertSame('状態 500：' . $message, $old->detail);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function reportsWhereTheUrlRedirects(): void
    {
        $root = $this->root();

        try {
            $location = 'https://example.jp/anpi/install/probe/x?from=console';
            $answer = $this->check($root, $this->answering(new Response(301, ['Location' => $location])))->reach('http://example.jp/anpi');

            $this->assertSame(ProbeOutcome::Redirected, $answer->outcome);
            $this->assertSame($location, $answer->detail);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function saysWhenItCannotConnect(): void
    {
        $root = $this->root();

        try {
            $answer = $this->check($root, new FakeHttpClient(new FakeNetworkException('timed out'), [], false))->reach('https://example.jp/anpi');

            $this->assertSame(ProbeOutcome::Unreachable, $answer->outcome);
            $this->assertStringContainsString('timed out', $answer->detail);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function judgesFilesUnderTheGivenBase(): void
    {
        $root = $this->root();

        try {
            $client = new FakeHttpClient(404, ['https://example.jp/re-buji/storage/config.php' => 200]);
            $result = $this->check($root, $client)->files('https://example.jp/re-buji', ['storage/config.php', 'src/Version.php']);

            $this->assertSame(['https://example.jp/re-buji/storage/config.php'], $result->exposed);
            $this->assertNull($result->probeFailure);
            $this->assertCount(2, $client->requested);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    private function answering(ResponseInterface $response): ClientInterface
    {
        return new readonly class ($response) implements ClientInterface {
            public function __construct(private ResponseInterface $response)
            {
                //
            }

            #[Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    private function root(): string
    {
        $root = TemporaryDirectory::create();
        mkdir($root . '/storage');

        return $root;
    }

    private function check(string $root, ClientInterface $client): ExposureCheck
    {
        return new ExposureCheck($client, new Psr17Factory(), new Paths($root));
    }
}
