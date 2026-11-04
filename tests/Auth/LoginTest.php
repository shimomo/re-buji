<?php

declare(strict_types=1);

namespace ReBuji\Tests\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Http\ArraySession;
use ReBuji\Http\Session;
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
}
