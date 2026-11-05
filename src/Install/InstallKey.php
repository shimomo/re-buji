<?php

declare(strict_types=1);

namespace ReBuji\Install;

use ReBuji\Paths;
use RuntimeException;

/**
 * Proof that whoever runs the installer can read the server's files: a random key written to storage/install-key.txt,
 * which only someone with FTP access can open. Without it, a stranger who found the URL first could take over setup.
 *
 * @author shimomo
 */
final class InstallKey
{
    private const string FILE = 'install-key.txt';

    public function __construct(private readonly Paths $paths)
    {
        //
    }

    public function ensure(): void
    {
        $path = $this->path();

        if (is_file($path)) {
            return;
        }

        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $key = '';

        for ($i = 0; $i < 24; $i++) {
            $key .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        if (file_put_contents($path, $key . "\n") === false || !chmod($path, 0600)) {
            throw new RuntimeException('Cannot write ' . $path);
        }
    }

    public function matches(string $input): bool
    {
        $path = $this->path();
        $input = trim($input);

        if ($input === '' || !is_file($path)) {
            return false;
        }

        return hash_equals(trim((string) file_get_contents($path)), $input);
    }

    public function forget(): void
    {
        @unlink($this->path());
    }

    private function path(): string
    {
        return $this->paths->storage(self::FILE);
    }
}
