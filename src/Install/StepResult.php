<?php

declare(strict_types=1);

namespace ReBuji\Install;

/**
 * @author shimomo
 */
final readonly class StepResult
{
    /**
     * @param list<string> $errors
     */
    private function __construct(public array $errors)
    {
        //
    }

    public static function done(): self
    {
        return new self([]);
    }

    /**
     * @param list<string> $errors
     */
    public static function failed(array $errors): self
    {
        return new self($errors);
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }
}
