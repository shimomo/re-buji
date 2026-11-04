<?php

declare(strict_types=1);

namespace ReBuji\Install;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Paths;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /install/probe/{nonce}: echoes the value the exposure check has just written, so the check knows its requests
 * reach this copy. Before setup only; the installed gate hides /install afterwards.
 *
 * @author shimomo
 */
final class ProbeController
{
    public function __construct(private readonly Paths $paths, private readonly ResponseFactoryInterface $responses)
    {
        //
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $file = $this->paths->storage(ExposureCheck::PROBE_FILE);
        $stored = is_file($file) ? trim((string) file_get_contents($file)) : '';

        if ($stored === '' || !hash_equals($stored, $args['nonce'] ?? '')) {
            throw new HttpNotFoundException($request);
        }

        $answer = $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
        $answer->getBody()->write($stored);

        return $answer;
    }
}
