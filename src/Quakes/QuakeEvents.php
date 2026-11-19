<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use DateTimeImmutable;
use JmaXml\Earthquake\EarthquakeEvent;
use Psr\Log\LoggerInterface;
use ReBuji\Feed\FailedTelegrams;
use ReBuji\Feed\TelegramRepository;

/**
 * Earthquakes rebuilt from their kept telegrams, once each until forget(): the dispatch job compares one quake with
 * others moments before, and so does releasing a late quake.
 *
 * @author shimomo
 */
final class QuakeEvents
{
    /**
     * By EventID: null when none of the telegrams can be read.
     *
     * @var array<string, EarthquakeEvent|null>
     */
    private array $events = [];

    public function __construct(
        private readonly TelegramRepository $telegrams,
        private readonly FailedTelegrams $failed,
        private readonly LoggerInterface $logger,
    ) {
        //
    }

    /**
     * The quake as its readable telegrams tell it, or null when none can be read. The ones that cannot are logged and
     * listed for the admins.
     */
    public function of(string $eventId, DateTimeImmutable $now): ?EarthquakeEvent
    {
        if (!array_key_exists($eventId, $this->events)) {
            $reports = $this->telegrams->reportsOf($eventId, function (string $url, string $title, string $reason) use ($eventId, $now): void {
                $this->logger->error('dispatch: cannot read a telegram of event ' . $eventId . ' (' . $url . '): ' . $reason);
                $this->failed->add(FailedTelegrams::READ, $title, $url, $reason, $now);
            });
            $this->events[$eventId] = $reports === [] ? null : EarthquakeEvent::fromReports($reports);
        }

        return $this->events[$eventId];
    }

    /**
     * Forgets what was rebuilt, so new telegrams are read next time.
     */
    public function forget(): void
    {
        $this->events = [];
    }
}
