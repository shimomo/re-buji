<?php

declare(strict_types=1);

namespace ReBuji\Tests\Http;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReBuji\Http\SecurityHeaders;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * @author shimomo
 */
final class SecurityHeadersTest extends TestCase
{
    #[Test]
    public function setsTheHeadersOnEveryPage(): void
    {
        [$app, , $root] = TestApp::installed();

        try {
            $page = $app->handle(TestApp::request('GET', '/login'));
            $missing = $app->handle(TestApp::request('GET', '/no-such-page'));

            $this->assertSame(404, $missing->getStatusCode());

            foreach (SecurityHeaders::HEADERS as $name => $value) {
                $this->assertSame($value, $page->getHeaderLine($name), $name);
                $this->assertSame($value, $missing->getHeaderLine($name), $name);
            }
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function keepsAHeaderTheResponseAlreadyHas(): void
    {
        $handler = new class () implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200)->withHeader('Cache-Control', 'private, max-age=60');
            }
        };

        $response = (new SecurityHeaders())->process(TestApp::request('GET', '/'), $handler);

        $this->assertSame('private, max-age=60', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }
}
