<?php

declare(strict_types=1);

namespace ReBuji\Employees;

use JmaXml\Codes\EarthquakeCodes;
use JmaXml\Codes\Prefecture;

/**
 * The municipalities employees are registered by: JMA's 7-digit codes from jmaxml/codes. A code that
 * EarthquakeCodes::city() does not know (the whole of a designated city, a code lost to a merger) can never be matched
 * against earthquake information, so it is not offered and not accepted (design 3.1).
 *
 * @author shimomo
 */
final class Cities
{
    /**
     * @var array<string, CityChoice>|null
     */
    private static ?array $index = null;

    public static function find(string $code): ?CityChoice
    {
        return self::index()[$code] ?? null;
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function prefectures(): array
    {
        return array_map(
            static fn(Prefecture $prefecture): array => ['code' => $prefecture->code, 'name' => $prefecture->name],
            EarthquakeCodes::prefectures(),
        );
    }

    /**
     * Every prefecture with its municipalities, both in code order. A list rather than an array keyed by prefecture
     * code: PHP turns keys like "13" into integers.
     *
     * @return list<array{code: string, name: string, cities: list<array{code: string, name: string}>}>
     */
    public static function byPrefecture(): array
    {
        $cities = [];

        foreach (self::index() as $city) {
            $cities[$city->prefectureCode][] = ['code' => $city->code, 'name' => $city->name];
        }

        return array_map(
            static fn(array $prefecture): array => $prefecture + ['cities' => $cities[$prefecture['code']] ?? []],
            self::prefectures(),
        );
    }

    /**
     * Trims the code and puts back the leading zero that spreadsheets drop: "110100" (Hokkaido to Fukushima) becomes
     * "0110100". Anything else is returned as it is, to be refused by find().
     */
    public static function normalizeCode(string $value): string
    {
        $code = trim($value);

        return preg_match('/^[0-9]{6}$/', $code) === 1 ? '0' . $code : $code;
    }

    /**
     * @return array<string, CityChoice>
     */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];

        foreach (EarthquakeCodes::cities() as $city) {
            $area = EarthquakeCodes::area($city->areaCode);
            $prefecture = $area === null ? null : EarthquakeCodes::prefecture($area->prefectureCode);

            if ($prefecture !== null) {
                $index[$city->code] = new CityChoice($city->code, $city->name, $prefecture->code, $prefecture->name);
            }
        }

        return self::$index = $index;
    }
}
