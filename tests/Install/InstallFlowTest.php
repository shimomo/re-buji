<?php

declare(strict_types=1);

namespace ReBuji\Tests\Install;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use ReBuji\Config\ConfigFile;
use ReBuji\Database\Database;
use ReBuji\Paths;
use ReBuji\Tests\Support\FakeHttpClient;
use ReBuji\Tests\Support\FakeNetworkException;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;

/**
 * Walks the installer step by step, as someone with FTP access would.
 *
 * @author shimomo
 */
final class InstallFlowTest extends TestCase
{
    #[Test]
    public function completesTheWholeFlowWithSqlite(): void
    {
        [$app, $container, $root] = TestApp::create();

        try {
            $this->post($app, $container, '/install/environment');
            $this->post($app, $container, '/install/key', ['install_key' => $this->installKey($app, $root)]);
            $this->post($app, $container, '/install/database', ['driver' => 'sqlite']);
            $this->post($app, $container, '/install/admin', $this->admin());
            $this->post($app, $container, '/install/exposure', ['base_url' => 'https://example.com']);
            $finish = $app->handle(TestApp::request('GET', '/install/finish'));
            $this->assertStringContainsString($root . '/bin/cron.php', (string) $finish->getBody());
            $this->assertStringContainsString('https://example.com/status/', (string) $finish->getBody());
            $done = $this->post($app, $container, '/install/finish');

            $this->assertSame(302, $done->getStatusCode());
            $this->assertSame('/login', $done->getHeaderLine('Location'));
            $config = (new ConfigFile($root . '/storage/config.php'))->load();
            $this->assertIsString($config->get('installed_at'));
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $config->get('status.token'));
            $this->assertStringContainsString('/status/' . (string) $config->get('status.token'), (string) $finish->getBody());
            $db = Database::fromConfig($config, new Paths($root));
            $this->assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM admins'));
            $this->assertFileDoesNotExist($root . '/storage/install-key.txt');
            $this->assertSame(404, $app->handle(TestApp::request('GET', '/install'))->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function cannotFinishWhileFilesAreExposed(): void
    {
        [$app, $container, $root] = TestApp::create([ClientInterface::class => new FakeHttpClient(403, ['https://example.com/storage/config.php' => 200])]);

        try {
            $this->reachExposure($app, $container, $root);
            $response = $this->post($app, $container, '/install/exposure', ['base_url' => 'https://example.com']);
            $body = (string) $response->getBody();

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('https://example.com/storage/config.php', $body);
            $this->assertStringContainsString('ウェブから見えています', $body);
            $next = $app->handle(TestApp::request('GET', '/install/finish'));
            $this->assertSame('/install/exposure', $next->getHeaderLine('Location'));
            $this->assertNull((new ConfigFile($root . '/storage/config.php'))->load()->get('installed_at'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function allowsAManualCheckWhenTheServerCannotReachItself(): void
    {
        [$app, $container, $root] = TestApp::create([ClientInterface::class => new FakeHttpClient(403, [
            'https://example.com/storage/config.php' => new FakeNetworkException('timed out'),
        ])]);

        try {
            $this->reachExposure($app, $container, $root);
            $refused = $this->post($app, $container, '/install/exposure', ['base_url' => 'https://example.com']);
            $this->assertStringContainsString('確かめられませんでした', (string) $refused->getBody());

            $accepted = $this->post($app, $container, '/install/exposure', ['base_url' => 'https://example.com', 'checked_by_hand' => '1']);
            $this->assertSame('/install/finish', $accepted->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function manualCheckCannotOverrideAnExposedFile(): void
    {
        [$app, $container, $root] = TestApp::create([ClientInterface::class => new FakeHttpClient(403, ['https://example.com/storage/database.sqlite' => 200])]);

        try {
            $this->reachExposure($app, $container, $root);
            $response = $this->post($app, $container, '/install/exposure', ['base_url' => 'https://example.com', 'checked_by_hand' => '1']);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('ウェブから見えています', (string) $response->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesAWrongInstallKey(): void
    {
        [$app, $container, $root] = TestApp::create();

        try {
            $this->post($app, $container, '/install/environment');
            $this->installKey($app, $root);
            $response = $this->post($app, $container, '/install/key', ['install_key' => 'not-the-key']);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('鍵が違います', (string) $response->getBody());
            $next = $app->handle(TestApp::request('GET', '/install/database'));
            $this->assertSame(302, $next->getStatusCode());
            $this->assertSame('/install/key', $next->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function cannotSkipAhead(): void
    {
        [$app, $container, $root] = TestApp::create();

        try {
            $this->post($app, $container, '/install/environment');
            $response = $this->post($app, $container, '/install/admin', $this->admin());

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/install/key', $response->getHeaderLine('Location'));
            $this->assertFileDoesNotExist($root . '/storage/config.php');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function reportsAnUnwritableStorage(): void
    {
        [$app, , $root] = TestApp::create();

        try {
            chmod($root . '/storage', 0500);
            $response = $app->handle(TestApp::request('GET', '/install/environment'));

            $this->assertStringContainsString('storage に書き込めません', (string) $response->getBody());
        } finally {
            chmod($root . '/storage', 0700);
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function warnsWithoutHttps(): void
    {
        [$app, , $root] = TestApp::create();

        try {
            $response = $app->handle(TestApp::request('GET', '/install/environment', [], ['HTTPS' => '']));
            $body = (string) $response->getBody();

            $this->assertStringContainsString('HTTPS で開いてください', $body);
            $this->assertStringContainsString('<button', $body);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function reportsADatabaseConnectionFailure(): void
    {
        [$app, $container, $root] = TestApp::create();

        try {
            $this->post($app, $container, '/install/environment');
            $this->post($app, $container, '/install/key', ['install_key' => $this->installKey($app, $root)]);
            $response = $this->post($app, $container, '/install/database', [
                'driver' => 'mysql',
                'mysql_host' => '127.0.0.1',
                'mysql_port' => '1',
                'mysql_database' => 'rebuji',
                'mysql_username' => 'rebuji',
                'mysql_password' => 'secret-password',
            ]);
            $body = (string) $response->getBody();

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('データベースに接続できません', $body);
            $this->assertStringNotContainsString('secret-password', $body);
            $this->assertFileDoesNotExist($root . '/storage/config.php');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * Opens the key step (which creates storage/install-key.txt) and reads the key the way the owner would over FTP.
     *
     * @param App<ContainerInterface> $app
     */
    private function installKey(App $app, string $root): string
    {
        $app->handle(TestApp::request('GET', '/install/key'));

        return trim((string) file_get_contents($root . '/storage/install-key.txt'));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function reachExposure(App $app, ContainerInterface $container, string $root): void
    {
        $this->post($app, $container, '/install/environment');
        $this->post($app, $container, '/install/key', ['install_key' => $this->installKey($app, $root)]);
        $this->post($app, $container, '/install/database', ['driver' => 'sqlite']);
        $this->post($app, $container, '/install/admin', $this->admin());
    }

    /**
     * @return array<string, string>
     */
    private function admin(): array
    {
        return ['name' => '安否 太郎', 'email' => 'admin@example.com', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'];
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, string> $body
     */
    private function post(App $app, ContainerInterface $container, string $path, array $body = []): ResponseInterface
    {
        return $app->handle(TestApp::request('POST', $path, $body + TestApp::csrf($container)));
    }
}
