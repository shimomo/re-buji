<?php

declare(strict_types=1);

namespace ReBuji\Monitor;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Config\ConfigFile;
use ReBuji\Mail\SendingAccount;
use ReBuji\Time;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /status/{token}, for external uptime monitors: 200 while contacts can go out, 503 when cron is stale or the
 * sending account has stayed unusable (SendingAccount::isLasting()). Mail cannot tell anyone that mail is broken; the
 * monitor's own mail can. A wrong token gets the same 404 as a page that does not exist. The body never holds personal
 * data, nor the mail server's words.
 *
 * @author shimomo
 */
final class StatusController
{
    public function __construct(
        private readonly ConfigFile $configFile,
        private readonly Health $health,
        private readonly ResponseFactoryInterface $responses,
        private readonly SendingAccount $account,
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
        $status = match (true) {
            $this->health->isStale() => 'stale',
            $this->account->isLasting() => 'mail',
            default => 'ok',
        };
        $response = $this->responses->createResponse($status === 'ok' ? 200 : 503)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write((string) json_encode([
            'status' => $status,
            'last_checked' => $lastCheck === null ? null : Time::format($lastCheck),
        ]));

        return $response;
    }
}
