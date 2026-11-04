<?php

declare(strict_types=1);

namespace ReBuji\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\Csrf\Guard;

/**
 * @author shimomo
 */
final class CsrfTest extends TestCase
{
    #[Test]
    public function rejectsAPostWithoutAToken(): void
    {
        [$app, $container, $root] = TestApp::create([], TestApp::installedConfig());

        try {
            $app->post('/_test', fn(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $response);
            $rejected = $app->handle(TestApp::request('POST', '/_test', ['x' => '1']));

            $this->assertSame(400, $rejected->getStatusCode());
            $this->assertStringContainsString('もう一度お試しください', (string) $rejected->getBody());

            $guard = $container->get(Guard::class);
            $this->assertInstanceOf(Guard::class, $guard);
            $token = $guard->generateToken();
            $fields = [
                $guard->getTokenNameKey() => (string) $token[$guard->getTokenNameKey()],
                $guard->getTokenValueKey() => (string) $token[$guard->getTokenValueKey()],
            ];
            $accepted = $app->handle(TestApp::request('POST', '/_test', ['x' => '1'] + $fields));

            $this->assertSame(200, $accepted->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
