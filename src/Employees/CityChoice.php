<?php

declare(strict_types=1);

namespace ReBuji\Employees;

/**
 * A municipality that earthquake information can be matched against, with its prefecture.
 *
 * @author shimomo
 */
final readonly class CityChoice
{
    public function __construct(
        public string $code,
        public string $name,
        public string $prefectureCode,
        public string $prefectureName,
    ) {
        //
    }

    /**
     * For people: "東京都 東京千代田区". JMA's names already carry a region ("東京", "札幌") but not the prefecture.
     */
    public function label(): string
    {
        return $this->prefectureName . ' ' . $this->name;
    }
}
