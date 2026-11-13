<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use DateTimeZone;
use JmaXml\Earthquake\SeismicIntensity;
use League\Plates\Engine;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Database\Database;
use ReBuji\Feed\TelegramRepository;
use ReBuji\Http\Flash;
use ReBuji\Http\Url;
use ReBuji\Quakes\Board\QuakeBoard;
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
        private readonly QuakeBoard $board,
        private readonly TelegramRepository $telegrams,
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
     * The manager screen of one quake (design 5.6).
     *
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $quake = $this->earthquakes->find((int) ($args['id'] ?? '0')) ?? throw new HttpNotFoundException($request);
        $page = $this->responses->createResponse(200);
        $page->getBody()->write($this->templates->render('earthquakes/show', [
            'title' => $quake->title,
            'admin' => $request->getAttribute('admin'),
            'quake' => $quake,
            'heading' => self::heading($quake),
            'basis' => self::basis($quake),
            'board' => $this->board->build($quake),
        ]));

        return $page;
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
     * Deletes an old quake's records (design 6.5): personal data is kept no longer than it is needed.
     *
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $quake = $this->earthquakes->find((int) ($args['id'] ?? '0')) ?? throw new HttpNotFoundException($request);
        $this->db->transaction(function () use ($quake): void {
            $this->earthquakes->delete($quake->id);

            if ($quake->eventId !== null) {
                $this->telegrams->forgetEvent($quake->eventId, $this->clock->now());
            }
        });
        $this->flash->put('地震の記録を消しました。');

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

    /**
     * "11月5日 3:12 ごろ　宮城県沖（M6.8）最大震度6弱", leaving out what is not known yet.
     */
    private static function heading(Earthquake $quake): string
    {
        $when = $quake->detectedAt->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('n月j日 G:i');

        if ($quake->isDrill()) {
            return $when . '　訓練';
        }

        $where = ($quake->hypocenter ?? '') . ($quake->magnitude === null ? '' : '（M' . $quake->magnitude . '）');
        $strongest = SeismicIntensity::tryFrom($quake->maxIntensity ?? '')?->label();

        return trim($when . ' ごろ　' . $where . ($strongest === null ? '' : '最大' . $strongest));
    }

    private static function basis(Earthquake $quake): ?string
    {
        if ($quake->isDrill()) {
            return null;
        }

        $threshold = SeismicIntensity::tryFrom($quake->threshold ?? '')?->label() ?? '基準の震度';
        $source = $quake->basisTitle === null ? '' : $quake->basisTitle
            . ($quake->basisReportedAt === null ? '' : '（' . $quake->basisReportedAt->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('G:i') . ' 発表）')
            . 'をもとに判定　｜　';

        return $source . '対象：' . $threshold . '以上の市町村に登録した人';
    }

    private function redirect(): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/earthquakes'));
    }
}
