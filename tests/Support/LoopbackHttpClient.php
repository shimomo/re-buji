<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use Nyholm\Psr7\Response;
use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Sends each request into a new copy of the app on the same root, as a server with only public/ in its document root
 * does: every path reaches index.php. URLs in $served are answered by the web server itself (a file it serves or
 * refuses) and never reach the app.
 *
 * @author shimomo
 */
final class LoopbackHttpClient implements ClientInterface
{
    /**
     * @var list<string>
     */
    public array $requested = [];

    /**
     * @param array<string, int> $served
     */
    public function __construct(private readonly string $root, private readonly array $served = [])
    {
        //
    }

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $url = (string) $uri;
        $this->requested[] = $url;

        if (isset($this->served[$url])) {
            return new Response($this->served[$url]);
        }

        // A new container, so the request carries no session from the request that sent it.
        [$app] = TestApp::build($this->root);
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => $uri->getScheme() === 'https' ? 'on' : ''];

        return $app->handle((new ServerRequestFactory())->createServerRequest($request->getMethod(), $uri, $server));
    }
}
