<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use DateTimeImmutable;
use JmaXml\Earthquake\CityMatch;
use JmaXml\Earthquake\EarthquakeEvent;
use JmaXml\Earthquake\SeismicIntensity;
use Override;
use Psr\Log\LoggerInterface;
use ReBuji\Cron\CronJob;
use ReBuji\Database\Database;
use ReBuji\Employees\Employee;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Feed\TelegramRepository;
use ReBuji\Settings\Settings;
use RuntimeException;
use Throwable;

/**
 * Design 6.1 steps 2 and 3: rebuilds each earthquake that got new telegrams and decides who to contact (design 4).
 * Sending is the next job's.
 *
 * Each event is judged in its own transaction and only then marked processed, so an event that fails is judged
 * again next run.
 *
 * @author shimomo
 */
final class DispatchJob implements CronJob
{
    public function __construct(
        private readonly Database $db,
        private readonly TelegramRepository $telegrams,
        private readonly EarthquakeRepository $earthquakes,
        private readonly NotificationRepository $notifications,
        private readonly EmployeeRepository $employees,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {
        //
    }

    #[Override]
    public function name(): string
    {
        return 'dispatch';
    }

    #[Override]
    public function run(DateTimeImmutable $now): void
    {
        $failure = null;

        foreach ($this->telegrams->unprocessedEventIds() as $eventId) {
            try {
                $this->db->transaction(function () use ($eventId, $now): void {
                    $this->judge($eventId, $now);
                    $this->telegrams->markProcessed($eventId, $now);
                });
            } catch (Throwable $exception) {
                $this->logger->error('dispatch: cannot judge event ' . $eventId, ['exception' => $exception]);
                $failure ??= new RuntimeException('event ' . $eventId . ': ' . $exception->getMessage(), 0, $exception);
            }
        }

        $this->telegrams->prune($now);

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function judge(string $eventId, DateTimeImmutable $now): void
    {
        $event = EarthquakeEvent::fromReports($this->telegrams->reportsOf($eventId));
        $quake = $this->earthquakes->findByEventId($eventId);

        if (($quake !== null && $quake->isCancelled()) || $event->isCancelled()) {
            return;
        }

        // The threshold of the first judgement stays with the quake, so a later change of the setting neither adds
        // nor drops people halfway through.
        $threshold = SeismicIntensity::tryFrom($quake?->threshold ?? '') ?? $this->settings->threshold();
        $matched = $this->matchPeople($event, $threshold);

        if ($matched === [] && $quake === null) {
            return;
        }

        // Without a time, the telegram is treated as fresh: sending late beats not sending.
        $quakeTime = $event->detectedAt ?? $event->originTime ?? $now;
        $snapshot = QuakeSnapshot::fromEvent($event, $this->telegrams->latestOf($eventId));

        if ($quake === null) {
            $late = $now->getTimestamp() - $quakeTime->getTimestamp() > $this->settings->int('late_after_hours') * 3600;
            $id = $this->earthquakes->createQuake($eventId, $snapshot, $threshold->value, $late ? Earthquake::HELD : Earthquake::AUTO, $late, $quakeTime);
            $quake = $this->earthquakes->find($id) ?? throw new RuntimeException('The quake just created is gone: ' . $id);
        } else {
            $this->earthquakes->updateSnapshot($quake->id, $snapshot);
        }

        foreach ($matched as [$employee, $cities, $priority]) {
            $existing = $this->notifications->findFor($quake->id, $employee->id);

            // One contact per person and quake (design 4). Someone no longer matching keeps theirs: a 震度速報 judges by
            // area, which can overstate a city, but they were in the shaken area and missing them is the worse failure.
            if ($existing !== null) {
                $this->notifications->updateCities($existing->id, $cities, $priority);

                continue;
            }

            $state = match ($quake->delivery) {
                Earthquake::HELD => Notification::HELD,
                Earthquake::DISMISSED => Notification::SKIPPED,
                default => Notification::PENDING,
            };
            $notBefore = $quake->delivery === Earthquake::RELEASED
                ? $now
                : $quake->detectedAt->modify('+' . $this->settings->int('delay_minutes') . ' minutes');
            $this->notifications->create($quake->id, $employee, $cities, $priority, $state, $notBefore);
        }
    }

    /**
     * Active employees with at least one municipality at or above the threshold.
     *
     * @return list<array{Employee, list<MatchedCity>, int}>
     */
    private function matchPeople(EarthquakeEvent $event, SeismicIntensity $threshold): array
    {
        $people = array_values(array_filter($this->employees->all(), static fn(Employee $employee): bool => $employee->active));
        $codes = [];

        foreach ($people as $employee) {
            foreach ($employee->cities as $city) {
                $codes[$city->code] = true;
            }
        }

        $result = $event->citiesAtLeast($threshold, array_map(strval(...), array_keys($codes)));

        if ($result->unknownCityCodes !== []) {
            $this->logger->warning('dispatch: municipality codes the tables do not know: ' . implode(', ', $result->unknownCityCodes));
        }

        /** @var array<array-key, CityMatch> $byCode */
        $byCode = [];

        foreach ($result->matches as $match) {
            $byCode[$match->cityCode] = $match;
        }

        $matched = [];

        foreach ($people as $employee) {
            $cities = [];

            foreach ($employee->cities as $city) {
                if (isset($byCode[$city->code])) {
                    $cities[] = MatchedCity::fromMatch($byCode[$city->code], $city);
                }
            }

            if ($cities !== []) {
                $matched[] = [$employee, $cities, max(array_map(static fn(MatchedCity $city): int => $city->rank(), $cities))];
            }
        }

        return $matched;
    }
}
