<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use ReBuji\Database\Database;
use ReBuji\Employees\Employee;
use ReBuji\Time;

/**
 * One contact per person and earthquake (or drill), with its sending state.
 *
 * @author shimomo
 */
final class NotificationRepository
{
    private const string COLUMNS = 'id, earthquake_id, employee_id, employee_name, employee_department, token, cities, priority, state, not_before, sent_count, first_sent_at, last_sent_at, next_send_at, replied_at, cancel_notice, merged_into_id';

    public function __construct(private readonly Database $db, private readonly ClockInterface $clock)
    {
        //
    }

    /**
     * @param list<MatchedCity> $cities
     */
    public function create(int $earthquakeId, Employee $employee, array $cities, int $priority, string $state, ?DateTimeImmutable $notBefore, ?int $mergedIntoId = null): int
    {
        $now = Time::format($this->clock->now());
        $this->db->execute(
            'INSERT INTO notifications (earthquake_id, employee_id, employee_name, employee_department, token, cities, priority, state, not_before, sent_count, merged_into_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)',
            [
                $earthquakeId,
                $employee->id,
                $employee->name,
                $employee->department,
                $this->newToken(),
                self::encodeCities($cities),
                $priority,
                $state,
                $notBefore === null ? null : Time::format($notBefore),
                $mergedIntoId,
                $now,
                $now,
            ],
        );

        return $this->db->lastInsertId();
    }

    public function find(int $id): ?Notification
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM notifications WHERE id = ?', [$id]);

        return $row === null ? null : self::hydrate($row);
    }

    public function findFor(int $earthquakeId, int $employeeId): ?Notification
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM notifications WHERE earthquake_id = ? AND employee_id = ?', [$earthquakeId, $employeeId]);

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @return list<Notification>
     */
    public function forEarthquake(int $earthquakeId): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll('SELECT ' . self::COLUMNS . ' FROM notifications WHERE earthquake_id = ? ORDER BY id', [$earthquakeId]));
    }

    /**
     * Shows the latest judgement. The priority never drops: it ordered a contact that may already be on its way.
     *
     * @param list<MatchedCity> $cities
     */
    public function updateCities(int $id, array $cities, int $priority): void
    {
        $this->db->execute(
            'UPDATE notifications SET cities = ?, priority = CASE WHEN priority > ? THEN priority ELSE ? END, updated_at = ? WHERE id = ?',
            [self::encodeCities($cities), $priority, $priority, Time::format($this->clock->now()), $id],
        );
    }

    /**
     * Records one more sending (the first, or a resend).
     */
    public function markSent(int $id, DateTimeImmutable $at, ?DateTimeImmutable $nextSendAt): void
    {
        $sentAt = Time::format($at);
        $this->db->execute(
            'UPDATE notifications SET state = ?, sent_count = sent_count + 1, first_sent_at = COALESCE(first_sent_at, ?), last_sent_at = ?, next_send_at = ?, updated_at = ? WHERE id = ?',
            [Notification::SENT, $sentAt, $sentAt, $nextSendAt === null ? null : Time::format($nextSendAt), Time::format($this->clock->now()), $id],
        );
    }

    /**
     * The number in the subject that replies are matched by (design 5.1): 8 hex digits, the same for every resend.
     */
    private function newToken(): string
    {
        do {
            $token = bin2hex(random_bytes(4));
        } while ($this->db->fetchValue('SELECT 1 FROM notifications WHERE token = ?', [$token]) !== null);

        return $token;
    }

    /**
     * @param list<MatchedCity> $cities
     */
    private static function encodeCities(array $cities): string
    {
        return json_encode(array_map(static fn(MatchedCity $city): array => $city->toArray(), $cities), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<MatchedCity>
     */
    private static function decodeCities(string $json): array
    {
        return array_map(MatchedCity::fromArray(...), self::arraysIn(json_decode($json, true)));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function arraysIn(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Notification
    {
        return new Notification(
            (int) $row['id'],
            (int) $row['earthquake_id'],
            $row['employee_id'] === null ? null : (int) $row['employee_id'],
            (string) $row['employee_name'],
            (string) $row['employee_department'],
            (string) $row['token'],
            self::decodeCities((string) $row['cities']),
            (int) $row['priority'],
            (string) $row['state'],
            Time::parseNullable($row['not_before']),
            (int) $row['sent_count'],
            Time::parseNullable($row['first_sent_at']),
            Time::parseNullable($row['last_sent_at']),
            Time::parseNullable($row['next_send_at']),
            Time::parseNullable($row['replied_at']),
            $row['cancel_notice'] === null ? null : (string) $row['cancel_notice'],
            $row['merged_into_id'] === null ? null : (int) $row['merged_into_id'],
        );
    }
}
