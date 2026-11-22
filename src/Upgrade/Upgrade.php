<?php

declare(strict_types=1);

namespace ReBuji\Upgrade;

use FilesystemIterator;
use Psr\Clock\ClockInterface;
use ReBuji\Config\Config;
use ReBuji\Config\ConfigFile;
use ReBuji\Database\Database;
use ReBuji\Database\Migration;
use ReBuji\Database\Migrator;
use ReBuji\Paths;
use ReBuji\PrivateFile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Puts the copy unpacked at $source in place of the installed copy at $target (bin/upgrade.sh): everything but storage/
 * is replaced, a copy of what was there is kept in storage/upgrade-backups/ first, and the database changes are applied.
 * $source is the new version, so the steps here always match the version being installed.
 *
 * @author shimomo
 */
final class Upgrade
{
    /**
     * Where a copy of the replaced files and the database is kept, inside storage/ so the web never serves it.
     */
    public const string BACKUPS = 'upgrade-backups';

    /**
     * The key in storage/config.php that remembers the public directory when only public/ is in the document root.
     */
    public const string PUBLIC_DIR = 'upgrade.public_dir';

    private ?Database $db = null;

    /**
     * @param list<Migration> $migrations the new version's migrations
     */
    public function __construct(
        private readonly string $source,
        private readonly string $target,
        private readonly array $migrations,
        private readonly ClockInterface $clock,
    ) {
        //
    }

    /**
     * The version a copy says it is, read from its src/Version.php without loading it (the class is already loaded from
     * the new copy).
     */
    public static function versionOf(string $root): ?string
    {
        $code = @file_get_contents($root . '/src/Version.php');

        return is_string($code) && preg_match("/CURRENT = '([^']+)'/", $code, $match) === 1 ? $match[1] : null;
    }

    public function config(): ConfigFile
    {
        return new ConfigFile($this->target . '/storage/config.php');
    }

    /**
     * @return list<Migration> the new version's changes the installed database does not have yet
     */
    public function pendingMigrations(): array
    {
        return $this->migrator()->pending();
    }

    /**
     * Why $directory cannot be the public directory of this copy, or null when it can: it must hold index.php and a
     * rebuji-root.php that points at $target.
     */
    public function publicDirProblem(string $directory): ?string
    {
        if (!is_file($directory . '/index.php')) {
            return $directory . ' に index.php がありません。';
        }

        $pointer = $directory . '/rebuji-root.php';

        if (!is_file($pointer)) {
            return $directory . ' に rebuji-root.php がありません。';
        }

        /** @psalm-suppress UnresolvableInclude */
        $root = (string) require $pointer;

        if (realpath($root) !== realpath($this->target)) {
            return $directory . '/rebuji-root.php が ' . $this->target . ' を指していません（' . $root . '）。';
        }

        return null;
    }

    /**
     * Copies everything but storage/ to $directory/files and the SQLite database to $directory/database.sqlite. MySQL
     * is not copied: the caller says to use mysqldump.
     */
    public function backUp(string $directory): void
    {
        self::makeDirectory($directory, 0700);

        foreach ($this->entries($this->target) as $name) {
            self::copy($this->target . '/' . $name, $directory . '/files/' . $name);
        }

        $db = $this->database();

        if ($db->driver() === 'sqlite') {
            // Private before anything is written to it, like `console backup`.
            if (!PrivateFile::create($directory . '/database.sqlite')) {
                throw new RuntimeException('Cannot create ' . $directory . '/database.sqlite');
            }

            $db->execute('VACUUM INTO ?', [$directory . '/database.sqlite']);
        }
    }

