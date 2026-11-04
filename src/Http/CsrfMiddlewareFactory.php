<?php

declare(strict_types=1);

namespace ReBuji\Http;

use League\Plates\Engine;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Csrf\Guard;

/**
 * slim/csrf's Guard, storing its tokens in our Session. One token per session (persistent mode), so several tabs
 * keep working.
 *
 * @author shimomo
 */
final class CsrfMiddlewareFactory
{
    public static function create(ResponseFactoryInterface $responses, Session $session, Engine $templates): Guard
    {
        /** @psalm-suppress UnsupportedReferenceUsage slim/csrf stores its tokens through this reference */
        $storage = &$session->reference('csrf');
        $failure = static function () use ($responses, $templates): ResponseInterface {
            $response = $responses->createResponse(400);
            $response->getBody()->write($templates->render('error', [
                'title' => 'もう一度お試しください',
                'message' => 'フォームの有効期限が切れたか、正しく送られませんでした。ページを開き直して、もう一度お試しください。',
            ]));

            return $response;
        };

        return new Guard($responses, 'csrf', $storage, $failure, 200, 16, true);
    }
}
