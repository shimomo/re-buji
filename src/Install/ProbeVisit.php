<?php

declare(strict_types=1);

namespace ReBuji\Install;

use ReBuji\Paths;
use RuntimeException;

/**
 * The record of a browser opening the probe (?from=browser): the console opens a nonce, the browser's visit writes
 * what the web side sees to storage/, and the console reads it back.
 *
 * @author shimomo
 */
final class ProbeVisit
{
    public const string FILE = 'install-probe-visit.json';

    public function __construct(private readonly Paths $paths)
    {
        //
    }

    /**
     * Writes a new nonce to the probe file and forgets the previous visit.
     *
     * @throws RuntimeException when storage/ cannot be written
     */
    public function open(): string
    {
        $nonce = bin2hex(random_bytes(16));
        @unlink($this->paths->storage(self::FILE));

        if (@file_put_contents($this->paths->storage(ExposureCheck::PROBE_FILE), $nonce) === false) {
            throw new RuntimeException('確かめるためのファイルを storage に書けません');
        }

        return $nonce;
    }

    /**
     * Returns false when the record cannot be written.
     */
    public function record(string $nonce, WebSide $web): bool
    {
        $file = $this->paths->storage(self::FILE);
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporary, $web->answer($nonce)) === false) {
            return false;
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    public function seen(string $nonce): ?WebSide
    {
        $file = $this->paths->storage(self::FILE);
        $body = is_file($file) ? file_get_contents($file) : false;

        return $body === false ? null : WebSide::fromAnswer($body, $nonce);
    }

    public function close(): void
    {
        @unlink($this->paths->storage(ExposureCheck::PROBE_FILE));
        @unlink($this->paths->storage(self::FILE));
    }
}
