<?php

declare(strict_types=1);

namespace ReBuji\Auth;

use Override;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReBuji\Http\Session;
use ReBuji\Http\Url;

/**
 * Lets only logged-in admins through and puts the admin (id, name, email) in the request attribute "admin". An admin
 * with no request for Session::IDLE_SECONDS is logged out.
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
        private readonly ClockInterface $clock,
    ) {
        //
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = self::int($this->session->get('admin_id'));
        $now = $this->clock->now()->getTimestamp();
        $seenAt = self::int($this->session->get('admin_seen_at'));

        if ($id > 0 && $seenAt > 0 && $now - $seenAt > Session::IDLE_SECONDS) {
            $this->session->clear();
            $this->session->regenerate();

            return $this->toLogin();
        }

        $admin = $id > 0 ? $this->admins->find($id) : null;

        if ($admin === null) {
            $this->session->remove('admin_id');

            return $this->toLogin();
        }

        $this->session->set('admin_seen_at', $now);

        return $handler->handle($request->withAttribute('admin', ['id' => $admin['id'], 'name' => $admin['name'], 'email' => $admin['email']]));
    }

    private function toLogin(): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/login'));
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
