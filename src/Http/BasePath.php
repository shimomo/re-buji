<?php

declare(strict_types=1);

namespace ReBuji\Http;

/**
 * The URL prefix the app is served under: "" when public/ is the document root, "/re-buji" when the whole package sits
 * in a subdirectory and its .htaccess forwards requests to public/.
 *
 * @author shimomo
 */
final class BasePath
{
    /**
     * @param array<array-key, mixed> $server
     */
    public static function detect(array $server): string
    {
        $script = self::string($server, 'SCRIPT_NAME');
        $uri = (string) parse_url(self::string($server, 'REQUEST_URI'), PHP_URL_PATH);
        $publicDir = $script === '' ? '' : rtrim(str_replace('\\', '/', dirname($script)), '/');

        if ($publicDir !== '' && ($uri === $publicDir || str_starts_with($uri, $publicDir . '/'))) {
            return $publicDir;
        }

        $packageDir = str_ends_with($publicDir, '/public') ? substr($publicDir, 0, -strlen('/public')) : $publicDir;

        return $packageDir === '/' ? '' : $packageDir;
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function string(array $server, string $key): string
    {
        return isset($server[$key]) && is_string($server[$key]) ? $server[$key] : '';
    }
}
