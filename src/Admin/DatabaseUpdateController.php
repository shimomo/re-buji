<?php

declare(strict_types=1);

namespace ReBuji\Admin;

use League\Plates\Engine;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Database\Migration;
use ReBuji\Database\MigrationInProgress;
use ReBuji\Database\Migrator;
use ReBuji\Monitor\Health;
use ReBuji\SystemState;
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
        private readonly SystemState $state,
    ) {
        //
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->render($request, null, false);
    }

    /**
     * A second press while the first is still applying (a double click) is told to wait, not answered with an error.
     */
    public function apply(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $applied = $this->migrator->migrate();
        } catch (MigrationInProgress) {
            return $this->render($request, null, true);
        }

        // Cron's notice that it waits for this button would otherwise stay on the home page until its next run.
        if ($this->migrator->pending() === [] && $this->state->get('cron.last_error') === Health::WAITING_FOR_UPDATE) {
            $this->state->set('cron.last_error', '');
        }

        return $this->render($request, $applied, false);
    }

    /**
     * @param list<string>|null $applied
     */
    private function render(ServerRequestInterface $request, ?array $applied, bool $busy): ResponseInterface
    {
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('admin/database-update', [
            'title' => 'DB の更新',
            'admin' => $request->getAttribute('admin'),
            'version' => Version::CURRENT,
            // While another update runs, its half-done list would only invite another press.
            'pending' => $busy ? [] : array_map(static fn(Migration $m): array => ['version' => $m->version(), 'description' => $m->description()], $this->migrator->pending()),
            'applied' => $applied,
            'busy' => $busy,
        ]));

        return $response;
    }
}
