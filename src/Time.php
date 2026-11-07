<?php

declare(strict_types=1);

namespace ReBuji;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Date-times are stored as UTC strings such as "2026-11-05T03:12:00Z", which sort and compare the same in SQLite and
 * MySQL.
 *
 * @author shimomo
 */
final class Time
{
    private const string FORMAT = 'Y-m-d\TH:i:s\Z';

    public static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function parse(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, new DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format(self::FORMAT) !== $value) {
            throw new InvalidArgumentException('Not a stored date-time: ' . $value);
        }

        return $parsed;
    }

    /**
     * Reads a nullable date-time column.
     */
    public static function parseNullable(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : self::parse((string) $value);
    }
}
