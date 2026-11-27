<?php

declare(strict_types=1);

namespace ReBuji\Install;

use FilesystemIterator;
use ReBuji\Config\Config;
use ReBuji\Config\ConfigFile;
use ReBuji\Paths;
use ReBuji\Upgrade\Upgrade;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Places this copy's public files in a host's public directory, where only public/ is served, and remembers the
 * directory in storage/config.php so bin/upgrade.sh can refresh it. rebuji-root.php goes first and index.php last: a
 * run that stops half way leaves a directory that is still known as this copy's, so the next run can place again.
 *
 * @author shimomo
 */
final readonly class PublicDir
{
    private const int LISTED = 10;

    public function __construct(private Paths $paths)
    {
        //
    }

    /**
     * Why the files of this copy cannot be placed in $directory, or null when they can: it is not there yet (its parent
     * is), empty, or holds a rebuji-root.php that points at this copy.
     */
    public function problem(string $directory): ?string
    {
        $exists = file_exists($directory);

        if (!$exists && !is_dir(dirname($directory))) {
            return $directory . ' の親のディレクトリがありません。';
        }

        if ($exists && !is_dir($directory)) {
            return $directory . ' はディレクトリではありません。';
        }

        $root = $this->paths->root();

        if ($this->overlaps($directory, $root)) {
            return $directory . ' と本体の場所（' . $root . '）が重なっています。';
        }

        $pointed = $this->pointedAt($directory);

        if ($pointed !== null) {
            return realpath($pointed) === realpath($root) ? null : $directory . ' は、ほかの Re:無事（' . $pointed . '）の公開ディレクトリです。';
        }

        $names = $exists ? array_values(array_diff((array) scandir($directory), ['.', '..'])) : [];

        if ($names === []) {
            return null;
        }

        sort($names);
        $listed = implode('、', array_map('strval', array_slice($names, 0, self::LISTED)));

        if (count($names) > self::LISTED) {
            $listed .= '、ほか ' . (count($names) - self::LISTED) . ' 件';
        }

        return $directory . ' にほかのファイルがあります（' . $listed . '）。まだ無いか、空のディレクトリを渡してください。';
    }

    /**
     * Places the files and remembers the directory as upgrade.public_dir.
     *
     * @return string the full path of the directory
     */
    public function place(string $directory, ConfigFile $config): string
    {
        $this->makeDirectory($directory, 0755);

        self::write($directory . '/rebuji-root.php', "<?php\n\nreturn " . var_export($this->paths->root(), true) . ";\n", 0644);
        $this->copyTree($this->paths->public() . '/assets', $directory . '/assets');
        self::copyFile($this->paths->public() . '/.htaccess', $directory . '/.htaccess');
        self::copyFile($this->paths->public() . '/index.php', $directory . '/index.php');

        $place = realpath($directory);

        if ($place === false) {
            throw new RuntimeException('Cannot resolve ' . $directory);
        }

        $values = $config->exists() ? $config->load()->all() : [];
        $config->save(new Config([Upgrade::PUBLIC_DIR => $place] + $values));

        return $place;
    }

    /**
     * Whether either path is the other or inside it. A path that is not there yet counts by its parent and its name.
     */
    private function overlaps(string $directory, string $root): bool
    {
        $real = realpath($directory);

        if ($real === false) {
            $parent = realpath(dirname($directory));
            $real = ($parent === false ? dirname($directory) : $parent) . '/' . basename($directory);
        }

        $directory = $real;
        $root = realpath($root);
        $root = $root === false ? $this->paths->root() : $root;

        return $directory === $root
            || str_starts_with($directory, $root . '/')
            || str_starts_with($root, $directory . '/');
    }

    /**
     * @return string|null where $directory/rebuji-root.php points, or null when there is none to read
     */
    private function pointedAt(string $directory): ?string
    {
        $pointer = $directory . '/rebuji-root.php';

        if (!is_file($pointer)) {
            return null;
        }

        /**
         * @psalm-suppress UnresolvableInclude
         *
         * @var mixed $root
         */
        $root = require $pointer;

        return is_string($root) ? $root : null;
    }

    private function makeDirectory(string $directory, int $mode): void
    {
        if (!is_dir($directory) && !mkdir($directory, $mode)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }

        // mkdir() is masked by the umask of whoever runs this (an SSH session may have 077).
        if (!is_dir($directory) || !chmod($directory, $mode)) {
            throw new RuntimeException('Cannot set the permissions of ' . $directory);
        }
    }

    private function copyTree(string $from, string $to): void
    {
        $this->makeDirectory($to, 0755);

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $path = $to . substr($item->getPathname(), strlen($from));

            if ($item->isDir()) {
                $this->makeDirectory($path, $item->getPerms() & 0777);

                continue;
            }

            self::copyFile($item->getPathname(), $path);
        }
    }

    private static function copyFile(string $from, string $to): void
    {
        $perms = fileperms($from);

        if ($perms === false || !@copy($from, $to) || !chmod($to, $perms & 0777)) {
            throw new RuntimeException('Cannot copy ' . $from . ' to ' . $to);
        }
    }

    private static function write(string $path, string $contents, int $mode): void
    {
        if (file_put_contents($path, $contents) === false || !chmod($path, $mode)) {
            throw new RuntimeException('Cannot write ' . $path);
        }
    }
}
