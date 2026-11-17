<?php

declare(strict_types=1);

namespace ReBuji\Tests\Feed;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Feed\FailedTelegrams;
use ReBuji\SystemState;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TestDatabase;

/**
 * @author shimomo
 */
final class FailedTelegramsTest extends TestCase
{
    #[Test]
    public function keepsAReasonThatIsNotUtf8(): void
    {
        $state = self::state();

        (new FailedTelegrams($state))->add(FailedTelegrams::READ, '震源・震度に関する情報', 'https://example.com/broken.xml', "XML を読めません：\xff\xfe", new DateTimeImmutable('2026-11-18T00:00:00Z'));

        $reason = self::reasons($state)[0];
        $this->assertStringStartsWith('XML を読めません：', $reason);
        $this->assertTrue(mb_check_encoding($reason, 'UTF-8'));
    }

    #[Test]
    public function cutsALongReason(): void
    {
        $state = self::state();

        (new FailedTelegrams($state))->add(FailedTelegrams::READ, '震源・震度に関する情報', 'https://example.com/broken.xml', str_repeat('あ', 1000), new DateTimeImmutable('2026-11-18T00:00:00Z'));

        $this->assertSame(200, mb_strlen(self::reasons($state)[0]));
    }

    private static function state(): SystemState
    {
        $db = TestDatabase::open('sqlite');
        $clock = new FrozenClock();
        (new Migrator($db, Migrations::all(), $clock))->migrate();

        return new SystemState($db, $clock);
    }

    /**
     * @return list<string>
     */
    private static function reasons(SystemState $state): array
    {
        /** @var list<array{reason: string}> $entries */
        $entries = json_decode((string) $state->get('feed.failed_telegrams'), true, flags: JSON_THROW_ON_ERROR);

        return array_column($entries, 'reason');
    }
}
