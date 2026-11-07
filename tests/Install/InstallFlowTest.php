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
use ReBuji\Tests\Support\LoopbackHttpClient;
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
            $this->post($app, $container, '/install/mail', TestApp::mailSettings());
            TestApp::replyToTheTestMail($container);
            $this->post($app, $container, '/install/reply');
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
    public function completesSetupWhenOnlyPublicIsServed(): void
    {
        // Every probe reaches index.php, which must answer with the app's 404 rather than a redirect to the installer.
        $root = TestApp::root();
        [$app, $container] = TestApp::build($root, [ClientInterface::class => new LoopbackHttpClient($root)]);

        try {
            $this->reachExposure($app, $container, $root);
            $response = $this->post($app, $container, '/install/exposure', ['base_url' => 'https://example.com']);

            $this->assertSame('/install/finish', $response->getHeaderLine('Location'), (string) $response->getBody());
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
    public function refusesAUrlThatDoesNotReachThisCopy(): void
    {
        // Another server (or a wrong path) answers 404 to every file, which must not pass as protection.
        [$app, $container, $root] = TestApp::create([ClientInterface::class => new FakeHttpClient(404, [], false)]);

        try {
            $this->reachExposure($app, $container, $root);
            $response = $this->post($app, $container, '/install/exposure', ['base_url' => 'https://example.com']);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('この Re:無事 に届きませんでした（状態 404）', (string) $response->getBody());
            $this->assertSame('/install/exposure', $app->handle(TestApp::request('GET', '/install/finish'))->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function keepsTheHostOfTheInstaller(): void
    {
        $client = new FakeHttpClient();
        [$app, $container, $root] = TestApp::create([ClientInterface::class => $client]);

        try {
            $this->reachExposure($app, $container, $root);
            $response = $this->post($app, $container, '/install/exposure', ['base_url' => 'https://other.example.net']);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('ホスト名は、いま開いている example.com から変えられません', (string) $response->getBody());
            $this->assertSame([], array_filter($client->requested, static fn(string $url): bool => str_contains($url, 'other.example.net')));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function probesFilesWhereThePackageIsServed(): void
    {
        // Only public/ is served at the top, but the package itself sits in the document root under its own name.
        $root = TestApp::root();
        $package = 'https://example.com/' . basename($root);
        [$app, $container] = TestApp::build($root, [ClientInterface::class => new FakeHttpClient(403, [$package . '/storage/database.sqlite' => 200])]);

        try {
            $this->reachExposure($app, $container, $root);
            $body = ['base_url' => 'https://example.com'] + TestApp::csrf($container);
            $response = $app->handle(TestApp::request('POST', '/install/exposure', $body, ['DOCUMENT_ROOT' => dirname($root)]));

            $this->assertStringContainsString('ウェブから見えています：' . $package . '/storage/database.sqlite', (string) $response->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesAKeyThatCanBeReadFromTheWeb(): void
    {
        [$app, $container, $root] = TestApp::create([ClientInterface::class => new FakeHttpClient(403, ['https://example.com/storage/install-key.txt' => 200])]);

        try {
            $this->post($app, $container, '/install/environment');
            $key = $this->installKey($app, $root);
            $response = $this->post($app, $container, '/install/key', ['install_key' => $key]);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('storage/install-key.txt がウェブから読めるため', (string) $response->getBody());
            // Anyone could have read the old key, so it is replaced.
            $this->assertNotSame($key, trim((string) @file_get_contents($root . '/storage/install-key.txt')));
            $this->assertSame('/install/key', $app->handle(TestApp::request('GET', '/install/database'))->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function answersItsProbeOnlyWithTheStoredNonce(): void
    {
        [$app, , $root] = TestApp::create();

        try {
            file_put_contents($root . '/storage/install-probe.txt', str_repeat('ab', 16));
            $response = $app->handle(TestApp::request('GET', '/install/probe/' . str_repeat('ab', 16)));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(str_repeat('ab', 16), (string) $response->getBody());
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            $this->assertSame(404, $app->handle(TestApp::request('GET', '/install/probe/' . str_repeat('cd', 16)))->getStatusCode());
            unlink($root . '/storage/install-probe.txt');
            $this->assertSame(404, $app->handle(TestApp::request('GET', '/install/probe/' . str_repeat('ab', 16)))->getStatusCode());
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
        $this->post($app, $container, '/install/mail', TestApp::mailSettings());
        TestApp::replyToTheTestMail($container);
        $this->post($app, $container, '/install/reply');
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
