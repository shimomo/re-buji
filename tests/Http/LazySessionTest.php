<?php

declare(strict_types=1);

namespace ReBuji\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;

/**
 * A session (a file in storage/sessions) starts only when a form needs a token or someone logs in, so bots scanning
 * the site leave nothing behind.
 *
 * @author shimomo
 */
final class LazySessionTest extends TestCase
{
    #[Test]
    public function requestsWithoutACookieStartNoSession(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            $this->assertSame(404, $app->handle(TestApp::request('GET', '/wp-login.php'))->getStatusCode());
            $this->assertSame(404, $app->handle(TestApp::request('POST', '/xmlrpc.php'))->getStatusCode());
            $this->assertSame(302, $app->handle(TestApp::request('GET', '/'))->getStatusCode());
            $this->assertSame(400, $app->handle(TestApp::request('POST', '/login', ['email' => 'a@example.com', 'password' => 'x']))->getStatusCode());

            $this->assertFalse(TestApp::session($container)->exists());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function aFormStartsTheSession(): void
    {
        [$app, $container, $root] = TestApp::installed();

        try {
            $this->assertStringContainsString('name="csrf_value"', (string) $app->handle(TestApp::request('GET', '/login'))->getBody());

            $this->assertTrue(TestApp::session($container)->exists());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
