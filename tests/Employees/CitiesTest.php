<?php

declare(strict_types=1);

namespace ReBuji\Tests\Employees;

use JmaXml\Codes\EarthquakeCodes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Employees\Cities;

/**
 * @author shimomo
 */
final class CitiesTest extends TestCase
{
    #[Test]
    public function findsACityWithItsPrefecture(): void
    {
        $city = Cities::find('1310100');

        $this->assertNotNull($city);
        $this->assertSame('東京千代田区', $city->name);
        $this->assertSame('13', $city->prefectureCode);
        $this->assertSame('東京都', $city->prefectureName);
        $this->assertSame('東京都 東京千代田区', $city->label());
    }

    #[Test]
    public function refusesCodesTheEarthquakeInformationCannotUse(): void
    {
        // The whole of Sapporo (its wards are used instead), Hamamatsu before its 2024 reorganisation, too short, empty.
        foreach (['0110000', '2213100', '131010', ''] as $code) {
            $this->assertNull(Cities::find($code), $code);
        }
    }

    #[Test]
    public function groupsEveryCityUnderItsPrefecture(): void
    {
        $byPrefecture = Cities::byPrefecture();
        $tokyo = array_values(array_filter($byPrefecture, static fn(array $prefecture): bool => $prefecture['code'] === '13'))[0];

        $this->assertCount(47, $byPrefecture);
        $this->assertSame('東京都', $tokyo['name']);
        $this->assertContains(['code' => '1310100', 'name' => '東京千代田区'], $tokyo['cities']);
        $this->assertSame(count(EarthquakeCodes::cities()), array_sum(array_map(static fn(array $prefecture): int => count($prefecture['cities']), $byPrefecture)));
        $this->assertSame(['code' => '01', 'name' => '北海道'], Cities::prefectures()[0]);
        $this->assertCount(47, Cities::prefectures());
    }

    #[Test]
    public function restoresTheLeadingZeroExcelDrops(): void
    {
        $this->assertSame('0110100', Cities::normalizeCode('110100'));
        $this->assertSame('1310100', Cities::normalizeCode(' 1310100 '));
        $this->assertSame('abc', Cities::normalizeCode('abc'));
    }
}
