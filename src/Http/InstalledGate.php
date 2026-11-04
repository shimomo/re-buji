<?php

declare(strict_types=1);

namespace ReBuji\Http;

use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReBuji\Config\ConfigFile;
use Slim\Exception\HttpNotFoundException;

/**
 * Before setup every page leads to the installer; after setup the installer is gone (404).
 *
 * @author shimomo
 */
final class InstalledGate implements MiddlewareInterface
{
    public function __construct(
        private readonly ConfigFile $configFile,
        private readonly Url $url,
        private readonly ResponseFactoryInterface $responses,
    ) {
        //
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $this->url->relative($request->getUri()->getPath());
        $isInstaller = $path === '/install' || str_starts_with($path, '/install/');
        $installed = $this->configFile->exists() && $this->configFile->load()->get('installed_at') !== null;

        if (!$installed && !$isInstaller) {
            return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/install'));
        }

        if ($installed && $isInstaller) {
            throw new HttpNotFoundException($request);
        }

        return $handler->handle($request);
    }
}
