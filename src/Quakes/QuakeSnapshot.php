<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use DateTimeImmutable;
use JmaXml\Earthquake\EarthquakeEvent;

/**
 * What a quake record shows of the event as last rebuilt (design 5.6, item 1). It improves as telegrams arrive: a
 * 震度速報 has no hypocentre yet.
 *
 * @author shimomo
 */
final readonly class QuakeSnapshot
{
    public function __construct(
        public string $title,
        public ?string $hypocenter,
        public ?string $magnitude,
        public ?string $maxIntensity,
        public ?DateTimeImmutable $detectedAt,
        public ?string $basisTitle,
        public ?DateTimeImmutable $basisReportedAt,
    ) {
        //
    }

    /**
     * @param array{title: string, reportedAt: DateTimeImmutable}|null $basis the newest telegram of the event
     */
    public static function fromEvent(EarthquakeEvent $event, ?array $basis): self
    {
        $hypocenter = $event->hypocenter;
        $maxIntensity = $event->maxIntensity === null ? null : '最大' . $event->maxIntensity->label();
        $title = match (true) {
            $hypocenter !== null && $maxIntensity !== null => $hypocenter->name . '・' . $maxIntensity,
            $maxIntensity !== null => $maxIntensity,
            $hypocenter !== null => $hypocenter->name,
            default => '地震',
        };
        $magnitude = $hypocenter === null ? null : ($hypocenter->magnitude === null ? $hypocenter->magnitudeDescription : number_format($hypocenter->magnitude, 1));

        return new self(
            $title,
            $hypocenter?->name,
            $magnitude,
            $event->maxIntensity?->value,
            $event->detectedAt,
            $basis['title'] ?? null,
            $basis['reportedAt'] ?? null,
        );
    }
}
