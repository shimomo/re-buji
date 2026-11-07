<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use DateTimeImmutable;

/**
 * An earthquake that reached someone's municipality, or a drill: what contacts are made for.
 *
 * @author shimomo
 */
final readonly class Earthquake
{
    public const string KIND_QUAKE = 'quake';

    public const string KIND_DRILL = 'drill';

    /**
     * Contacts go out on their own.
     */
    public const string AUTO = 'auto';

    /**
     * Arrived later than late_after_hours: waits for an admin to decide (design 4).
     */
    public const string HELD = 'held';

    /**
     * Arrived late, and an admin chose to send.
     */
    public const string RELEASED = 'released';

    /**
     * Arrived late, and an admin chose not to send.
     */
    public const string DISMISSED = 'dismissed';

    public function __construct(
        public int $id,
        public string $kind,
        public ?string $eventId,
        public string $title,
        public ?string $hypocenter,
        public ?string $magnitude,
        public ?string $maxIntensity,
        public DateTimeImmutable $detectedAt,
        public ?string $basisTitle,
        public ?DateTimeImmutable $basisReportedAt,
        public ?string $threshold,
        public string $delivery,
        public bool $late,
        public ?DateTimeImmutable $cancelledAt,
    ) {
        //
    }

    public function isDrill(): bool
    {
        return $this->kind === self::KIND_DRILL;
    }

    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null;
    }
}
