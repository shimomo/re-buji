<?php

declare(strict_types=1);

namespace ReBuji\Feed;

use DateTimeImmutable;
use ReBuji\SystemState;
use ReBuji\Time;

/**
 * Telegrams the app could not use, listed on the home page and in the watch mail until an admin acknowledges them:
 * ones the feed gave up fetching, and ones that came in whole but cannot be read.
 *
 * @author shimomo
 */
final class FailedTelegrams
{
    public const int KEPT = 10;

    /**
     * Reasons are cut to this many characters: an exception message can carry a long piece of the telegram.
     */
    public const int REASON_LENGTH = 200;

    /**
     * The feed gave up fetching it.
     */
    public const string FETCH = 'fetch';

    /**
     * It came in, but cannot be read.
     */
    public const string READ = 'read';

    private const string STATE = 'feed.failed_telegrams';

    public function __construct(private readonly SystemState $state)
    {
        //
    }

    /**
     * A telegram already listed is not listed again: an unreadable one is read each time its quake is judged again.
     *
     * The reason comes from an exception message, which may carry bytes of the broken telegram. Invalid UTF-8 is
     * replaced rather than refused: an exception here would stop the judging of the run.
     */
    public function add(string $kind, string $title, string $url, string $reason, DateTimeImmutable $at): void
    {
        $all = self::entriesIn(json_decode((string) $this->state->get(self::STATE), true));
        $title = mb_scrub($title, 'UTF-8');
        $url = mb_scrub($url, 'UTF-8');
        $reason = mb_substr(mb_scrub($reason, 'UTF-8'), 0, self::REASON_LENGTH);

        foreach ($all as $entry) {
            if (($entry['url'] ?? null) === $url) {
                return;
            }
        }

        $all[] = ['kind' => $kind, 'title' => $title, 'url' => $url, 'reason' => $reason, 'at' => Time::format($at)];
        $this->state->set(self::STATE, json_encode(array_slice($all, -self::KEPT), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function entriesIn(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
