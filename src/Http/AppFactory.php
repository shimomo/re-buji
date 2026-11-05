<?php

declare(strict_types=1);

namespace ReBuji\Http;

use Psr\Container\ContainerInterface;
use ReBuji\Admin\DatabaseUpdateController;
use ReBuji\Admin\PendingMigrationsGate;
use ReBuji\Auth\AuthMiddleware;
use ReBuji\Auth\LoginController;
use ReBuji\Employees\EmployeesController;
use ReBuji\HomeController;
use ReBuji\Install\InstallController;
use ReBuji\Install\ProbeController;
use ReBuji\Monitor\StatusController;
use ReBuji\Monitor\StatusTokenController;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Interfaces\RouteCollectorProxyInterface;

/**
 * @author shimomo
 */
final class AppFactory
{
    /**
     * @return App<ContainerInterface>
     */
    public static function create(ContainerInterface $container): App
    {
        $app = SlimAppFactory::createFromContainer($container);
        $url = $container->get(Url::class);
        assert($url instanceof Url);
        $app->setBasePath($url->basePath());

        self::routes($app, $container);

        // Added last runs first: errors → routing → installed gate → CSRF. Routing comes before the gate, so an unknown
        // path is a 404 even before setup; the exposure check relies on it when only public/ is served.
        $gate = $container->get(InstalledGate::class);
        $errorHandler = $container->get(ErrorHandler::class);
        assert($gate instanceof InstalledGate && $errorHandler instanceof ErrorHandler);

        $app->add(new CsrfMiddleware($container, $url));
        $app->add($gate);
        $app->addRoutingMiddleware();
        $errors = $app->addErrorMiddleware(false, false, false);
        $errors->setDefaultErrorHandler($errorHandler);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    public static function routes(App $app, ContainerInterface $container): void
    {
        $app->get('/login', [LoginController::class, 'show']);
        $app->post('/login', [LoginController::class, 'login']);
        $app->post('/logout', [LoginController::class, 'logout']);

        // Added by class name, so they are built (and touch the database) only when a protected route runs. Added last
        // runs first: login, then the pending-migrations gate.
        $app->group('', function (RouteCollectorProxyInterface $group): void {
            $group->get('/', [HomeController::class, 'show']);
            $group->post('/settings/status-token', [StatusTokenController::class, 'regenerate']);
            $group->get('/admin/database', [DatabaseUpdateController::class, 'show']);
            $group->post('/admin/database', [DatabaseUpdateController::class, 'apply']);
            $group->get('/employees', [EmployeesController::class, 'index']);
            $group->get('/employees/new', [EmployeesController::class, 'create']);
            $group->post('/employees', [EmployeesController::class, 'store']);
            $group->get('/employees/{id:[0-9]+}', [EmployeesController::class, 'edit']);
            $group->post('/employees/{id:[0-9]+}', [EmployeesController::class, 'update']);
            $group->post('/employees/{id:[0-9]+}/delete', [EmployeesController::class, 'delete']);
        })->add(PendingMigrationsGate::class)->add(AuthMiddleware::class);

        $app->get('/status/{token}', [StatusController::class, 'show']);

        $app->get('/install', [InstallController::class, 'index']);
        $app->get('/install/probe/{nonce}', [ProbeController::class, 'show']);
        $app->get('/install/{step}', [InstallController::class, 'show']);
        $app->post('/install/{step}', [InstallController::class, 'submit']);
    }
}
