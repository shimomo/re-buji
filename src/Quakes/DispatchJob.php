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
use ReBuji\Feed\FailedTelegrams;
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
    /**
     * Seconds between detections within which another quake may be the same shaking (jmaxml/earthquake's default).
     */
    public const int RELATED_WITHIN_SECONDS = 300;

    /**
     * Events rebuilt during this run, by EventID, to compare with related quakes: null when none of the telegrams can
     * be read.
     *
     * @var array<string, EarthquakeEvent|null>
     */
    private array $events = [];

    public function __construct(
        private readonly Database $db,
        private readonly TelegramRepository $telegrams,
        private readonly EarthquakeRepository $earthquakes,
        private readonly NotificationRepository $notifications,
        private readonly EmployeeRepository $employees,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
        private readonly FailedTelegrams $failed,
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
        $this->events = [];

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
        $event = $this->event($eventId, $now);

        // Nothing to judge until a telegram that can be read arrives; the ones that cannot are listed for the admins.
        if ($event === null) {
            return;
        }

        $quake = $this->earthquakes->findByEventId($eventId);

        // Once cancelled, a quake stays cancelled even if JMA issued it again: people were told no reply was needed.
        if ($quake !== null && $quake->isCancelled()) {
            return;
        }

        if ($event->isCancelled()) {
            if ($quake !== null) {
                $this->earthquakes->markCancelled($quake->id, $now);
                $this->notifications->cancelFor($quake->id);
            }

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
            $foldedInto = $state === Notification::PENDING ? $this->unansweredRelatedContact($employee, $quake, $event, $now) : null;

            if ($foldedInto !== null) {
                $state = Notification::MERGED;
                $target = $this->notifications->find($foldedInto);

                // The contact that goes out carries this quake's shaking too, and its place in the queue if stronger.
                if ($target !== null) {
                    $this->notifications->updateCities($target->id, MatchedCity::merge($target->cities, $cities), $priority);
                }
            }

            $this->notifications->create($quake->id, $employee, $cities, $priority, $state, $notBefore, $foldedInto);
        }
    }

    /**
     * A person who has not answered a contact for a quake moments before, likely the same shaking, gets no second
     * mail: this quake is folded into that contact (design 4). A main shock some time later is a quake of its own.
     */
    private function unansweredRelatedContact(Employee $employee, Earthquake $quake, EarthquakeEvent $event, DateTimeImmutable $now): ?int
    {
        foreach ($this->notifications->unansweredQuakeContactsOf($employee->id, $quake->id) as $other) {
            $otherQuake = $this->earthquakes->find($other->earthquakeId);
            $otherEventId = $otherQuake?->eventId;

            if ($otherQuake === null || $otherEventId === null || abs($otherQuake->detectedAt->getTimestamp() - $quake->detectedAt->getTimestamp()) > self::RELATED_WITHIN_SECONDS) {
                continue;
            }

            $otherEvent = $this->event($otherEventId, $now);

            if ($otherEvent !== null && $event->isPossiblyRelatedTo($otherEvent, self::RELATED_WITHIN_SECONDS)) {
                return $other->mergedIntoId ?? $other->id;
            }
        }

        return null;
    }

    private function event(string $eventId, DateTimeImmutable $now): ?EarthquakeEvent
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
