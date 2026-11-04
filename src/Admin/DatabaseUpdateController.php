<?php

declare(strict_types=1);

namespace ReBuji\Admin;

use League\Plates\Engine;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Database\Migration;
use ReBuji\Database\Migrator;
use ReBuji\Version;

/**
 * /admin/database: after uploading a new version, applies its database changes with one button.
 *
 * @author shimomo
 */
final class DatabaseUpdateController
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly Engine $templates,
        private readonly ResponseFactoryInterface $responses,
    ) {
        //
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->render($request, null);
    }

    public function apply(ServerRequestInterface $request): ResponseInterface
    {
        return $this->render($request, $this->migrator->migrate());
    }

    /**
     * @param list<string>|null $applied
     */
    private function render(ServerRequestInterface $request, ?array $applied): ResponseInterface
    {
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('admin/database-update', [
            'title' => 'DB の更新',
            'admin' => $request->getAttribute('admin'),
            'version' => Version::CURRENT,
            'pending' => array_map(static fn(Migration $m): array => ['version' => $m->version(), 'description' => $m->description()], $this->migrator->pending()),
            'applied' => $applied,
        ]));

        return $response;
    }
}
