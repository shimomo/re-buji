<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Auth\AdminRepository;
use ReBuji\Config\Config;
use ReBuji\Config\ConfigFile;
use ReBuji\Container;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Http\AppFactory;
use ReBuji\Http\ArraySession;
use ReBuji\Http\Session;
use ReBuji\Paths;
use Slim\App;
use Slim\Csrf\Guard;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Builds the app on a throwaway root (storage in a temp dir, templates linked from the repository). Remove the root
 * with TemporaryDirectory::remove() in a finally block.
 *
 * @author shimomo
 */
final class TestApp
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{App<ContainerInterface>, ContainerInterface, string}
     */
    public static function create(array $overrides = [], ?Config $config = null): array
    {
        $root = TemporaryDirectory::create();
        mkdir($root . '/storage');
        symlink(dirname(__DIR__, 2) . '/templates', $root . '/templates');
        $paths = new Paths($root);

        if ($config !== null) {
            (new ConfigFile($paths->storage('config.php')))->save($config);
        }

        // Tests never reach the network: by default every self-check request is answered with 403 (protected).
        $container = Container::build($paths, $overrides + [Session::class => new ArraySession(), ClientInterface::class => new FakeHttpClient()]);

        return [AppFactory::create($container), $container, $root];
    }

    public const string ADMIN_EMAIL = 'admin@example.com';

    public const string ADMIN_PASSWORD = 'correct-horse-battery';

    /**
     * An installed copy: SQLite migrated and one admin (ADMIN_EMAIL / ADMIN_PASSWORD).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array{App<ContainerInterface>, ContainerInterface, string}
     */
    public static function installed(array $overrides = []): array
    {
        [$app, $container, $root] = self::create($overrides, self::installedConfig());
        $db = $container->get(Database::class);
        $clock = $container->get(ClockInterface::class);
        assert($db instanceof Database && $clock instanceof ClockInterface);
        // The real migrations, not the container's list, so a test can add one that stays pending.
        (new Migrator($db, Migrations::all(), $clock))->migrate();
        (new AdminRepository($db, $clock))->create('安否 太郎', self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        return [$app, $container, $root];
    }

    /**
     * Logs in through the form and returns the response.
     *
     * @param App<ContainerInterface> $app
     */
    public static function logIn(App $app, ContainerInterface $container, string $password = self::ADMIN_PASSWORD, string $email = self::ADMIN_EMAIL): ResponseInterface
    {
        return $app->handle(self::request('POST', '/login', ['email' => $email, 'password' => $password] + self::csrf($container)));
    }

    public static function session(ContainerInterface $container): ArraySession
    {
        $session = $container->get(Session::class);
        assert($session instanceof ArraySession);

        return $session;
    }

    /**
     * A config for an installed copy with SQLite in storage/.
     */
    public static function installedConfig(): Config
    {
        return new Config([
            'db.driver' => 'sqlite',
            'db.sqlite.path' => 'database.sqlite',
            'status.token' => str_repeat('a', 32),
            'installed_at' => '2026-11-05T03:00:00Z',
        ]);
    }

    /**
     * Valid CSRF fields for POST requests (one token per session).
     *
     * @return array<string, string>
     */
    public static function csrf(ContainerInterface $container): array
    {
        $guard = $container->get(Guard::class);
        assert($guard instanceof Guard);
        $token = $guard->generateToken();

        return [
            $guard->getTokenNameKey() => (string) $token[$guard->getTokenNameKey()],
            $guard->getTokenValueKey() => (string) $token[$guard->getTokenValueKey()],
        ];
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $server
     */
    public static function request(string $method, string $path, array $body = [], array $server = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'https://example.com' . $path, $server + ['REMOTE_ADDR' => '192.0.2.1', 'HTTPS' => 'on']);

        return $body === [] ? $request : $request->withParsedBody($body);
    }
}
