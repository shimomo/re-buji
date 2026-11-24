<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use Nyholm\Psr7\Response;
use Override;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReBuji\Install\WebSide;

/**
 * Answers every request with a fixed status, or a per-URL status or exception. Records requested URLs.
 *
 * Unless $echoProbe is false, the exposure check's own probe (/install/probe/{nonce}) is answered the way this copy
 * would answer it: 200 with the nonce, or with $webSide as JSON when asked ?from=console.
 *
 * @author shimomo
 */
final class FakeHttpClient implements ClientInterface
{
    /**
     * @var list<string>
     */
    public array $requested = [];

    /**
     * @param array<string, int|ClientExceptionInterface> $byUrl
     */
    public function __construct(
        private readonly int|ClientExceptionInterface $default = 403,
        private readonly array $byUrl = [],
        private readonly bool $echoProbe = true,
        private readonly ?WebSide $webSide = null,
    ) {
        //
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $this->requested[] = $url;

        if (!isset($this->byUrl[$url]) && $this->echoProbe && preg_match('#/install/probe/([0-9a-f]+)\?from=console$#', $url, $match) === 1) {
            return new Response(200, ['Content-Type' => 'application/json'], ($this->webSide ?? new WebSide(null, ['sqlite', 'mysql'], true))->answer($match[1]));
        }

        if (!isset($this->byUrl[$url]) && $this->echoProbe && preg_match('#/install/probe/([0-9a-f]+)$#', $url, $match) === 1) {
            return new Response(200, ['Content-Type' => 'text/plain'], $match[1]);
        }

        $answer = $this->byUrl[$url] ?? $this->default;

        if ($answer instanceof ClientExceptionInterface) {
            throw $answer;
        }

        return new Response($answer);
    }
}
