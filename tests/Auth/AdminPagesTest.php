<?php

declare(strict_types=1);

namespace ReBuji\Tests\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReBuji\Auth\AdminRepository;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;

/**
 * @author shimomo
 */
final class AdminPagesTest extends TestCase
{
    #[Test]
    public function listsAndAddsAdmins(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $response = $app->handle(TestApp::request('POST', '/admins', $this->hanako() + TestApp::csrf($container)));
            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/admins', $response->getHeaderLine('Location'));

            $list = (string) $app->handle(TestApp::request('GET', '/admins'))->getBody();
            $this->assertStringContainsString('安否 花子 さんを管理者に加えました。', $list);
            $this->assertStringContainsString(TestApp::ADMIN_EMAIL, $list);
            $this->assertStringContainsString('hanako@example.com', $list);

            // The new admin, in a browser of their own.
            [$other, $otherContainer] = TestApp::build($root);
            $this->assertSame('/', TestApp::logIn($other, $otherContainer, 'another-long-secret', 'hanako@example.com')->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesADuplicateEmailOrAShortPassword(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $duplicate = $app->handle(TestApp::request('POST', '/admins', ['email' => strtoupper(TestApp::ADMIN_EMAIL)] + $this->hanako() + TestApp::csrf($container)));
            $this->assertStringContainsString('このメールアドレスの管理者はすでにいます。', (string) $duplicate->getBody());

            $short = $app->handle(TestApp::request('POST', '/admins', ['password' => 'short-pass1', 'password_confirmation' => 'short-pass1'] + $this->hanako() + TestApp::csrf($container)));
            $this->assertStringContainsString('パスワードは 12 文字以上にしてください', (string) $short->getBody());

            $mismatch = $app->handle(TestApp::request('POST', '/admins', ['password_confirmation' => 'something-else-1'] + $this->hanako() + TestApp::csrf($container)));
            $this->assertStringContainsString('確認のパスワードが一致しません。', (string) $mismatch->getBody());

            $this->assertSame(1, $this->admins($container)->count());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function cannotDeleteOneself(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $self = TestApp::session($container)->get('admin_id');
            $this->assertIsInt($self);
            $response = $app->handle(TestApp::request('POST', '/admins/' . $self . '/delete', TestApp::csrf($container)));

            $this->assertStringContainsString('自分は削除できません。ほかの管理者に頼んでください。', (string) $app->handle(TestApp::request('GET', '/admins'))->getBody() . (string) $response->getBody());
            $this->assertSame(1, $this->admins($container)->count());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function deletingAnAdminEndsTheirSession(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $id = $this->admins($container)->create('安否 花子', 'hanako@example.com', 'another-long-secret');
            [$other, $otherContainer] = TestApp::build($root);
            TestApp::logIn($other, $otherContainer, 'another-long-secret', 'hanako@example.com');
            $this->assertSame(200, $other->handle(TestApp::request('GET', '/'))->getStatusCode());

            $response = $app->handle(TestApp::request('POST', '/admins/' . $id . '/delete', TestApp::csrf($container)));
            $this->assertSame('/admins', $response->getHeaderLine('Location'));
            $this->assertStringContainsString('安否 花子 さんを管理者から外しました。', (string) $app->handle(TestApp::request('GET', '/admins'))->getBody());

            $this->assertSame('/login', $other->handle(TestApp::request('GET', '/'))->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * @return array{App<ContainerInterface>, ContainerInterface, string}
     */
    private function loggedIn(): array
    {
        [$app, $container, $root] = TestApp::installed();
        TestApp::logIn($app, $container);

        return [$app, $container, $root];
    }

    private function admins(ContainerInterface $container): AdminRepository
    {
        $admins = $container->get(AdminRepository::class);
        $this->assertInstanceOf(AdminRepository::class, $admins);

        return $admins;
    }

    /**
     * @return array<string, string>
     */
    private function hanako(): array
    {
        return ['name' => '安否 花子', 'email' => 'hanako@example.com', 'password' => 'another-long-secret', 'password_confirmation' => 'another-long-secret'];
    }
}
