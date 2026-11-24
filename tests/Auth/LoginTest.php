<?php

declare(strict_types=1);

namespace ReBuji\Tests\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Auth\AdminRepository;
use ReBuji\Config\Config;
use ReBuji\Config\Environment;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Http\ArraySession;
use ReBuji\Http\Session;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;

/**
 * @author shimomo
 */
final class LoginTest extends TestCase
{
    #[Test]
    public function logsInWithTheRightPassword(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            $response = TestApp::logIn($app, $container);
            $session = $container->get(Session::class);
            $this->assertInstanceOf(ArraySession::class, $session);

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/', $response->getHeaderLine('Location'));
            $this->assertIsInt($session->get('admin_id'));
            $this->assertSame(1, $session->regenerated);

            $home = $app->handle(TestApp::request('GET', '/'));
            $this->assertSame(200, $home->getStatusCode());
            $this->assertStringContainsString('安否 太郎', (string) $home->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function logsOutAfterEightHoursWithoutARequest(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            TestApp::logIn($app, $container);
            $clock->advance('+8 hours +1 minute');
            $response = $app->handle(TestApp::request('GET', '/'));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/login', $response->getHeaderLine('Location'));
            $this->assertNull(TestApp::session($container)->get('admin_id'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function staysLoggedInWhileInUse(): void
    {
        $clock = new FrozenClock();
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => $clock]);

        try {
            TestApp::logIn($app, $container);

            for ($i = 0; $i < 3; $i++) {
                $clock->advance('+7 hours');
                $this->assertSame(200, $app->handle(TestApp::request('GET', '/'))->getStatusCode());
            }
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function acceptsTheEmailInAnyCase(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            $this->assertSame(302, TestApp::logIn($app, $container, TestApp::ADMIN_PASSWORD, ' Admin@Example.com ')->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function rejectsAWrongPasswordWithAGenericMessage(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            $wrongPassword = TestApp::logIn($app, $container, 'wrong-password-123');
            $unknownEmail = TestApp::logIn($app, $container, TestApp::ADMIN_PASSWORD, 'nobody@example.com');

            foreach ([$wrongPassword, $unknownEmail] as $response) {
                $this->assertSame(200, $response->getStatusCode());
                $this->assertStringContainsString('メールアドレスかパスワードが違います', (string) $response->getBody());
            }

            $this->assertNull(TestApp::session($container)->get('admin_id'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function strengthensAnOldHashAtLogIn(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            $weak = self::setTheHashCost($container, 4);

            $this->assertSame(302, TestApp::logIn($app, $container)->getStatusCode());

            $hash = self::hash($container);
            $this->assertNotSame($weak, $hash);
            $this->assertFalse(password_needs_rehash($hash, PASSWORD_DEFAULT));
            $this->assertTrue(password_verify(TestApp::ADMIN_PASSWORD, $hash));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function keepsAStrongerHashAtLogIn(): void
    {
        // A server moved back from PHP 8.4 (cost 12) to 8.3 (cost 10) must not weaken the hash.
        [$app, $container, $root] = TestApp::installed();

        try {
            $strong = self::setTheHashCost($container, PASSWORD_BCRYPT_DEFAULT_COST + 1);

            $this->assertSame(302, TestApp::logIn($app, $container)->getStatusCode());

            $this->assertSame($strong, self::hash($container));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function leavesTheHashAloneWhenTheLogInFails(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            $weak = self::setTheHashCost($container, 4);

            $this->assertSame(200, TestApp::logIn($app, $container, 'wrong-password-123')->getStatusCode());

            $this->assertSame($weak, self::hash($container));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function leavesACurrentHashAlone(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            $current = self::hash($container);

            $this->assertSame(302, TestApp::logIn($app, $container)->getStatusCode());

            $this->assertSame($current, self::hash($container));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function requiresLoginForTheHomePage(): void
    {
        [$app, , $root] = TestApp::installed();

        try {
            $response = $app->handle(TestApp::request('GET', '/'));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/login', $response->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function logsOut(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            TestApp::logIn($app, $container);
            $response = $app->handle(TestApp::request('POST', '/logout', TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertNull(TestApp::session($container)->get('admin_id'));
            $this->assertSame('/login', $app->handle(TestApp::request('GET', '/'))->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * Gives the admin a bcrypt hash of this cost. Returns the hash.
     */
    private static function setTheHashCost(ContainerInterface $container, int $cost): string
    {
        $hash = password_hash(TestApp::ADMIN_PASSWORD, PASSWORD_BCRYPT, ['cost' => $cost]);
        $db = $container->get(Database::class);
        assert($db instanceof Database);
        $db->execute('UPDATE admins SET password_hash = ? WHERE email = ?', [$hash, TestApp::ADMIN_EMAIL]);

        return $hash;
    }

    private static function hash(ContainerInterface $container): string
    {
        $admins = $container->get(AdminRepository::class);
        assert($admins instanceof AdminRepository);

        return $admins->findByEmail(TestApp::ADMIN_EMAIL)['password_hash'] ?? '';
    }

    #[Test]
    public function pointsToAdminCreateWhenDockerHasNoAdmin(): void
    {
        [$app, $root] = $this->docker(false);

        try {
            $this->assertStringContainsString(self::GUIDE, (string) $app->handle(TestApp::request('GET', '/login'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function staysQuietOnceDockerHasAnAdmin(): void
    {
        [$app, $root] = $this->docker(true);

        try {
            $this->assertStringNotContainsString(self::GUIDE, (string) $app->handle(TestApp::request('GET', '/login'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function staysQuietOutsideDocker(): void
    {
        [$app, , $root] = TestApp::create([], TestApp::installedConfig());

        try {
            $this->assertStringNotContainsString(self::GUIDE, (string) $app->handle(TestApp::request('GET', '/login'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    private const string GUIDE = 'まだ管理者がいません。サーバーで docker compose exec app php bin/console admin:create を流して作ってください。';

    /**
     * @return array{\Slim\App<ContainerInterface>, string}
     */
    private function docker(bool $withAdmin): array
    {
        [$app, $container, $root] = TestApp::create([Environment::class => new Environment(['REBUJI_ENV_CONFIG' => '1'])], new Config(['installed_at' => '2026-11-05T03:00:00Z']));
        $db = $container->get(Database::class);
        $clock = $container->get(ClockInterface::class);
        $this->assertInstanceOf(Database::class, $db);
        $this->assertInstanceOf(ClockInterface::class, $clock);
        (new Migrator($db, Migrations::all(), $clock))->migrate();

        if ($withAdmin) {
            (new AdminRepository($db, $clock))->create('安否 太郎', TestApp::ADMIN_EMAIL, TestApp::ADMIN_PASSWORD);
        }

        return [$app, $root];
    }
}
