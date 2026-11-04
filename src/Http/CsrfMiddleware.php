<?php

declare(strict_types=1);

namespace ReBuji\Http;

use Override;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Csrf\Guard;

/**
 * Builds the Guard only when a request needs it, so the status URL (polled by monitors) never starts a session.
 *
 * @author shimomo
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ContainerInterface $container, private readonly Url $url)
    {
        //
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (str_starts_with($this->url->relative($request->getUri()->getPath()), '/status/')) {
            return $handler->handle($request);
        }

        $guard = $this->container->get(Guard::class);
        assert($guard instanceof Guard);

        return $guard->process($request, $handler);
    }
}
