<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Config\Config;
use ReBuji\Config\ConfigFile;
use ReBuji\Container;
use ReBuji\Http\AppFactory;
use ReBuji\Http\ArraySession;
use ReBuji\Http\Session;
use ReBuji\Paths;
use Slim\App;
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

        $container = Container::build($paths, $overrides + [Session::class => new ArraySession()]);

        return [AppFactory::create($container), $container, $root];
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
     * @param array<string, string> $body
     * @param array<string, string> $server
     */
    public static function request(string $method, string $path, array $body = [], array $server = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'https://example.com' . $path, $server + ['REMOTE_ADDR' => '192.0.2.1', 'HTTPS' => 'on']);

        return $body === [] ? $request : $request->withParsedBody($body);
    }
}
