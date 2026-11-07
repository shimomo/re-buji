<?php

declare(strict_types=1);

namespace ReBuji\Monitor;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Config\ConfigFile;
use ReBuji\Time;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /status/{token}, for external uptime monitors: 200 while cron runs, 503 when it is stale. A wrong token gets the
 * same 404 as a page that does not exist. The body never holds personal data.
 *
 * @author shimomo
 */
final class StatusController
{
    public function __construct(
        private readonly ConfigFile $configFile,
        private readonly Health $health,
        private readonly ResponseFactoryInterface $responses,
    ) {
        //
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->configFile->load()->get('status.token');

        if (!is_string($token) || $token === '' || !hash_equals($token, $args['token'] ?? '')) {
            throw new HttpNotFoundException($request);
        }

        $lastCheck = $this->health->lastCheckedAt();
        $stale = $this->health->isStale();
        $response = $this->responses->createResponse($stale ? 503 : 200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write((string) json_encode([
            'status' => $stale ? 'stale' : 'ok',
            'last_checked' => $lastCheck === null ? null : Time::format($lastCheck),
        ]));

        return $response;
    }
}
