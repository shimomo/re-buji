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
     * A drill (design 4): sent like a quake and shown with them, but never judged against telegrams.
     */
    public function createDrill(DateTimeImmutable $now): int
    {
        $at = Time::format($now);
        $this->db->execute(
            'INSERT INTO earthquakes (kind, event_id, title, detected_at, delivery, late, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, 0, ?, ?)',
            [Earthquake::KIND_DRILL, '訓練', $at, Earthquake::AUTO, Time::format($this->clock->now()), Time::format($this->clock->now())],
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
     * Every quake and drill, newest first, with how its contacts stand.
     *
     * @return list<array{earthquake: Earthquake, total: int, sent: int, waiting: int, failed: int, merged: int}>
     */
    public function summaries(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ','
            . ' (SELECT COUNT(*) FROM notifications n WHERE n.earthquake_id = earthquakes.id) AS total,'
            . ' (SELECT COUNT(*) FROM notifications n WHERE n.earthquake_id = earthquakes.id AND n.sent_count > 0) AS sent,'
            . " (SELECT COUNT(*) FROM notifications n WHERE n.earthquake_id = earthquakes.id AND n.state IN ('pending', 'held')) AS waiting,"
            . " (SELECT COUNT(*) FROM notifications n WHERE n.earthquake_id = earthquakes.id AND n.state = 'failed') AS failed,"
            . " (SELECT COUNT(*) FROM notifications n WHERE n.earthquake_id = earthquakes.id AND n.state = 'merged') AS merged"
            . ' FROM earthquakes ORDER BY detected_at DESC, id DESC',
        );

        return array_map(static fn(array $row): array => [
            'earthquake' => self::hydrate($row),
            'total' => (int) $row['total'],
            'sent' => (int) $row['sent'],
            'waiting' => (int) $row['waiting'],
            'failed' => (int) $row['failed'],
            'merged' => (int) $row['merged'],
        ], $rows);
    }

    public function countHeld(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM earthquakes WHERE delivery = ?', [Earthquake::HELD]);
    }

    public function setDelivery(int $id, string $delivery): void
    {
        $this->db->execute('UPDATE earthquakes SET delivery = ?, updated_at = ? WHERE id = ?', [$delivery, Time::format($this->clock->now()), $id]);
    }

    public function markCancelled(int $id, DateTimeImmutable $at): void
    {
        $this->db->execute('UPDATE earthquakes SET cancelled_at = ?, updated_at = ? WHERE id = ?', [Time::format($at), Time::format($this->clock->now()), $id]);
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
