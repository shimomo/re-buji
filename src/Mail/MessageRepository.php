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
     * @return list<string> one per mailbox, compared as the employees' addresses are
     */
    private function addresses(int $notificationId, bool $sent): array
    {
        $unique = [];

        foreach ($this->db->fetchAll('SELECT address FROM messages WHERE notification_id = ? AND sent = ? ORDER BY id', [$notificationId, $sent ? 1 : 0]) as $row) {
            $address = (string) $row['address'];
            $unique[EmployeeRepository::addressKey($address)] ??= $address;
        }

        return array_values($unique);
    }
}
