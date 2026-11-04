<?php

declare(strict_types=1);

namespace ReBuji\Tests\Http;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Http\NativeSession;
use ReBuji\Http\Session;
use ReBuji\Tests\Support\TemporaryDirectory;

/**
 * PHP's own session, so each test runs in a process of its own.
 *
 * @author shimomo
 */
final class NativeSessionTest extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    public function readingWithoutACookieCreatesNoFile(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            $session = new NativeSession($directory, false);

            $this->assertNull($session->get('admin_id'));
            $session->remove('admin_id');
            $this->assertFalse($session->exists());
            $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
            $this->assertSame([], glob($directory . '/sess_*'));
        } finally {
            TemporaryDirectory::remove($directory);
        }
    }

    #[Test]
    #[RunInSeparateProcess]
    public function cleansUpSessionsIdleForLongerThanTheLimit(): void
    {
        $directory = TemporaryDirectory::create();

        try {
            touch($directory . '/sess_old', time() - Session::IDLE_SECONDS - 60);
            touch($directory . '/sess_recent', time() - 3600);
            // Debian and Ubuntu ship with session.gc_probability = 0; the session must not rely on the host's value.
            ini_set('session.gc_probability', '0');
            $session = new NativeSession($directory, false);
            $session->set('a', 1);

            $this->assertTrue($session->exists());
            $this->assertSame('1', ini_get('session.gc_probability'));
            session_gc();
            $this->assertFileDoesNotExist($directory . '/sess_old');
            $this->assertFileExists($directory . '/sess_recent');
        } finally {
            session_write_close();
            TemporaryDirectory::remove($directory);
        }
    }
}