    /**
     * Puts each entry of the new copy (but storage/) in place. The entry is first copied next to the copy (inside
     * storage/, so on the same disk) and then renamed in, with the old entry renamed out of the way just before: a request
     * never finds a half-copied directory. The new copy itself is left as it is, because this runs from it and still
     * loads classes from it.
     *
     * @return list<string> the entries replaced
     */
    public function replace(): array
    {
        $work = $this->target . '/storage/upgrade-trash-' . bin2hex(random_bytes(4));
        self::makeDirectory($work . '/new', 0700);
        self::makeDirectory($work . '/old', 0700);
        $replaced = [];

        try {
            foreach ($this->entries($this->source) as $name) {
                $old = $this->target . '/' . $name;
                $new = $work . '/new/' . $name;
                self::copy($this->source . '/' . $name, $new);

                // With the whole copy in the document root there is no pointer; with only public/ there, it lives in
                // the document root. Either way one left in public/ by hand is kept.
                if ($name === 'public' && is_file($old . '/rebuji-root.php') && !file_exists($new . '/rebuji-root.php')) {
                    self::copy($old . '/rebuji-root.php', $new . '/rebuji-root.php');
                }

                if (file_exists($old) && !rename($old, $work . '/old/' . $name)) {
                    throw new RuntimeException('Cannot move ' . $old);
                }

                if (!rename($new, $old)) {
                    throw new RuntimeException('Cannot move ' . $new . ' to ' . $old);
                }

                $replaced[] = $name;
            }
        } finally {
            self::remove($work);
        }

        return $replaced;
    }

    /**
     * @return list<string> the versions applied
     */
    public function migrate(): array
    {
        return $this->migrator()->migrate();
    }

    /**
     * Copies the new public/index.php and public/assets/ into the public directory. Its .htaccess and rebuji-root.php
     * are left as they are.
     */
    public function updatePublicDir(string $directory): void
    {
        self::copy($this->target . '/public/index.php', $directory . '/index.php');

        $assets = $directory . '/assets';
        $old = $directory . '/assets-old-' . bin2hex(random_bytes(4));

        if (is_dir($assets) && !rename($assets, $old)) {
            throw new RuntimeException('Cannot move ' . $assets);
        }

        self::copy($this->target . '/public/assets', $assets);
        self::remove($old);
    }

    public function rememberPublicDir(string $directory): void
    {
        $config = $this->config();
        $config->save(new Config([self::PUBLIC_DIR => $directory] + $config->load()->all()));
    }

    /**
     * @return list<string> the top-level names of a copy, storage/ left out
     */
    private function entries(string $root): array
    {
        $names = array_values(array_diff((array) scandir($root), ['.', '..', 'storage']));
        sort($names);

        return array_map('strval', $names);
    }

    private function database(): Database
    {
        return $this->db ??= Database::fromConfig($this->config()->load(), new Paths($this->target));
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->database(), $this->migrations, $this->clock, $this->target . '/storage/migrate.lock');
    }

    private static function makeDirectory(string $directory, int $mode): void
    {
        if (!is_dir($directory) && !mkdir($directory, $mode, true)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }
    }

    /**
     * Copies a file or a directory tree, keeping each file's permissions.
     */
    private static function copy(string $from, string $to): void
    {
        if (is_link($from) || is_file($from)) {
            self::makeDirectory(dirname($to), 0755);

            if (!copy($from, $to) || !chmod($to, self::mode($from))) {
                throw new RuntimeException('Cannot copy ' . $from . ' to ' . $to);
            }

            return;
        }

        self::makeDirectory($to, self::mode($from));
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $path = $to . substr($item->getPathname(), strlen($from));

            if ($item->isDir()) {
                self::makeDirectory($path, $item->getPerms() & 0777);
            } elseif (!copy($item->getPathname(), $path) || !chmod($path, $item->getPerms() & 0777)) {
                throw new RuntimeException('Cannot copy ' . $item->getPathname());
            }
        }
    }

    private static function mode(string $path): int
    {
        $perms = fileperms($path);

        if ($perms === false) {
            throw new RuntimeException('Cannot read the permissions of ' . $path);
        }

        return $perms & 0777;
    }

    private static function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
