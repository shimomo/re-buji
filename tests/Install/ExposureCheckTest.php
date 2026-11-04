<?php

declare(strict_types=1);

namespace ReBuji\Tests\Install;

use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Install\ExposureCheck;
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

    private string $root = '';

    #[Override]
    protected function setUp(): void
    {
        $this->root = TemporaryDirectory::create();
        mkdir($this->root . '/storage');
    }

    #[Override]
    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->root);
    }

    #[Test]
    public function passesWhenEveryPathIsDenied(): void
    {
        $client = new FakeHttpClient(403, [self::BASE . '/bin/cron.php' => 404]);
        $result = $this->check($client)->check(self::BASE . '/');

        $this->assertTrue($result->passed());
        $this->assertContains(self::BASE . '/storage/config.php', $client->requested);
        $this->assertContains(self::BASE . '/storage/database.sqlite', $client->requested);
        $this->assertContains(self::BASE . '/storage/install-key.txt', $client->requested);
        $this->assertContains(self::BASE . '/src/Version.php', $client->requested);
        $this->assertContains(self::BASE . '/vendor/composer/installed.json', $client->requested);
        $this->assertContains(self::BASE . '/bin/cron.php', $client->requested);
    }

    #[Test]
    public function failsWhenTheDatabaseIsReadable(): void
    {
        $result = $this->check(new FakeHttpClient(403, [self::BASE . '/storage/database.sqlite' => 200]))->check(self::BASE);

        $this->assertFalse($result->passed());
        $this->assertSame([self::BASE . '/storage/database.sqlite'], $result->exposed);
        $this->assertSame(200, $result->statuses[self::BASE . '/storage/database.sqlite']);
    }

    #[Test]
    public function treatsUnexpectedStatusesAsExposed(): void
    {
        $result = $this->check(new FakeHttpClient(403, [self::BASE . '/bin/cron.php' => 500, self::BASE . '/src/Version.php' => 301]))->check(self::BASE);

        $this->assertFalse($result->passed());
        $this->assertSame([self::BASE . '/src/Version.php', self::BASE . '/bin/cron.php'], $result->exposed);
    }

    #[Test]
    public function failsWhenAPathCannotBeReached(): void
    {
        $result = $this->check(new FakeHttpClient(403, [self::BASE . '/storage/config.php' => new FakeNetworkException('timed out')]))->check(self::BASE);

        $this->assertFalse($result->passed());
        $this->assertSame([], $result->exposed);
        $this->assertSame([self::BASE . '/storage/config.php'], $result->unreachable);
    }

    #[Test]
    public function asksForItsOwnProbeFirstAndRemovesIt(): void
    {
        $client = new FakeHttpClient();
        $result = $this->check($client)->check(self::BASE);

        $this->assertTrue($result->passed());
        $this->assertNull($result->probeFailure);
        $this->assertMatchesRegularExpression('#^https://example\.com/re-buji/install/probe/[0-9a-f]{32}$#', $client->requested[0]);
        $this->assertFileDoesNotExist($this->root . '/storage/install-probe.txt');
    }

    #[Test]
    public function refusesWhenTheProbeDoesNotComeBack(): void
    {
        // A server without this copy answers 404 to everything, which would otherwise look like protection.
        $client = new FakeHttpClient(404, [], false);
        $result = $this->check($client)->check(self::BASE);

        $this->assertFalse($result->passed());
        $this->assertSame('状態 404', $result->probeFailure);
        $this->assertSame([], $result->exposed);
        $this->assertCount(count(ExposureCheck::PATHS), $result->unreachable);
        $this->assertCount(1, $client->requested);
    }

    #[Test]
    public function refusesAProbeAnsweredWithOtherContent(): void
    {
        $result = $this->check(new FakeHttpClient(200, [], false))->check(self::BASE);

        $this->assertFalse($result->passed());
        $this->assertSame('別の内容（状態 200）', $result->probeFailure);
    }

    #[Test]
    public function refusesWhenTheProbeCannotBeSent(): void
    {
        $result = $this->check(new FakeHttpClient(new FakeNetworkException('timed out'), [], false))->check(self::BASE);

        $this->assertFalse($result->passed());
        $this->assertSame('接続できません：timed out', $result->probeFailure);
    }

    #[Test]
    public function probesFilesUnderThePackageUrl(): void
    {
        $client = new FakeHttpClient();
        $this->check($client)->check('https://example.com', 'https://example.com/app');

        $this->assertStringStartsWith('https://example.com/install/probe/', $client->requested[0]);
        $this->assertContains('https://example.com/app/storage/config.php', $client->requested);
        $this->assertNotContains('https://example.com/storage/config.php', $client->requested);
    }

    #[Test]
    public function checksOnlyTheGivenFiles(): void
    {
        $client = new FakeHttpClient();
        $this->check($client)->check(self::BASE, null, ['storage/install-key.txt']);

        $this->assertSame([self::BASE . '/storage/install-key.txt'], array_slice($client->requested, 1));
    }

    private function check(FakeHttpClient $client): ExposureCheck
    {
        return new ExposureCheck($client, new Psr17Factory(), new Paths($this->root));
    }
}
