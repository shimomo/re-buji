<?php

declare(strict_types=1);

namespace ReBuji\Http;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use ReBuji\Install\InstallController;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

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
        $responses = $container->get(ResponseFactoryInterface::class);
        $url = $container->get(Url::class);
        assert($responses instanceof ResponseFactoryInterface && $url instanceof Url);

        // Route closures are not static: Slim binds them to the container.
        // Replaced by the login-protected home page (Task 6).
        $app->get('/', fn(): ResponseInterface => $responses->createResponse(302)->withHeader('Location', $url->to('/login')));

        $app->get('/install', [InstallController::class, 'index']);
        $app->get('/install/{step}', [InstallController::class, 'show']);
        $app->post('/install/{step}', [InstallController::class, 'submit']);
    }
}
