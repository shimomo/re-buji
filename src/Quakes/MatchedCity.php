<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use JmaXml\Earthquake\CityMatch;
use JmaXml\Earthquake\MatchBasis;
use JmaXml\Earthquake\SeismicIntensity;
use ReBuji\Employees\Cities;
use ReBuji\Employees\EmployeeCity;

/**
 * One of a person's municipalities that reached the threshold, as the contact mail and the screens show it.
 *
 * @author shimomo
 */
final readonly class MatchedCity
{
    /**
     * The municipality's own intensity (震源・震度に関する情報).
     */
    public const string BASIS_CITY = 'city';

    /**
     * The intensity of its area, from a 震度速報.
     */
    public const string BASIS_AREA = 'area';

    /**
     * No intensity: a drill lists the registered municipalities.
     */
    public const string BASIS_NONE = 'none';

    /**
     * @param string|null $intensity a SeismicIntensity value such as '5-', or null when not received yet
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $place,
        public ?string $intensity,
        public bool $notYetReceived,
        public string $basis,
    ) {
        //
    }

    public static function fromMatch(CityMatch $match, EmployeeCity $city): self
    {
        return new self(
            $city->code,
            $city->label,
            Cities::find($city->code)?->label() ?? $city->code,
            $match->intensity?->value,
            $match->notYetReceived,
            $match->basis === MatchBasis::City ? self::BASIS_CITY : self::BASIS_AREA,
        );
    }

    /**
     * A registered municipality without an intensity, as a drill lists it.
     */
    public static function registered(EmployeeCity $city): self
    {
        return new self($city->code, $city->label, Cities::find($city->code)?->label() ?? $city->code, null, false, self::BASIS_NONE);
    }

    /**
     * The municipalities after a later judgement: each one judged again takes its newer reading, even a weaker one;
     * the ones the later telegram no longer names stay as they were, since the person was contacted for them; new
     * ones come last.
     *
     * @param list<self> $before
     * @param list<self> $now
     *
     * @return list<self>
     */
    public static function revise(array $before, array $now): array
    {
        $byCode = [];

        foreach ([...$before, ...$now] as $city) {
            $byCode[$city->code] = $city;
        }

        return array_values($byCode);
    }

    /**
     * The municipalities with each one raised to another quake's reading where that is stronger. As strong, the
     * municipality keeps its own reading (the contact's own quake), unless only the other says the observation is not
     * in yet: that one may turn out stronger, and the mail says so. The others' new ones come last.
     *
     * @param list<self> $cities
     * @param list<self> $others
     *
     * @return list<self>
     */
    public static function raise(array $cities, array $others): array
    {
        $byCode = [];

        foreach ($cities as $city) {
            $byCode[$city->code] = $city;
        }

        foreach ($others as $other) {
            $known = $byCode[$other->code] ?? null;

            $stronger = $known === null || $other->rank() > $known->rank();
            $mayBeStronger = $known !== null && $other->rank() === $known->rank() && $other->notYetReceived && !$known->notYetReceived;

            if ($stronger || $mayBeStronger) {
                $byCode[$other->code] = $other;
            }
        }

        return array_values($byCode);
    }

    /**
     * The strength of the reading, as two quakes' readings are compared (raise()): a municipality with no intensity yet
     * counts as 5弱, the least it may have been. Sending is ordered by priority() instead.
     */
    public function rank(): int
    {
        return SeismicIntensity::tryFrom($this->intensity ?? '')?->rank() ?? SeismicIntensity::FiveLower->rank();
    }

    /**
     * The strength used to order sending. A reading not in yet counts as at least 5弱: JMA marks a municipality so only
     * while its confirmed intensity is below 5弱, estimating that the points not in yet reached it, and those may be
     * the places hit hardest.
     */
    public function priority(): int
    {
        return $this->notYetReceived ? max($this->rank(), SeismicIntensity::FiveLower->rank()) : $this->rank();
    }

    /**
     * @return array{code: string, label: string, place: string, intensity: string|null, notYetReceived: bool, basis: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'place' => $this->place,
            'intensity' => $this->intensity,
            'notYetReceived' => $this->notYetReceived,
            'basis' => $this->basis,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['code'] ?? ''),
            (string) ($data['label'] ?? ''),
            (string) ($data['place'] ?? ''),
            self::nullableString($data['intensity'] ?? null),
            ($data['notYetReceived'] ?? false) === true,
            (string) ($data['basis'] ?? self::BASIS_NONE),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
