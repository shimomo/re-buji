<?php

declare(strict_types=1);

namespace ReBuji\Employees;

/**
 * An employee as entered, from the form or a CSV row. Not yet validated.
 *
 * @author shimomo
 */
final readonly class EmployeeInput
{
    /**
     * @param list<string> $emails
     * @param list<EmployeeCity> $cities
     */
    public function __construct(
        public string $name,
        public string $department,
        public array $emails,
        public array $cities,
        public string $memo,
        public bool $active,
    ) {
        //
    }

    /**
     * Trims every value, drops empty addresses and empty municipality rows, and puts back the leading zero a
     * spreadsheet may have dropped from a code. A row with a label or a prefecture (from the form) but no code is
     * kept with an empty code, so the validator reports it instead of the place being lost without a word.
     *
     * @param list<string> $emails
     * @param list<array{code: string, label: string, prefecture?: string}> $cities
     */
    public static function from(string $name, string $department, array $emails, array $cities, string $memo, bool $active): self
    {
        $addresses = array_values(array_filter(array_map(trim(...), $emails), static fn(string $address): bool => $address !== ''));
        $places = [];

        foreach ($cities as $city) {
            $code = Cities::normalizeCode($city['code']);
            $label = trim($city['label']);

            if ($code !== '' || $label !== '' || trim($city['prefecture'] ?? '') !== '') {
                $places[] = new EmployeeCity($code, $label);
            }
        }

        return new self(trim($name), trim($department), $addresses, $places, trim($memo), $active);
    }
}
