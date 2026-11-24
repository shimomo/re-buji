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
 * reach this copy. With ?from=console it describes the web side as JSON, with ?from=browser it records the visit for
 * the console. Before setup only; the installed gate hides /install afterwards.
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

        $from = (string) ($request->getQueryParams()['from'] ?? '');

        if ($from === 'console') {
            return $this->reply('application/json', WebSide::detect($request->getServerParams(), $this->paths)->answer($stored));
        }

        if ($from === 'browser') {
            $recorded = (new ProbeVisit($this->paths))->record($stored, WebSide::detect($request->getServerParams(), $this->paths));

            return $this->reply('text/plain; charset=utf-8', $recorded
                ? "Re:無事 に届きました。SSH の画面に戻ってください。\n"
                : "Re:無事 に届きましたが、storage/ に書けないため、SSH の画面に伝えられませんでした。storage/ の権限を確かめてください。\n");
        }

        return $this->reply('text/plain; charset=utf-8', $stored);
    }

    private function reply(string $contentType, string $body): ResponseInterface
    {
        $answer = $this->responses->createResponse(200)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Cache-Control', 'no-store');
        $answer->getBody()->write($body);

        return $answer;
    }
}
