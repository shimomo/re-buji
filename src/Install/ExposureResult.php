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
     * @param list<string> $unreachable URLs that could not be requested, or every URL when the probe did not come back
     * @param array<string, int> $statuses the status each reachable URL answered with
     * @param ?string $probeFailure why the URL did not reach this copy (then no file was judged), or null when it did
     */
    public function __construct(public array $exposed, public array $unreachable, public array $statuses, public ?string $probeFailure = null)
    {
        //
    }

    public function passed(): bool
    {
        return $this->probeFailure === null && $this->exposed === [] && $this->unreachable === [];
    }
}
