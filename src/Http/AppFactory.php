<?php

declare(strict_types=1);

namespace ReBuji\Http;

use League\Plates\Engine;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
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
        $templates = $container->get(Engine::class);
        assert($responses instanceof ResponseFactoryInterface && $url instanceof Url && $templates instanceof Engine);

        // Route closures are not static: Slim binds them to the container.
        // Replaced by the login-protected home page (Task 6).
        $app->get('/', fn(): ResponseInterface => $responses->createResponse(302)->withHeader('Location', $url->to('/login')));

        // Replaced by the installer (Task 4).
        $app->get('/install', function () use ($responses, $templates): ResponseInterface {
            $response = $responses->createResponse(200);
            $response->getBody()->write($templates->render('install/placeholder'));

            return $response;
        });
    }
}
