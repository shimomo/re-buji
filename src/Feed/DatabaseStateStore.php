<?php

declare(strict_types=1);

namespace ReBuji\Feed;

use DateTimeImmutable;
use JmaXml\Feed\FeedState;
use JmaXml\Feed\StateStore;
use Override;
use ReBuji\Database\Database;
use ReBuji\Time;

/**
 * jmaxml/feed's state in the app's own database, so the poll and the telegrams it returns are saved in one transaction
 * (FeedJob). The seen entries and the feed states are kept and removed together, never by an evicting cache.
 *
 * @author shimomo
 */
final class DatabaseStateStore implements StateStore
{
    public function __construct(private readonly Database $db)
    {
        //
    }

    #[Override]
    public function isSeen(string $url): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM feed_seen WHERE url = ?', [$url]) !== null;
    }

    #[Override]
    public function markSeen(string $url, DateTimeImmutable $expiresAt): void
    {
        $expires = Time::format($expiresAt);

        if ($this->db->execute('UPDATE feed_seen SET expires_at = ? WHERE url = ?', [$expires, $url]) === 0) {
            $this->db->execute('INSERT INTO feed_seen (url, expires_at) VALUES (?, ?)', [$url, $expires]);
        }
    }

    #[Override]
    public function load(string $feedUrl): ?FeedState
    {
        $json = $this->db->fetchValue('SELECT state FROM feed_states WHERE feed_url = ?', [$feedUrl]);

        if ($json === null) {
            return null;
        }

        $data = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));
        /** @var array<string, mixed> $data */

        return FeedState::fromArray($data);
    }

    #[Override]
    public function save(string $feedUrl, FeedState $state): void
    {
        $json = json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if ($this->db->execute('UPDATE feed_states SET state = ? WHERE feed_url = ?', [$json, $feedUrl]) === 0) {
            $this->db->execute('INSERT INTO feed_states (feed_url, state) VALUES (?, ?)', [$feedUrl, $json]);
        }
    }

    /**
     * Forgets seen entries past their expiry (jmaxml/feed keeps them for 8 days, longer than the long-term feed).
     */
    public function purge(DateTimeImmutable $now): void
    {
        $this->db->execute('DELETE FROM feed_seen WHERE expires_at < ?', [Time::format($now)]);
    }
}
