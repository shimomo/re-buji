<?php

declare(strict_types=1);

namespace ReBuji;

use League\Plates\Engine;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @author shimomo
 */
final class HomeController
{
    public function __construct(private readonly Engine $templates, private readonly ResponseFactoryInterface $responses)
    {
        //
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('home', ['title' => 'ホーム', 'admin' => $request->getAttribute('admin')]));

        return $response;
    }
}
