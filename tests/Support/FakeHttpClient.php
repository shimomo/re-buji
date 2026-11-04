<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use Nyholm\Psr7\Response;
use Override;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers every request with a fixed status, or a per-URL status or exception. Records requested URLs.
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
    public function __construct(private readonly int $default = 403, private readonly array $byUrl = [])
    {
        //
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $this->requested[] = $url;
        $answer = $this->byUrl[$url] ?? $this->default;

        if ($answer instanceof ClientExceptionInterface) {
            throw $answer;
        }

        return new Response($answer);
    }
}
