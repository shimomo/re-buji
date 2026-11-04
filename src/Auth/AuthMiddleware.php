<?php

declare(strict_types=1);

namespace ReBuji\Auth;

use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReBuji\Http\Session;
use ReBuji\Http\Url;

/**
 * Lets only logged-in admins through and puts the admin (id, name, email) in the request attribute "admin".
 *
 * @author shimomo
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly AdminRepository $admins,
        private readonly ResponseFactoryInterface $responses,
        private readonly Url $url,
    ) {
        //
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = self::id($this->session->get('admin_id'));
        $admin = $id > 0 ? $this->admins->find($id) : null;

        if ($admin === null) {
            $this->session->remove('admin_id');

            return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/login'));
        }

        return $handler->handle($request->withAttribute('admin', ['id' => $admin['id'], 'name' => $admin['name'], 'email' => $admin['email']]));
    }

    private static function id(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
