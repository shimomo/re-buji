<?php

declare(strict_types=1);

namespace ReBuji\Install;

/**
 * The URL path the package root is served under, from what the web server says about the running script: "" at the
 * top of the site, "/re-buji" in a subdirectory, or null when the package is outside the document root (only public/
 * is served, so its files have no URL) or the server does not say.
 *
 * @author shimomo
 */
final class PackagePath
{
    /**
     * @param array<array-key, mixed> $server
     */
    public static function detect(array $server, string $root): ?string
    {
        $package = self::real($root);

        if ($package === null) {
            return null;
        }

        // The running script is this package's public/index.php, so the package is one level above it in the URL too.
        $script = self::real(dirname(self::string($server, 'SCRIPT_FILENAME')));
        $scriptDir = rtrim(str_replace('\\', '/', dirname(self::string($server, 'SCRIPT_NAME'))), '/');

        if ($script === $package . '/public' && str_ends_with($scriptDir, '/public')) {
            return self::encode(substr($scriptDir, 0, -strlen('/public')));
        }

        $documentRoot = self::real(self::string($server, 'DOCUMENT_ROOT'));

        if ($documentRoot === null) {
            return null;
        }

        if ($package === $documentRoot) {
            return '';
        }

        return str_starts_with($package, $documentRoot . '/') ? self::encode(substr($package, strlen($documentRoot))) : null;
    }

    private static function real(string $path): ?string
    {
        // realpath('') and realpath('.') are the working directory, which says nothing about the server.
        if ($path === '' || $path === '.') {
            return null;
        }

        $real = realpath($path);

        return $real === false ? null : rtrim($real, '/');
    }

    private static function encode(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function string(array $server, string $key): string
    {
        return isset($server[$key]) && is_string($server[$key]) ? $server[$key] : '';
    }
}
