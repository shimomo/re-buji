<?php

declare(strict_types=1);

namespace ReBuji\Tests\Admin;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Migration;
use ReBuji\Database\Migrations;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;

/**
 * Between uploading a new version over FTP and pressing the update button, new code would meet old tables (design
 * 6.4). Until then the admin pages lead to the update.
 *
 * @author shimomo
 */
final class PendingMigrationsGateTest extends TestCase
{
    #[Test]
    public function sendsEveryPageToTheUpdateWhileMigrationsArePending(): void
    {
        [$app, $container, $root] = TestApp::installed(['migrations' => [...Migrations::all(), $this->testMigration()]]);

        try {
            TestApp::logIn($app, $container);
            $home = $app->handle(TestApp::request('GET', '/'));
            $this->assertSame(302, $home->getStatusCode());
            $this->assertSame('/admin/database', $home->getHeaderLine('Location'));

            $update = $app->handle(TestApp::request('GET', '/admin/database'));
            $this->assertSame(200, $update->getStatusCode());
            $this->assertStringContainsString('DB の更新を当てるまで、ほかの画面は開けません。', (string) $update->getBody());

            $this->assertSame('/login', $app->handle(TestApp::request('POST', '/logout', TestApp::csrf($container)))->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function opensThePagesOnceApplied(): void
    {
        [$app, $container, $root] = TestApp::installed(['migrations' => [...Migrations::all(), $this->testMigration()]]);

        try {
            TestApp::logIn($app, $container);
            $app->handle(TestApp::request('POST', '/admin/database', TestApp::csrf($container)));

            $this->assertSame(200, $app->handle(TestApp::request('GET', '/'))->getStatusCode());
            $this->assertStringNotContainsString('ほかの画面は開けません', (string) $app->handle(TestApp::request('GET', '/admin/database'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    private function testMigration(): Migration
    {
        return new class () implements Migration {
            #[Override]
            public function version(): string
            {
                return '9999';
            }

            #[Override]
            public function description(): string
            {
                return 'テスト用の変更';
            }

            #[Override]
            public function statements(string $driver): array
            {
                return ['CREATE TABLE test_only (id INTEGER)'];
            }
        };
    }
}
