<?php

declare(strict_types=1);

namespace ReBuji;

use League\Plates\Engine;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The "more" page: what does not fit in the tabs on a phone.
 *
 * @author shimomo
 */
final class MoreController
{
    public function __construct(
        private readonly Engine $templates,
        private readonly ResponseFactoryInterface $responses,
    ) {
        //
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('more', [
            'title' => 'その他',
            'admin' => $request->getAttribute('admin'),
        ]));

        return $response;
    }
}
