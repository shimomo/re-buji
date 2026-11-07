<?php

declare(strict_types=1);

namespace ReBuji\Replies;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use ReBuji\Database\Database;
use ReBuji\Time;

/**
 * Mail read from the reply mailbox. A row with a contact is that person's reply (or auto-reply, or a bounce of a mail
 * to them); a row without one is mail that matched nobody, listed for the admins (design 5.2).
 *
 * @author shimomo
 */
final class ReplyRepository
{
    public const int SUBJECT_LENGTH = 255;

    public const int BODY_LENGTH = 4000;

    public function __construct(private readonly Database $db, private readonly ClockInterface $clock)
    {
        //
    }

    public function record(?int $notificationId, string $kind, ?string $matchedBy, string $fromAddress, string $subject, string $body, ?string $messageId, DateTimeImmutable $receivedAt): int
    {
        $this->db->execute(
            'INSERT INTO replies (notification_id, kind, matched_by, from_address, subject, body, message_id, received_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $notificationId,
                $kind,
                $matchedBy,
                mb_substr($fromAddress, 0, 255),
                mb_substr($subject, 0, self::SUBJECT_LENGTH),
                mb_substr($body, 0, self::BODY_LENGTH),
                $messageId,
                Time::format($receivedAt),
                Time::format($this->clock->now()),
            ],
        );

        return $this->db->lastInsertId();
    }

    /**
     * Whether this incoming mail was recorded already (read again after a run stopped before marking it seen).
     */
    public function knows(string $messageId): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM replies WHERE message_id = ?', [$messageId]) !== null;
    }

    /**
     * @return list<array{kind: string, body: string, receivedAt: DateTimeImmutable}> oldest first
     */
    public function forNotification(int $notificationId): array
    {
        return array_map(static fn(array $row): array => [
            'kind' => (string) $row['kind'],
            'body' => (string) $row['body'],
            'receivedAt' => Time::parse((string) $row['received_at']),
        ], $this->db->fetchAll('SELECT kind, body, received_at FROM replies WHERE notification_id = ? ORDER BY received_at, id', [$notificationId]));
    }

    /**
     * @return list<array{id: int, kind: string, fromAddress: string, subject: string, body: string, receivedAt: DateTimeImmutable}> newest first
     */
    public function unmatched(): array
    {
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'kind' => (string) $row['kind'],
            'fromAddress' => (string) $row['from_address'],
            'subject' => (string) $row['subject'],
            'body' => (string) $row['body'],
            'receivedAt' => Time::parse((string) $row['received_at']),
        ], $this->db->fetchAll('SELECT id, kind, from_address, subject, body, received_at FROM replies WHERE notification_id IS NULL ORDER BY received_at DESC, id DESC'));
    }

    public function countUnmatched(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM replies WHERE notification_id IS NULL');
    }

    /**
     * Deletes mail that matched nobody. A person's reply is part of their record and goes only with the quake.
     */
    public function deleteUnmatched(int $id): bool
    {
        return $this->db->execute('DELETE FROM replies WHERE id = ? AND notification_id IS NULL', [$id]) > 0;
    }
}
