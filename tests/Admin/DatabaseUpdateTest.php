<?php

declare(strict_types=1);

namespace ReBuji\Tests\Admin;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Migration;
use ReBuji\Database\Migrations;
use ReBuji\Monitor\Health;
use ReBuji\SystemState;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use ReBuji\Version;

/**
 * @author shimomo
 */
final class DatabaseUpdateTest extends TestCase
{
    #[Test]
    public function listsAndAppliesPendingMigrations(): void
    {
        [$app, $container, $root] = TestApp::installed(['migrations' => [...Migrations::all(), $this->testMigration()]]);

        try {
            TestApp::logIn($app, $container);

            $this->assertSame('/admin/database', $app->handle(TestApp::request('GET', '/'))->getHeaderLine('Location'));
            $before = (string) $app->handle(TestApp::request('GET', '/admin/database'))->getBody();
            $this->assertStringContainsString('9999', $before);
            $this->assertStringContainsString('テスト用の変更', $before);
            $this->assertStringContainsString(Version::CURRENT, $before);

            $applied = $app->handle(TestApp::request('POST', '/admin/database', TestApp::csrf($container)));
            $this->assertSame(200, $applied->getStatusCode());
            $this->assertStringContainsString('9999', (string) $applied->getBody());

            $this->assertStringNotContainsString('テスト用の変更', (string) $app->handle(TestApp::request('GET', '/admin/database'))->getBody());
            $this->assertSame(200, $app->handle(TestApp::request('GET', '/'))->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function clearsTheWaitingNoticeOnceUpdated(): void
    {
        [$app, $container, $root] = TestApp::installed(['migrations' => [...Migrations::all(), $this->testMigration()]]);

        try {
            TestApp::logIn($app, $container);
            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(SystemState::class, $state);
            // What cron wrote while the new version waited for its database changes.
            $state->set('cron.last_error', Health::WAITING_FOR_UPDATE);

            $app->handle(TestApp::request('POST', '/admin/database', TestApp::csrf($container)));

            $this->assertSame('', $state->get('cron.last_error'));
            $this->assertStringNotContainsString('DB の更新を待っています', (string) $app->handle(TestApp::request('GET', '/'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function leavesAnotherCronErrorAlone(): void
    {
        [$app, $container, $root] = TestApp::installed(['migrations' => [...Migrations::all(), $this->testMigration()]]);

        try {
            TestApp::logIn($app, $container);
            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(SystemState::class, $state);
            $state->set('cron.last_error', 'feed: Connection timed out');

            $app->handle(TestApp::request('POST', '/admin/database', TestApp::csrf($container)));

            $this->assertSame('feed: Connection timed out', $state->get('cron.last_error'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function saysAnotherUpdateIsRunning(): void
    {
        [$app, $container, $root] = TestApp::installed(['migrations' => [...Migrations::all(), $this->testMigration()]]);

        try {
            TestApp::logIn($app, $container);
            $held = fopen($root . '/storage/migrate.lock', 'c');
            $this->assertNotFalse($held);
            flock($held, LOCK_EX);

            $busy = $app->handle(TestApp::request('POST', '/admin/database', TestApp::csrf($container)));

            $this->assertSame(200, $busy->getStatusCode());
            $this->assertStringContainsString('ほかの画面で DB の更新が進んでいます。', (string) $busy->getBody());
            $this->assertStringNotContainsString('DB を更新する', (string) $busy->getBody());

            flock($held, LOCK_UN);
            fclose($held);

            $this->assertStringContainsString('当てました：9999', (string) $app->handle(TestApp::request('POST', '/admin/database', TestApp::csrf($container)))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function requiresLogin(): void
    {
        [$app, , $root] = TestApp::installed();

        try {
            $this->assertSame('/login', $app->handle(TestApp::request('GET', '/admin/database'))->getHeaderLine('Location'));
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
