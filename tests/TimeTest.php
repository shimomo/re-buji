<?php

declare(strict_types=1);

namespace ReBuji\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Time;

/**
 * @author shimomo
 */
final class TimeTest extends TestCase
{
    #[Test]
    public function formatsInUtc(): void
    {
        $this->assertSame('2026-11-05T03:12:00Z', Time::format(new DateTimeImmutable('2026-11-05T12:12:00+09:00')));

        $parsed = Time::parse('2026-11-05T03:12:00Z');
        $this->assertSame('2026-11-05T03:12:00+00:00', $parsed->format(DATE_ATOM));
        $this->assertSame('UTC', $parsed->getTimezone()->getName());
    }

    #[Test]
    public function rejectsOtherShapes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Time::parse('2026-11-05 03:12:00');
    }
}
