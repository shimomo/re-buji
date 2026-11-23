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
use ReBuji\Feed\TelegramRepository;
use ReBuji\Http\Flash;
use ReBuji\Http\Url;
use ReBuji\Quakes\Board\QuakeBoard;
use ReBuji\Replies\ReplyRepository;
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
        private readonly RelatedContacts $related,
        private readonly ReplyRepository $replies,
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
            'merged' => $summary['merged'],
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
            'heading' => QuakeHeading::heading($quake),
            'basis' => QuakeHeading::basis($quake),
            'board' => $this->board->build($quake),
            'unmatchedMails' => $this->replies->countUnmatchedForQuake($quake->id),
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
            $now = $this->clock->now();
            $this->earthquakes->setDelivery($quake->id, Earthquake::RELEASED);
            // Someone who has not answered a related quake's contact gets no second mail (design 4), as on time.
            $this->related->foldHeld($quake, $now);
            $this->notifications->release($quake->id, $now);
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
            // Contacts of other quakes folded into this one keep the answers their people gave here, and the words.
            foreach ($this->notifications->handOverAnswers($quake->id) as [$from, $to]) {
                $this->replies->copyShown($from, $to);
            }

            $this->earthquakes->delete($quake->id);

            if ($quake->eventId !== null) {
                $this->telegrams->forgetEvent($quake->eventId, $this->clock->now());
            }
        });
        $this->flash->put('地震の記録を消しました。');

        return $this->redirect();
    }

    /**
     * The late quake waiting for a decision, or 404: a quake decided already (or never held) cannot be decided again,
     * and one JMA cancelled has nothing left to send.
     *
     * @param array<string, string> $args
     */
    private function held(ServerRequestInterface $request, array $args): Earthquake
    {
        $quake = $this->earthquakes->find((int) ($args['id'] ?? '0'));

        if ($quake === null || $quake->delivery !== Earthquake::HELD || $quake->isCancelled()) {
            throw new HttpNotFoundException($request);
        }

        return $quake;
    }

    private function redirect(): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/earthquakes'));
    }
}
