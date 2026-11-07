<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use DateTimeZone;
use League\Plates\Engine;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Database\Database;
use ReBuji\Http\Flash;
use ReBuji\Http\Url;
use Slim\Exception\HttpNotFoundException;

/**
 * The list of quakes and drills (design 7), and the decision on a quake that arrived late (design 4).
 *
 * @author shimomo
 */
final class EarthquakesController
{
    public function __construct(
        private readonly EarthquakeRepository $earthquakes,
        private readonly NotificationRepository $notifications,
        private readonly Database $db,
        private readonly Engine $templates,
        private readonly ResponseFactoryInterface $responses,
        private readonly Url $url,
        private readonly Flash $flash,
        private readonly ClockInterface $clock,
    ) {
        //
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $tokyo = new DateTimeZone('Asia/Tokyo');
        $rows = array_map(static fn(array $summary): array => [
            'id' => $summary['earthquake']->id,
            'detected' => $summary['earthquake']->detectedAt->setTimezone($tokyo)->format('Y-m-d H:i'),
            'title' => $summary['earthquake']->title,
            'drill' => $summary['earthquake']->isDrill(),
            'cancelled' => $summary['earthquake']->isCancelled(),
            'held' => $summary['earthquake']->delivery === Earthquake::HELD,
            'total' => $summary['total'],
            'sent' => $summary['sent'],
            'waiting' => $summary['waiting'],
            'failed' => $summary['failed'],
        ], $this->earthquakes->summaries());

        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('earthquakes/index', [
            'title' => '地震',
            'admin' => $request->getAttribute('admin'),
            'rows' => $rows,
        ]));

        return $response;
    }

    /**
     * @param array<string, string> $args
     */
    public function release(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $quake = $this->held($request, $args);
        $this->db->transaction(function () use ($quake): void {
            $this->earthquakes->setDelivery($quake->id, Earthquake::RELEASED);
            $this->notifications->release($quake->id, $this->clock->now());
        });
        $this->flash->put('遅れて届いた地震の連絡を送ります。cron が順に送ります。');

        return $this->redirect();
    }

    /**
     * @param array<string, string> $args
     */
    public function dismiss(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $quake = $this->held($request, $args);
        $this->db->transaction(function () use ($quake): void {
            $this->earthquakes->setDelivery($quake->id, Earthquake::DISMISSED);
            $this->notifications->dismiss($quake->id);
        });
        $this->flash->put('遅れて届いた地震の連絡を送らないことにしました。');

        return $this->redirect();
    }

    /**
     * The late quake waiting for a decision, or 404: a quake decided already (or never held) cannot be decided again.
     *
     * @param array<string, string> $args
     */
    private function held(ServerRequestInterface $request, array $args): Earthquake
    {
        $quake = $this->earthquakes->find((int) ($args['id'] ?? '0'));

        if ($quake === null || $quake->delivery !== Earthquake::HELD) {
            throw new HttpNotFoundException($request);
        }

        return $quake;
    }

    private function redirect(): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/earthquakes'));
    }
}
