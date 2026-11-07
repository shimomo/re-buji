<?php

declare(strict_types=1);

namespace ReBuji\Feed;

use DateTimeImmutable;
use JmaXml\Feed\FeedClient;
use JmaXml\Feed\FeedType;
use JmaXml\Feed\PollResult;
use JmaXml\Feed\Subscription;
use Override;
use Psr\Log\LoggerInterface;
use ReBuji\Cron\CronJob;
use ReBuji\Database\Database;
use ReBuji\SystemState;
use ReBuji\Time;

/**
 * Design 6.1 step 1: polls the JMA feed for earthquake telegrams and keeps them.
 *
 * jmaxml/feed marks what it returns as seen, so the poll and the saving share one transaction: if saving fails or the
 * run is killed, the seen marks roll back too and the telegrams come again next run.
 *
 * @author shimomo
 */
final class FeedJob implements CronJob
{
    public const int FAILED_TELEGRAMS_KEPT = 10;

    public function __construct(
        private readonly FeedClient $client,
        private readonly Database $db,
        private readonly DatabaseStateStore $store,
        private readonly TelegramRepository $telegrams,
        private readonly SystemState $state,
        private readonly LoggerInterface $logger,
    ) {
        //
    }

    /**
     * 震度速報, 震源に関する情報 and 震源・震度に関する情報, in normal operation only: JMA's drill and test telegrams are
     * never kept, so they can never start a contact (design 4).
     */
    public static function subscription(): Subscription
    {
        return new Subscription(FeedType::EarthquakeVolcano, ['VXSE51', 'VXSE52', 'VXSE53']);
    }

    #[Override]
    public function name(): string
    {
        return 'feed';
    }

    #[Override]
    public function run(DateTimeImmutable $now): void
    {
        $result = $this->db->transaction(function () use ($now): PollResult {
            $result = $this->client->poll(self::subscription());

            foreach ($result->telegrams as $telegram) {
                $this->telegrams->store($telegram->entry->url, $telegram->xml, $now);
            }

            return $result;
        });

        $this->state->set('feed.last_checked_at', Time::format($now));
        $this->recordGap($result);
        $this->recordFailures($result, $now);

        foreach ($result->warnings as $warning) {
            $this->logger->warning('feed: ' . $warning);
        }

        $this->store->purge($now);
    }

    /**
     * A gap that cannot be filled stays on the home page until it is filled or an admin acknowledges it: after a pause
     * of six days or more, jmaxml/feed reports the gap only once.
     */
    private function recordGap(PollResult $result): void
    {
        if ($result->gap === null) {
            return;
        }

        if ($result->gap->covered) {
            $this->state->set('feed.gap_since', '');

            return;
        }

        $since = Time::format($result->gap->since);
        $known = (string) $this->state->get('feed.gap_since');
        $this->state->set('feed.gap_since', $known !== '' && $known < $since ? $known : $since);
        $this->logger->warning('feed: telegrams since ' . $since . ' may be missing');
    }

    private function recordFailures(PollResult $result, DateTimeImmutable $now): void
    {
        $failed = [];

        foreach ($result->failures as $failure) {
            if ($failure->willRetry) {
                $this->logger->info('feed: will retry ' . $failure->entry->url . ': ' . $failure->reason);

                continue;
            }

            $this->logger->error('feed: gave up on ' . $failure->entry->url . ': ' . $failure->reason);
            $failed[] = ['title' => $failure->entry->title, 'url' => $failure->entry->url, 'reason' => $failure->reason, 'at' => Time::format($now)];
        }

        if ($failed === []) {
            return;
        }

        $all = [...self::listOf(json_decode((string) $this->state->get('feed.failed_telegrams'), true)), ...$failed];
        $this->state->set('feed.failed_telegrams', json_encode(array_slice($all, -self::FAILED_TELEGRAMS_KEPT), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return list<mixed>
     */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
