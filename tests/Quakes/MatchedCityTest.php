<?php

declare(strict_types=1);

namespace ReBuji\Tests\Quakes;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Quakes\MatchedCity;

/**
 * @author shimomo
 */
final class MatchedCityTest extends TestCase
{
    #[Test]
    public function raisesOnlyWhereTheOtherIsStronger(): void
    {
        $raised = MatchedCity::raise(
            [
                self::city('2221300', '4', MatchedCity::BASIS_CITY),
                self::city('2220500', '5-', MatchedCity::BASIS_CITY),
                self::city('2221100', '5+', MatchedCity::BASIS_CITY),
            ],
            [
                self::city('2221300', '6-', MatchedCity::BASIS_AREA),
                self::city('2220500', '5-', MatchedCity::BASIS_AREA),
                self::city('0321000', '5+', MatchedCity::BASIS_CITY),
            ],
        );

        $this->assertSame(
            [
                ['2221300', '6-', MatchedCity::BASIS_AREA],
                // As strong: the contact's own quake keeps its reading.
                ['2220500', '5-', MatchedCity::BASIS_CITY],
                // Not named by the others: as it was, even if weaker than before.
                ['2221100', '5+', MatchedCity::BASIS_CITY],
                ['0321000', '5+', MatchedCity::BASIS_CITY],
            ],
            array_map(static fn(MatchedCity $city): array => [$city->code, $city->intensity, $city->basis], $raised),
        );
    }

    private static function city(string $code, string $intensity, string $basis): MatchedCity
    {
        return new MatchedCity($code, '自宅', $code, $intensity, false, $basis);
    }
}
