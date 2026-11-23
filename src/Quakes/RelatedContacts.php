<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use DateTimeImmutable;

/**
 * Design 4, quakes close in time: a person who has not answered a contact for a quake moments before, likely the
 * same shaking, gets no second mail; this quake is folded into that contact. A main shock some time later is a quake
 * of its own. The dispatch job folds as it judges, and releasing a late quake folds the same way.
 *
 * @author shimomo
 */
final class RelatedContacts
{
    /**
     * Seconds between detections within which another quake may be the same shaking (jmaxml/earthquake's default).
     */
    public const int WITHIN_SECONDS = 300;

    public function __construct(
        private readonly QuakeEvents $events,
        private readonly EarthquakeRepository $earthquakes,
        private readonly NotificationRepository $notifications,
    ) {
        //
    }

    /**
     * The id of the person's unanswered contact for a related quake, which their contact for this quake folds into,
     * or null.
     */
    public function target(int $employeeId, Earthquake $quake, DateTimeImmutable $now): ?int
    {
        $event = $quake->eventId === null ? null : $this->events->of($quake->eventId, $now);

        if ($event === null) {
            return null;
        }

        foreach ($this->notifications->unansweredQuakeContactsOf($employeeId, $quake->id) as $other) {
            $otherQuake = $this->earthquakes->find($other->earthquakeId);
            $otherEventId = $otherQuake?->eventId;

            if ($otherQuake === null || $otherEventId === null || abs($otherQuake->detectedAt->getTimestamp() - $quake->detectedAt->getTimestamp()) > self::WITHIN_SECONDS) {
                continue;
            }

            $otherEvent = $this->events->of($otherEventId, $now);

            if ($otherEvent !== null && $event->isPossiblyRelatedTo($otherEvent, self::WITHIN_SECONDS)) {
                return $other->mergedIntoId ?? $other->id;
            }
        }

        return null;
    }

    /**
     * The contact that goes out carries the folded quake's shaking where it is stronger (MatchedCity::raise()), and its
     * place in the queue if stronger.
     *
     * @param list<MatchedCity> $cities
     */
    public function fold(int $targetId, array $cities, int $priority): void
    {
        $target = $this->notifications->find($targetId);

        if ($target !== null) {
            $this->notifications->updateCities($target->id, MatchedCity::raise($target->cities, $cities), $priority);
        }
    }

    /**
     * A later judgement of the contact's quake. A contact other quakes are folded into keeps their shaking where it is
     * stronger than the newer reading of its own quake: a later telegram of its own must not hide a main shock folded
     * into it. A contact folded into another passes its shaking on to that one, the contact that goes out, where it is
     * stronger. Both use the folded quakes' current readings, as the stored municipalities do not say which quake each
     * reading came from: once a folded quake is read weaker, a shared municipality comes down to the strongest of the
     * current readings when the contact's own quake judges it again, and not before. A municipality only folded quakes
     * name is never judged by the contact's own quake, so it keeps the strongest reading it has had.
     *
     * @param list<MatchedCity> $cities
     */
    public function revise(Notification $contact, array $cities, int $priority): void
    {
        $revised = MatchedCity::revise($contact->cities, $cities);

        foreach ($this->notifications->foldedInto($contact->id) as $folded) {
            $revised = MatchedCity::raise($revised, $folded->cities);
        }

        $this->notifications->updateCities($contact->id, $revised, $priority);

        if ($contact->state === Notification::MERGED && $contact->mergedIntoId !== null) {
            $target = $this->notifications->find($contact->mergedIntoId);

            if ($target !== null) {
                $this->notifications->updateCities($target->id, MatchedCity::raise($target->cities, $revised), $priority);
            }
        }
    }

    /**
     * Before a late quake is released: folds each unanswered held contact into the person's contact for a related
     * quake, so releasing two related late quakes sends one mail.
     */
    public function foldHeld(Earthquake $quake, DateTimeImmutable $now): void
    {
        foreach ($this->notifications->forEarthquake($quake->id) as $contact) {
            if ($contact->state !== Notification::HELD || $contact->answer !== null || $contact->employeeId === null) {
                continue;
            }

            $target = $this->target($contact->employeeId, $quake, $now);

            if ($target !== null) {
                $this->fold($target, $contact->cities, $contact->priority);
                $this->notifications->markMerged($contact->id, $target);
            }
        }
    }
}
