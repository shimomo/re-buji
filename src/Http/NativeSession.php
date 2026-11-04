<?php

declare(strict_types=1);

namespace ReBuji\Http;

use Override;
use RuntimeException;

/**
 * PHP's session, started on first use. Files go to storage/sessions rather than the server's shared directory.
 *
 * @author shimomo
 */
final class NativeSession implements Session
{
    public function __construct(private readonly string $savePath, private readonly bool $secure)
    {
        //
    }

    #[Override]
    public function get(string $key): mixed
    {
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
        session_name('rebuji_session');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $this->secure, 'httponly' => true, 'samesite' => 'Lax']);

        if (!session_start()) {
            throw new RuntimeException('Cannot start the session');
        }
    }
}
