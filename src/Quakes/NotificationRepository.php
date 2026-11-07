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
     * First contacts that may go now: the strongest shaking first, then in registration order (design 5.3).
     *
     * @return list<Notification>
     */
    public function dueFirstContacts(DateTimeImmutable $now): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM notifications WHERE state = ? AND (not_before IS NULL OR not_before <= ?) ORDER BY priority DESC, employee_id, id',
            [Notification::PENDING, Time::format($now)],
        ));
    }

    public function countWaiting(DateTimeImmutable $now): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM notifications WHERE state = ? AND (not_before IS NULL OR not_before <= ?)',
            [Notification::PENDING, Time::format($now)],
        );
    }

    /**
     * @return list<Notification>
     */
    public function dueCancelNotices(): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll('SELECT ' . self::COLUMNS . " FROM notifications WHERE cancel_notice = 'pending' ORDER BY id"));
    }

    /**
     * Contacts sent but not answered whose next resend is due.
     *
     * @return list<Notification>
     */
    public function dueResends(DateTimeImmutable $now): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM notifications WHERE state = ? AND replied_at IS NULL AND next_send_at IS NOT NULL AND next_send_at <= ? ORDER BY next_send_at, id',
            [Notification::SENT, Time::format($now)],
        ));
    }

    public function markFailed(int $id): void
    {
        $this->setState($id, Notification::FAILED);
    }

    public function markSkipped(int $id): void
    {
        $this->setState($id, Notification::SKIPPED);
    }

    public function markCancelNoticeSent(int $id): void
    {
        $this->db->execute("UPDATE notifications SET cancel_notice = 'sent', updated_at = ? WHERE id = ?", [Time::format($this->clock->now()), $id]);
    }

    public function stopResending(int $id): void
    {
        $this->db->execute('UPDATE notifications SET next_send_at = NULL, updated_at = ? WHERE id = ?', [Time::format($this->clock->now()), $id]);
    }

    /**
     * Contacts of other quakes the person has not answered yet, to fold a related quake into (design 4).
     *
     * @return list<Notification>
     */
    public function unansweredQuakeContactsOf(int $employeeId, int $exceptEarthquakeId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . self::prefixed('n') . ' FROM notifications n JOIN earthquakes e ON e.id = n.earthquake_id'
            . ' WHERE n.employee_id = ? AND n.earthquake_id <> ? AND e.kind = ? AND e.cancelled_at IS NULL AND n.replied_at IS NULL AND n.state IN (?, ?, ?) ORDER BY n.id',
            [$employeeId, $exceptEarthquakeId, Earthquake::KIND_QUAKE, Notification::PENDING, Notification::HELD, Notification::SENT],
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * The quake was cancelled (design 4): nothing more goes out for it, people already reached get a short notice, and
     * people folded into it get their own contact for the quake they were folded from.
     */
    public function cancelFor(int $earthquakeId): void
    {
        $now = Time::format($this->clock->now());
        $this->db->execute(
            'UPDATE notifications SET cancel_notice = ?, updated_at = ? WHERE earthquake_id = ? AND sent_count > 0 AND state IN (?, ?)'
            . ' AND EXISTS (SELECT 1 FROM messages m WHERE m.notification_id = notifications.id AND m.sent = 1)',
            ['pending', $now, $earthquakeId, Notification::SENT, Notification::FAILED],
        );
        $this->db->execute(
            'UPDATE notifications SET state = ?, next_send_at = NULL, updated_at = ? WHERE earthquake_id = ? AND state IN (?, ?, ?, ?)',
            [Notification::CANCELLED, $now, $earthquakeId, Notification::PENDING, Notification::HELD, Notification::SENT, Notification::FAILED],
        );
        $this->db->execute(
            'UPDATE notifications SET state = ?, merged_into_id = NULL, not_before = ?, updated_at = ? WHERE state = ? AND merged_into_id IN (SELECT id FROM (SELECT id FROM notifications WHERE earthquake_id = ?) AS cancelled)',
            [Notification::PENDING, $now, $now, Notification::MERGED, $earthquakeId],
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

    private function setState(int $id, string $state): void
    {
        $this->db->execute('UPDATE notifications SET state = ?, next_send_at = NULL, updated_at = ? WHERE id = ?', [$state, Time::format($this->clock->now()), $id]);
    }

    private static function prefixed(string $alias): string
    {
        return implode(', ', array_map(static fn(string $column): string => $alias . '.' . $column, explode(', ', self::COLUMNS)));
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
