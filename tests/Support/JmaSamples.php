<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use DateTimeImmutable;
use LogicException;
use ReBuji\Feed\TelegramRepository;

/**
 * Reads the official sample telegrams in tests/Fixtures/jma.
 *
 * @author shimomo
 */
final class JmaSamples
{
    public static function xml(string $file): string
    {
        $xml = file_get_contents(dirname(__DIR__) . '/Fixtures/jma/' . $file);

        if ($xml === false) {
            throw new LogicException('No such sample: ' . $file);
        }

        return $xml;
    }

    /**
     * A sample after string replacements; each search string must occur in the file.
     *
     * @param array<string, string> $replacements
     */
    public static function edited(string $file, array $replacements): string
    {
        $xml = self::xml($file);

        foreach ($replacements as $search => $replace) {
            if (!str_contains($xml, $search)) {
                throw new LogicException('Not in ' . $file . ': ' . $search);
            }

            $xml = str_replace($search, $replace, $xml);
        }

        return $xml;
    }

    public static function url(string $file): string
    {
        return 'https://www.data.jma.go.jp/developer/xml/data/' . $file;
    }

    /**
     * Stores samples as if the feed had returned them.
     */
    public static function store(TelegramRepository $telegrams, DateTimeImmutable $at, string ...$files): void
    {
        foreach ($files as $file) {
            $telegrams->store(self::url($file), self::xml($file), $at);
        }
    }
}
