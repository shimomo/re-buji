<?php

declare(strict_types=1);

namespace ReBuji\Install;

/**
 * @author shimomo
 */
final readonly class ExposureResult
{
    /**
     * @param list<string> $exposed URLs that answered with anything but 403 or 404
     * @param list<string> $unreachable URLs that could not be requested at all
     * @param array<string, int> $statuses the status each reachable URL answered with
     */
    public function __construct(public array $exposed, public array $unreachable, public array $statuses)
    {
        //
    }

    public function passed(): bool
    {
        return $this->exposed === [] && $this->unreachable === [];
    }
}
