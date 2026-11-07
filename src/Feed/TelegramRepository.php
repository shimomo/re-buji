<?php

declare(strict_types=1);

namespace ReBuji\Feed;

use DateTimeImmutable;
use JmaXml\Envelope;
use JmaXml\Report;
use ReBuji\Database\Database;
use ReBuji\Time;

/**
 * Earthquake telegrams the feed returned. Events are rebuilt from them on every new telegram (jmaxml/earthquake keeps
 * no state), so they stay until the quake is deleted, or for 8 days when no contact was made.
 *
 * @author shimomo
 */
final class TelegramRepository
{
    public const int KEEP_DAYS = 8;

    public function __construct(private readonly Database $db)
    {
        //
    }

    public function store(string $url, string $xml, DateTimeImmutable $receivedAt): void
    {
        if ($this->db->fetchValue('SELECT 1 FROM telegrams WHERE url = ?', [$url]) !== null) {
            return;
        }

        $envelope = Envelope::fromString($xml);
        $this->db->execute(
            'INSERT INTO telegrams (url, event_id, status, title, info_type, reported_at, received_at, processed_at, xml) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)',
            [
                $url,
                $envelope->head->eventID,
                $envelope->control->status,
                $envelope->control->title,
                $envelope->head->infoType,
                Time::format($envelope->head->reportDateTime),
                Time::format($receivedAt),
                $xml,
            ],
        );
    }

    /**
     * @return list<string> in the order their first telegram arrived
     */
    public function unprocessedEventIds(): array
    {
        $rows = $this->db->fetchAll('SELECT event_id, MIN(id) AS first_id FROM telegrams WHERE processed_at IS NULL GROUP BY event_id ORDER BY first_id');

        return array_map(static fn(array $row): string => (string) $row['event_id'], $rows);
    }

    /**
     * @return list<Report>
     */
    public function reportsOf(string $eventId): array
    {
        $rows = $this->db->fetchAll('SELECT xml FROM telegrams WHERE event_id = ? ORDER BY id', [$eventId]);

        return array_map(static fn(array $row): Report => Report::fromString((string) $row['xml']), $rows);
    }

    /**
     * The newest telegram of the event: what the judgement was based on.
     *
     * @return array{title: string, reportedAt: DateTimeImmutable}|null
     */
    public function latestOf(string $eventId): ?array
    {
        $row = $this->db->fetchOne('SELECT title, reported_at FROM telegrams WHERE event_id = ? ORDER BY reported_at DESC, id DESC LIMIT 1', [$eventId]);

        return $row === null ? null : ['title' => (string) $row['title'], 'reportedAt' => Time::parse((string) $row['reported_at'])];
    }

    public function markProcessed(string $eventId, DateTimeImmutable $now): void
    {
        $this->db->execute('UPDATE telegrams SET processed_at = ? WHERE event_id = ? AND processed_at IS NULL', [Time::format($now), $eventId]);
    }

    /**
     * Deletes processed telegrams older than KEEP_DAYS whose event has no quake record.
     */
    public function prune(DateTimeImmutable $now): int
    {
        return $this->db->execute(
            'DELETE FROM telegrams WHERE processed_at IS NOT NULL AND received_at < ? AND event_id NOT IN (SELECT event_id FROM earthquakes WHERE event_id IS NOT NULL)',
            [Time::format($now->modify('-' . self::KEEP_DAYS . ' days'))],
        );
    }
}
