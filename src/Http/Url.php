<?php

declare(strict_types=1);

namespace ReBuji\Http;

/**
 * Builds URLs under the base path, for links and redirects.
 *
 * @author shimomo
 */
final readonly class Url
{
    public function __construct(private string $basePath)
    {
        //
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function to(string $path): string
    {
        return $this->basePath . $path;
    }

    /**
     * The path of the request without the base path ("/" at least).
     */
    public function relative(string $requestPath): string
    {
        $path = $this->basePath !== '' && str_starts_with($requestPath, $this->basePath) ? substr($requestPath, strlen($this->basePath)) : $requestPath;

        return $path === '' ? '/' : $path;
    }
}
