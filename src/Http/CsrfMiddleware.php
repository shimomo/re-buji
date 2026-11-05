<?php

declare(strict_types=1);

namespace ReBuji\Http;

use League\Plates\Engine;
use Override;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Csrf\Guard;

/**
 * Builds the Guard only when there is a session to keep its token in. Without one, a form sent is refused and any
 * other request goes through untouched; a page with a form starts the session when it renders the token. So the
 * status URL (polled by monitors) and requests from bots never create a session file.
 *
 * @author shimomo
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const array UNSAFE_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

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

        $session = $this->container->get(Session::class);
        assert($session instanceof Session);

        if (!$session->exists()) {
            return in_array($request->getMethod(), self::UNSAFE_METHODS, true) ? $this->failure() : $handler->handle($request);
        }

        $guard = $this->container->get(Guard::class);
        assert($guard instanceof Guard);

        return $guard->process($request, $handler);
    }

    private function failure(): ResponseInterface
    {
        $responses = $this->container->get(ResponseFactoryInterface::class);
        $templates = $this->container->get(Engine::class);
        assert($responses instanceof ResponseFactoryInterface && $templates instanceof Engine);

        return CsrfMiddlewareFactory::failure($responses, $templates);
    }
}
