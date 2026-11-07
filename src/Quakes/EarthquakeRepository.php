<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use ReBuji\Database\Database;
use ReBuji\Time;

/**
 * Earthquakes that reached someone's municipality, and drills.
 *
 * @author shimomo
 */
final class EarthquakeRepository
{
    private const string COLUMNS = 'id, kind, event_id, title, hypocenter, magnitude, max_intensity, detected_at, basis_title, basis_reported_at, threshold, delivery, late, cancelled_at';

    public function __construct(private readonly Database $db, private readonly ClockInterface $clock)
    {
        //
    }

    public function find(int $id): ?Earthquake
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM earthquakes WHERE id = ?', [$id]);

        return $row === null ? null : self::hydrate($row);
    }

    public function findByEventId(string $eventId): ?Earthquake
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM earthquakes WHERE event_id = ?', [$eventId]);

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @param string $threshold the SeismicIntensity value the quake is judged with from now on
     */
    public function createQuake(string $eventId, QuakeSnapshot $snapshot, string $threshold, string $delivery, bool $late, DateTimeImmutable $detectedAt): int
    {
        $now = Time::format($this->clock->now());
        $this->db->execute(
            'INSERT INTO earthquakes (kind, event_id, title, hypocenter, magnitude, max_intensity, detected_at, basis_title, basis_reported_at, threshold, delivery, late, cancelled_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)',
            [
                Earthquake::KIND_QUAKE,
                $eventId,
                $snapshot->title,
                $snapshot->hypocenter,
                $snapshot->magnitude,
                $snapshot->maxIntensity,
                Time::format($detectedAt),
                $snapshot->basisTitle,
                $snapshot->basisReportedAt === null ? null : Time::format($snapshot->basisReportedAt),
                $threshold,
                $delivery,
                $late ? 1 : 0,
                $now,
                $now,
            ],
        );

        return $this->db->lastInsertId();
    }

    /**
     * Refreshes what the record shows. The detection time stays: lateness and delays were decided from it.
     */
    public function updateSnapshot(int $id, QuakeSnapshot $snapshot): void
    {
        $this->db->execute(
            'UPDATE earthquakes SET title = ?, hypocenter = ?, magnitude = ?, max_intensity = ?, basis_title = ?, basis_reported_at = ?, updated_at = ? WHERE id = ?',
            [
                $snapshot->title,
                $snapshot->hypocenter,
                $snapshot->magnitude,
                $snapshot->maxIntensity,
                $snapshot->basisTitle,
                $snapshot->basisReportedAt === null ? null : Time::format($snapshot->basisReportedAt),
                Time::format($this->clock->now()),
                $id,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Earthquake
    {
        return new Earthquake(
            (int) $row['id'],
            (string) $row['kind'],
            $row['event_id'] === null ? null : (string) $row['event_id'],
            (string) $row['title'],
            $row['hypocenter'] === null ? null : (string) $row['hypocenter'],
            $row['magnitude'] === null ? null : (string) $row['magnitude'],
            $row['max_intensity'] === null ? null : (string) $row['max_intensity'],
            Time::parse((string) $row['detected_at']),
            $row['basis_title'] === null ? null : (string) $row['basis_title'],
            Time::parseNullable($row['basis_reported_at']),
            $row['threshold'] === null ? null : (string) $row['threshold'],
            (string) $row['delivery'],
            (int) $row['late'] === 1,
            Time::parseNullable($row['cancelled_at']),
        );
    }
}
