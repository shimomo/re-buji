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

    #[Test]
    public function prefersAReadingNotReceivedYetOnATie(): void
    {
        // Not in yet counts as 5弱, the least it may be: stronger than a 4 for sure.
        $this->assertTrue(MatchedCity::raise([self::city('2221300', '4', MatchedCity::BASIS_CITY)], [self::city('2221300', null, MatchedCity::BASIS_CITY, true)])[0]->notYetReceived);
        // The contact's own reading not in yet stays against a 5弱 for sure.
        $this->assertTrue(MatchedCity::raise([self::city('2221300', null, MatchedCity::BASIS_CITY, true)], [self::city('2221300', '5-', MatchedCity::BASIS_CITY)])[0]->notYetReceived);

        // As strong, and only the other is not in yet: it may turn out stronger, so the mail keeps saying so.
        $raised = MatchedCity::raise([self::city('2221300', '5-', MatchedCity::BASIS_CITY)], [self::city('2221300', null, MatchedCity::BASIS_AREA, true)]);

        $this->assertTrue($raised[0]->notYetReceived);
        $this->assertSame(MatchedCity::BASIS_AREA, $raised[0]->basis);
    }

    private static function city(string $code, ?string $intensity, string $basis, bool $notYetReceived = false): MatchedCity
    {
        return new MatchedCity($code, '自宅', $code, $intensity, $notYetReceived, $basis);
    }
}
