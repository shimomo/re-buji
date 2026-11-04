<?php

declare(strict_types=1);

namespace ReBuji\Tests\Install;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Install\ExposureCheck;
use ReBuji\Tests\Support\FakeHttpClient;
use ReBuji\Tests\Support\FakeNetworkException;

/**
 * @author shimomo
 */
final class ExposureCheckTest extends TestCase
{
    private const string BASE = 'https://example.com/re-buji';

    #[Test]
    public function passesWhenEveryPathIsDenied(): void
    {
        $client = new FakeHttpClient(403, [self::BASE . '/bin/cron.php' => 404]);
        $result = (new ExposureCheck($client, new Psr17Factory()))->check(self::BASE . '/');

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
        $result = (new ExposureCheck(new FakeHttpClient(403, [self::BASE . '/storage/database.sqlite' => 200]), new Psr17Factory()))->check(self::BASE);

        $this->assertFalse($result->passed());
        $this->assertSame([self::BASE . '/storage/database.sqlite'], $result->exposed);
        $this->assertSame(200, $result->statuses[self::BASE . '/storage/database.sqlite']);
    }

    #[Test]
    public function treatsUnexpectedStatusesAsExposed(): void
    {
        $result = (new ExposureCheck(new FakeHttpClient(403, [self::BASE . '/bin/cron.php' => 500, self::BASE . '/src/Version.php' => 301]), new Psr17Factory()))->check(self::BASE);

        $this->assertFalse($result->passed());
        $this->assertSame([self::BASE . '/src/Version.php', self::BASE . '/bin/cron.php'], $result->exposed);
    }

    #[Test]
    public function failsWhenAPathCannotBeReached(): void
    {
        $result = (new ExposureCheck(new FakeHttpClient(403, [self::BASE . '/storage/config.php' => new FakeNetworkException('timed out')]), new Psr17Factory()))->check(self::BASE);

        $this->assertFalse($result->passed());
        $this->assertSame([], $result->exposed);
        $this->assertSame([self::BASE . '/storage/config.php'], $result->unreachable);
    }
}
