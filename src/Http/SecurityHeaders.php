<?php

declare(strict_types=1);

namespace ReBuji\Http;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Headers every response carries, error pages too. A header the response already has is kept.
 *
 * The policy leaves scripts alone: pages confirm with inline onsubmit handlers. no-store keeps pages with people's
 * names and addresses out of the browser's cache, so going back after logging out shows nothing (design 6.5).
 *
 * @author shimomo
 */
final class SecurityHeaders implements MiddlewareInterface
{
    public const array HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        'Referrer-Policy' => 'same-origin',
        'Cache-Control' => 'no-store',
    ];

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach (self::HEADERS as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
