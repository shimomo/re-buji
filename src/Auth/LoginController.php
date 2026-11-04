<?php

declare(strict_types=1);

namespace ReBuji\Auth;

use League\Plates\Engine;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Http\Session;
use ReBuji\Http\Url;

/**
 * Login and logout. A wrong password and an unknown email get the same answer. A block says so: it does not depend on
 * whether the account exists, so it reveals nothing, and an admin told "wrong password" would keep retrying.
 *
 * @author shimomo
 */
final class LoginController
{
    private const string FAILED = 'メールアドレスかパスワードが違います';

    private const string BLOCKED = 'ログインに続けて失敗したため、' . LoginThrottle::WINDOW_MINUTES . ' 分ほど受け付けを止めています。時間をおいてから、もう一度お試しください。';

    // Compared against for unknown emails, so they take as long as a wrong password. PHP 8.3 hashes at bcrypt cost 10
    // and PHP 8.4 at cost 12, so there is one dummy per cost.
    private const array DUMMY_HASHES = [
        10 => '$2y$10$4Le9C6dZJZp/nbc/adhNL.C8Vc9dgAvFli32QqVHGFY4bEnlsQfzm',
        12 => '$2y$12$s8XiSL49q5/EMaaHe0/Yr.QcBRQLuedRMC.O8AwMkcGe26/4g3H86',
    ];

    public function __construct(
        private readonly AdminRepository $admins,
        private readonly LoginThrottle $throttle,
        private readonly Session $session,
        private readonly ClockInterface $clock,
        private readonly Engine $templates,
        private readonly ResponseFactoryInterface $responses,
        private readonly Url $url,
    ) {
        //
    }

    public function show(): ResponseInterface
    {
        if (is_int($this->session->get('admin_id'))) {
            return $this->redirect('/');
        }

        return $this->render('', null);
    }

    public function login(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $email = is_array($body) && isset($body['email']) && is_string($body['email']) ? trim($body['email']) : '';
        $password = is_array($body) && isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
        $ip = self::text($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $now = $this->clock->now();

        // Not recorded, so retrying during a block does not move its end.
        if ($this->throttle->isBlocked($email, $ip, $now)) {
            return $this->render($email, self::BLOCKED);
        }

        $admin = $this->admins->findByEmail($email);
        $valid = password_verify($password, $admin['password_hash'] ?? self::dummyHash()) && $admin !== null;
        $this->throttle->record($email, $ip, $valid, $now);

        if (!$valid) {
            return $this->render($email, self::FAILED);
        }

        $this->session->regenerate();
        $this->session->set('admin_id', $admin['id']);

        return $this->redirect('/');
    }

    public function logout(): ResponseInterface
    {
        $this->session->clear();
        $this->session->regenerate();

        return $this->redirect('/login');
    }

    private static function dummyHash(): string
    {
        $cost = defined('PASSWORD_BCRYPT_DEFAULT_COST') ? PASSWORD_BCRYPT_DEFAULT_COST : 10;

        return self::DUMMY_HASHES[$cost] ?? self::DUMMY_HASHES[10];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function render(string $email, ?string $error): ResponseInterface
    {
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('login', ['title' => 'ログイン', 'email' => $email, 'error' => $error]));

        return $response;
    }

    private function redirect(string $path): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to($path));
    }
}
