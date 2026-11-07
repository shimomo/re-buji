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
     * The strength used to order sending: a municipality not received yet counts as 5弱, the least it may have been.
     */
    public function rank(): int
    {
        return SeismicIntensity::tryFrom($this->intensity ?? '')?->rank() ?? SeismicIntensity::FiveLower->rank();
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
