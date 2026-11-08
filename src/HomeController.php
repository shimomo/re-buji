<?php

declare(strict_types=1);

namespace ReBuji;

use DateTimeZone;
use League\Plates\Engine;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Config\ConfigFile;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Http\Url;
use ReBuji\Mail\RateLimit;
use ReBuji\Monitor\Health;
use ReBuji\Quakes\EarthquakeRepository;
use ReBuji\Quakes\NotificationRepository;
use ReBuji\Replies\ReplyReading;
use ReBuji\Replies\ReplyRepository;
use ReBuji\Settings\Settings;

/**
 * The home page: whether cron is alive and the status URL for monitors.
 *
 * @author shimomo
 */
final class HomeController
{
    public function __construct(
        private readonly Engine $templates,
        private readonly ResponseFactoryInterface $responses,
        private readonly Health $health,
        private readonly ConfigFile $configFile,
        private readonly Url $url,
        private readonly EmployeeRepository $employees,
        private readonly EarthquakeRepository $earthquakes,
        private readonly NotificationRepository $notifications,
        private readonly RateLimit $limit,
        private readonly Settings $settings,
        private readonly SystemState $state,
        private readonly ClockInterface $clock,
        private readonly ReplyRepository $replies,
        private readonly ReplyReading $reading,
    ) {
        //
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $lastCheck = $this->health->lastCheckedAt();
        $uri = $request->getUri();
        $port = $uri->getPort();
        $token = (string) $this->configFile->load()->get('status.token', '');

        $now = $this->clock->now();
        $tokyo = new DateTimeZone('Asia/Tokyo');
        $gapSince = (string) $this->state->get('feed.gap_since');
        $nextSlot = $this->limit->nextSlotAt($now);
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('home', [
            'heldQuakes' => $this->earthquakes->countHeld(),
            'unmatchedMails' => $this->replies->countUnmatched(),
            'smtpMissing' => $this->settings->smtp() === null,
            'imapMissing' => $this->settings->imap() === null,
            'imapError' => (string) $this->state->get('imap.last_error'),
            'imapStale' => $this->reading->staleWarning($now),
            'mailError' => (string) $this->state->get('mail.last_error'),
            'waiting' => $this->notifications->countWaiting($now),
            'nextSlot' => $nextSlot?->setTimezone($tokyo)->format('H:i'),
            'feedGap' => $gapSince === '' ? null : Time::parse($gapSince)->setTimezone($tokyo)->format('n月j日 G時i分'),
            'failedTelegrams' => self::failedTelegrams((string) $this->state->get('feed.failed_telegrams'), $tokyo),
            'title' => 'ホーム',
            'admin' => $request->getAttribute('admin'),
            'lastCheck' => $lastCheck?->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i'),
            'stale' => $this->health->isStale(),
            'lastError' => $this->health->lastError(),
            'employeeCounts' => $this->employees->counts(),
            'unknownCities' => $this->employees->unknownCities(),
            'statusUrl' => $uri->getScheme() . '://' . $uri->getHost() . ($port === null ? '' : ':' . $port) . $this->url->to('/status/' . $token),
        ]));

        return $response;
    }

    /**
     * @return list<array{title: string, at: string}>
     */
    private static function failedTelegrams(string $json, DateTimeZone $tokyo): array
    {
        return array_map(static function (array $entry) use ($tokyo): array {
            $at = self::text($entry['at'] ?? null);

            return ['title' => self::text($entry['title'] ?? null), 'at' => $at === '' ? '' : Time::parse($at)->setTimezone($tokyo)->format('n月j日 G時i分')];
        }, self::arraysIn(json_decode($json, true)));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function arraysIn(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
