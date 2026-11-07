<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use DateTimeImmutable;
use ReBuji\Database\Database;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Time;

/**
 * Every mail handed to the SMTP server, sent or refused: the hourly limit counts them, and replies refer to their
 * Message-IDs (plan 4).
 *
 * @author shimomo
 */
final class MessageRepository
{
    public function __construct(private readonly Database $db)
    {
        //
    }

    public function record(?int $notificationId, string $kind, string $address, string $messageId, bool $sent, string $error, DateTimeImmutable $at): void
    {
        $this->db->execute(
            'INSERT INTO messages (notification_id, kind, address, message_id, sent, error, attempted_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$notificationId, $kind, $address, $messageId, $sent ? 1 : 0, $error, Time::format($at)],
        );
    }

    /**
     * Mails attempted after the given time.
     */
    public function countSince(DateTimeImmutable $since): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM messages WHERE attempted_at > ?', [Time::format($since)]);
    }

    public function oldestSince(DateTimeImmutable $since): ?DateTimeImmutable
    {
        return Time::parseNullable($this->db->fetchValue('SELECT MIN(attempted_at) FROM messages WHERE attempted_at > ?', [Time::format($since)]));
    }

    /**
     * @return list<string>
     */
    public function sentAddresses(int $notificationId): array
    {
        return $this->addresses($notificationId, true);
    }

    /**
     * @return list<string>
     */
    public function refusedAddresses(int $notificationId): array
    {
        return $this->addresses($notificationId, false);
    }

    /**
     * @return array{id: int, notificationId: ?int, address: string}|null
     */
    public function findByMessageId(string $messageId): ?array
    {
        return self::reference($this->db->fetchOne('SELECT id, notification_id, address FROM messages WHERE message_id = ?', [$messageId]));
    }

    /**
     * The latest mail that went out to the address, for a bounce that does not say which mail it returns.
     *
     * @return array{id: int, notificationId: ?int, address: string}|null
     */
    public function latestSentTo(string $address): ?array
    {
        foreach ($this->db->fetchAll('SELECT id, notification_id, address FROM messages WHERE sent = 1 ORDER BY attempted_at DESC, id DESC') as $row) {
            if (EmployeeRepository::addressKey((string) $row['address']) === EmployeeRepository::addressKey($address)) {
                return self::reference($row);
            }
        }

        return null;
    }

    public function markBounced(int $id, string $reason, DateTimeImmutable $at): void
    {
        $this->db->execute('UPDATE messages SET bounced_at = ?, bounce_reason = ? WHERE id = ?', [Time::format($at), $reason, $id]);
    }

    /**
     * Addresses a mail of the contact went out to and did not come back from.
     *
     * @return list<string>
     */
    public function deliveredAddresses(int $notificationId): array
    {
        return self::unique($this->db->fetchAll('SELECT address FROM messages WHERE notification_id = ? AND sent = 1 AND bounced_at IS NULL ORDER BY id', [$notificationId]));
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return array{id: int, notificationId: ?int, address: string}|null
     */
    private static function reference(?array $row): ?array
    {
        return $row === null ? null : [
            'id' => (int) $row['id'],
            'notificationId' => $row['notification_id'] === null ? null : (int) $row['notification_id'],
            'address' => (string) $row['address'],
        ];
    }

    /**
     * @return list<string> one per mailbox, compared as the employees' addresses are
     */
    private function addresses(int $notificationId, bool $sent): array
    {
        // A mail that came back counts as refused: resends skip that address.
        $sql = $sent
            ? 'SELECT address FROM messages WHERE notification_id = ? AND sent = 1 ORDER BY id'
            : 'SELECT address FROM messages WHERE notification_id = ? AND (sent = 0 OR bounced_at IS NOT NULL) ORDER BY id';

        return self::unique($this->db->fetchAll($sql, [$notificationId]));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private static function unique(array $rows): array
    {
        $unique = [];

        foreach ($rows as $row) {
            $address = (string) $row['address'];
            $unique[EmployeeRepository::addressKey($address)] ??= $address;
        }

        return array_values($unique);
    }
}
