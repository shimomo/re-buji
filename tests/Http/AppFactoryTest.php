<?php

declare(strict_types=1);

namespace ReBuji\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use RuntimeException;

/**
 * @author shimomo
 */
final class AppFactoryTest extends TestCase
{
    #[Test]
    public function showsAGenericPageOnErrors(): void
    {
        [$app, , $root] = TestApp::create([], TestApp::installedConfig());

        try {
            $app->get('/_boom', fn() => throw new RuntimeException('secret detail'));
            $response = $app->handle(TestApp::request('GET', '/_boom'));
            $body = (string) $response->getBody();

            $this->assertSame(500, $response->getStatusCode());
            $this->assertStringNotContainsString('secret detail', $body);
            $this->assertStringContainsString('エラーが起きました', $body);
            $this->assertStringContainsString('secret detail', (string) file_get_contents($root . '/storage/logs/re-buji.log'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function showsAGenericPageForMissingPages(): void
    {
        [$app, , $root] = TestApp::create([], TestApp::installedConfig());

        try {
            $response = $app->handle(TestApp::request('GET', '/no-such-page'));

            $this->assertSame(404, $response->getStatusCode());
            $this->assertStringContainsString('ページが見つかりません', (string) $response->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }
}
