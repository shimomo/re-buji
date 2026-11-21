<?php

declare(strict_types=1);

namespace ReBuji;

use RuntimeException;

/**
 * Makes files that hold secrets (the config, the install key, the SQLite database, the log) readable by the owner
 * alone from the moment they exist. The file is made empty and narrowed before anything is written, so no umask lets
 * another account on a shared server read the contents, even briefly. The umask itself is left alone: it is
 * process-wide, and threaded servers share it between requests.
 *
 * Files that exist already keep their permissions: where the web server and cron run as different users, narrowing
 * them could lock one of the two out.
 *
 * @author shimomo
 */
final class PrivateFile
{
    /**
     * Creates the file empty with 0600. False when it exists already or cannot be made (a missing folder, say); the
     * write or connection that follows reports that.
     */
    public static function create(string $path): bool
    {
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            return false;
        }

        fclose($handle);

        if (!chmod($path, 0600)) {
            @unlink($path);

            throw new RuntimeException('Cannot make ' . $path . ' private');
        }

        return true;
    }

    /**
     * Writes a new file that only the owner can read. An existing file is never written over.
     */
    public static function write(string $path, string $contents): void
    {
        if (!self::create($path)) {
            throw new RuntimeException('Cannot create ' . $path);
        }

        if (file_put_contents($path, $contents) === false) {
            @unlink($path);

            throw new RuntimeException('Cannot write ' . $path);
        }
    }
}
