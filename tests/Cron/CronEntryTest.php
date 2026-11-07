<?php

declare(strict_types=1);

namespace ReBuji\Tests\Cron;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Config\ConfigFile;
use ReBuji\Database\Database;
use ReBuji\Database\Migration;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Migrations\M0001CreateFoundationTables;
use ReBuji\Paths;
use ReBuji\SystemClock;
use ReBuji\SystemState;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;

/**
 * Runs bin/cron.php as the server's cron would: a separate PHP process, on a copy whose root is a temp directory.
 *
 * @author shimomo
 */
final class CronEntryTest extends TestCase
{
    #[Test]
    public function runsOnAnInstalledCopy(): void
    {
        $root = $this->copy(true);

        try {
            // Nothing listens on port 9 (discard), so the feed job fails fast instead of reaching the real JMA site.
            [$exitCode] = $this->runCron($root, ['https_proxy' => 'http://127.0.0.1:9', 'HTTPS_PROXY' => 'http://127.0.0.1:9', 'no_proxy' => '', 'NO_PROXY' => '']);

            $this->assertSame(0, $exitCode);
            $this->assertNotNull($this->state($root)->get('cron.last_finished_at'));
            $this->assertStringStartsWith('feed: ', (string) $this->state($root)->get('cron.last_error'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function waitsForTheDatabaseUpdate(): void
    {
        // A new version was uploaded over FTP, but nobody has pressed the update button yet.
        $root = $this->copy(true, [new M0001CreateFoundationTables()]);

        try {
            [$exitCode] = $this->runCron($root, []);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('DB の更新を待っています', (string) $this->state($root)->get('cron.last_error'));
            $this->assertNull($this->state($root)->get('cron.last_started_at'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesToRunFromTheWeb(): void
    {
        $root = $this->copy(true);

        try {
            [$exitCode, $output] = $this->runCron($root, ['REQUEST_METHOD' => 'GET']);

            $this->assertNotSame(0, $exitCode);
            $this->assertStringContainsString('403', $output);
            $this->assertNull($this->state($root)->get('cron.last_started_at'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function doesNothingBeforeSetup(): void
    {
        $root = $this->copy(false);

        try {
            [$exitCode] = $this->runCron($root, []);

            $this->assertSame(0, $exitCode);
            $this->assertFileDoesNotExist($root . '/storage/database.sqlite');
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * @param list<Migration>|null $migrations what an installed copy has applied (all of them by default)
     */
    private function copy(bool $installed, ?array $migrations = null): string
    {
        $root = TemporaryDirectory::create();
        mkdir($root . '/storage');
        mkdir($root . '/bin');
        copy(dirname(__DIR__, 2) . '/bin/cron.php', $root . '/bin/cron.php');
        symlink(dirname(__DIR__, 2) . '/vendor', $root . '/vendor');

        if ($installed) {
            $paths = new Paths($root);
            (new ConfigFile($paths->storage('config.php')))->save(TestApp::installedConfig());
            (new Migrator(Database::fromConfig(TestApp::installedConfig(), $paths), $migrations ?? Migrations::all(), new SystemClock()))->migrate();
        }

        return $root;
    }

    private function state(string $root): SystemState
    {
        return new SystemState(Database::fromConfig(TestApp::installedConfig(), new Paths($root)), new SystemClock());
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{int, string}
     */
    private function runCron(string $root, array $env): array
    {
        $environment = array_filter(getenv(), static fn(int|string $key): bool => $key !== 'REQUEST_METHOD', ARRAY_FILTER_USE_KEY);
        $process = proc_open([PHP_BINARY, $root . '/bin/cron.php'], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root, $env + $environment);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }
}
