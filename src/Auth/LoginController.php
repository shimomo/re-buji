<?php

declare(strict_types=1);

namespace ReBuji\Auth;

use League\Plates\Engine;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Config\Environment;
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
    // and PHP 8.4 at cost 12, so there is one dummy per cost; the one used matches the strongest admin's hash
    // (dummyCost()), which stays at 12 when the server's PHP is moved back.
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
        private readonly Environment $environment,
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
        $valid = password_verify($password, $admin['password_hash'] ?? $this->dummyHash()) && $admin !== null;
        $this->throttle->record($email, $ip, $valid, $now);

        if (!$valid) {
            return $this->render($email, self::FAILED);
        }

        // The password is at hand only now: a hash weaker than what password_hash() makes today (another algorithm, or a
        // lower cost: 10 on PHP 8.3, 12 from 8.4) is made again, so a server's PHP upgrade strengthens each admin's hash
        // at their next log-in. A stronger one stays, so moving the server back to an older PHP does not weaken it.
        if (self::weakerThanDefault($admin['password_hash'])) {
            $this->admins->setPassword($admin['id'], $password);
        }

        $this->session->regenerate();
        $this->session->set('admin_id', $admin['id']);
        $this->session->set('admin_seen_at', $now->getTimestamp());

        return $this->redirect('/');
    }

    public function logout(): ResponseInterface
    {
        $this->session->clear();
        $this->session->regenerate();

        return $this->redirect('/login');
    }

    /**
     * The bcrypt cost of the dummy an unknown email is compared against: the strongest admin's hash that has a dummy, or
     * today's default when that is higher or no admin's hash has one.
     *
     * @param list<string> $hashes
     */
    public static function dummyCost(array $hashes, int $defaultCost): int
    {
        $cost = $defaultCost;

        foreach ($hashes as $hash) {
            $info = password_get_info($hash);
            $own = isset($info['options']['cost']) && is_int($info['options']['cost']) ? $info['options']['cost'] : 0;

            if ($info['algo'] === PASSWORD_BCRYPT && isset(self::DUMMY_HASHES[$own])) {
                $cost = max($cost, $own);
            }
        }

        return $cost;
    }

    private function dummyHash(): string
    {
        return self::DUMMY_HASHES[self::dummyCost($this->admins->passwordHashes(), self::defaultCost())] ?? self::DUMMY_HASHES[10];
    }

    /**
     * The bcrypt cost password_hash() uses today.
     */
    private static function defaultCost(): int
    {
        return defined('PASSWORD_BCRYPT_DEFAULT_COST') ? PASSWORD_BCRYPT_DEFAULT_COST : 10;
    }

    /**
     * Whether the hash is weaker than what password_hash() makes today: another algorithm, or bcrypt with a lower cost.
     */
    private static function weakerThanDefault(string $hash): bool
    {
        $info = password_get_info($hash);
        $cost = isset($info['options']['cost']) && is_int($info['options']['cost']) ? $info['options']['cost'] : 0;

        return $info['algo'] !== PASSWORD_DEFAULT || $cost < self::defaultCost();
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function render(string $email, ?string $error): ResponseInterface
    {
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('login', ['title' => 'ログイン', 'email' => $email, 'error' => $error, 'needsAdmin' => $this->environment->enabled() && $this->admins->count() === 0]));

        return $response;
    }

    private function redirect(string $path): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to($path));
    }
}
