<?php

declare(strict_types=1);

namespace ReBuji\Http;

use Override;
use RuntimeException;

/**
 * PHP's session, started on first write. Reading without a session cookie starts nothing, so requests that never need
 * one (bots, monitors) leave no file. Files go to storage/sessions rather than the server's shared directory, and
 * PHP's own cleanup runs there even on hosts that switch it off.
 *
 * @author shimomo
 */
final class NativeSession implements Session
{
    private const string NAME = 'rebuji_session';

    /**
     * @param string $cookiePath where the app lives, so the cookie goes to no other app on the same domain
     */
    public function __construct(private readonly string $savePath, private readonly bool $secure, private readonly string $cookiePath = '/')
    {
        //
    }

    #[Override]
    public function exists(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE || isset($_COOKIE[self::NAME]);
    }

    #[Override]
    public function get(string $key): mixed
    {
        if (!$this->exists()) {
            return null;
        }

        $this->start();

        return $_SESSION[$key] ?? null;
    }

    #[Override]
    public function set(string $key, mixed $value): void
    {
        $this->start();
        /** @psalm-suppress MixedAssignment the session holds whatever the app puts in it */
        $_SESSION[$key] = $value;
    }

    #[Override]
    public function remove(string $key): void
    {
        if (!$this->exists()) {
            return;
        }

        $this->start();
        unset($_SESSION[$key]);
    }

    #[Override]
    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    #[Override]
    public function clear(): void
    {
        if (!$this->exists()) {
            return;
        }

        $this->start();
        $_SESSION = [];
    }

    #[Override]
    public function &reference(string $key): array
    {
        $this->start();

        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }

        /** @var array<array-key, mixed> $reference */
        $reference = &$_SESSION[$key];

        return $reference;
    }

    private function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0700, true);
        }

        session_save_path($this->savePath);
        session_name(self::NAME);
        session_set_cookie_params(['lifetime' => 0, 'path' => $this->cookiePath, 'secure' => $this->secure, 'httponly' => true, 'samesite' => 'Lax']);

        // One start in a hundred removes files idle for longer than IDLE_SECONDS (Debian-based PHP ships with 0).
        // Strict mode refuses an id this app did not issue, so nobody can hand someone a session id chosen in advance;
        // set here rather than left to the host's settings.
        $options = ['gc_maxlifetime' => self::IDLE_SECONDS, 'gc_probability' => 1, 'gc_divisor' => 100, 'use_strict_mode' => 1, 'use_only_cookies' => 1, 'use_trans_sid' => 0];

        if (!session_start($options)) {
            throw new RuntimeException('Cannot start the session');
        }
    }
}
