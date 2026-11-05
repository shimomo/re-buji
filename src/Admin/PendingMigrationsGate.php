<?php

declare(strict_types=1);

namespace ReBuji\Admin;

use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReBuji\Database\Migrator;
use ReBuji\Http\Url;

/**
 * After a new version is uploaded over FTP, its code would meet the old tables until someone presses the update
 * button (design 6.4). Until then every admin page leads to /admin/database.
 *
 * @author shimomo
 */
final class PendingMigrationsGate implements MiddlewareInterface
{
    public function __construct(private readonly Migrator $migrator, private readonly ResponseFactoryInterface $responses, private readonly Url $url)
    {
        //
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->url->relative($request->getUri()->getPath()) === '/admin/database' || $this->migrator->pending() === []) {
            return $handler->handle($request);
        }

        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/admin/database'));
    }
}
