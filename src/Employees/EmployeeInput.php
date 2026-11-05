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
     * Trims every value, drops empty addresses and municipality rows without a code, and puts back the leading zero
     * a spreadsheet may have dropped from a code.
     *
     * @param list<string> $emails
     * @param list<array{code: string, label: string}> $cities
     */
    public static function from(string $name, string $department, array $emails, array $cities, string $memo, bool $active): self
    {
        $addresses = array_values(array_filter(array_map(trim(...), $emails), static fn(string $address): bool => $address !== ''));
        $places = [];

        foreach ($cities as $city) {
            $code = Cities::normalizeCode($city['code']);

            if ($code !== '') {
                $places[] = new EmployeeCity($code, trim($city['label']));
            }
        }

        return new self(trim($name), trim($department), $addresses, $places, trim($memo), $active);
    }
}
