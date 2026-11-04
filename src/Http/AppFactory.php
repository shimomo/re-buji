<?php

declare(strict_types=1);

namespace ReBuji\Http;

use Psr\Container\ContainerInterface;
use ReBuji\Auth\AuthMiddleware;
use ReBuji\Auth\LoginController;
use ReBuji\HomeController;
use ReBuji\Install\InstallController;
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

        // Added last runs first: errors → installed gate → CSRF → routing.
        $app->addRoutingMiddleware();
        $gate = $container->get(InstalledGate::class);
        $errorHandler = $container->get(ErrorHandler::class);
        assert($gate instanceof InstalledGate && $errorHandler instanceof ErrorHandler);

        $app->add(new CsrfMiddleware($container, $url));
        $app->add($gate);
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

        // Added by class name, so it is built (and touches the database) only when a protected route runs.
        $app->group('', function (RouteCollectorProxyInterface $group): void {
            $group->get('/', [HomeController::class, 'show']);
        })->add(AuthMiddleware::class);

        $app->get('/install', [InstallController::class, 'index']);
        $app->get('/install/{step}', [InstallController::class, 'show']);
        $app->post('/install/{step}', [InstallController::class, 'submit']);
    }
}
