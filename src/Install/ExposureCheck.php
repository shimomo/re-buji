<?php

declare(strict_types=1);

namespace ReBuji\Install;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Requests private files through the copy's own public URL. They are protected only if every one is refused (403) or
 * missing (404); any other answer, including a redirect or a server error, counts as exposed.
 *
 * @author shimomo
 */
final class ExposureCheck
{
    /**
     * Relative to the public URL. They exist once the database step has run (vendor and src always do).
     */
    public const array PATHS = [
        'storage/config.php',
        'storage/database.sqlite',
        'storage/install-key.txt',
        'src/Version.php',
        'vendor/composer/installed.json',
        'bin/cron.php',
    ];

    public function __construct(private readonly ClientInterface $http, private readonly RequestFactoryInterface $requests)
    {
        //
    }

    public function check(string $baseUrl): ExposureResult
    {
        $base = rtrim($baseUrl, '/');
        $exposed = [];
        $unreachable = [];
        $statuses = [];

        foreach (self::PATHS as $path) {
            $url = $base . '/' . $path;

            try {
                $status = $this->http->sendRequest($this->requests->createRequest('GET', $url))->getStatusCode();
            } catch (ClientExceptionInterface) {
                $unreachable[] = $url;

                continue;
            }

            $statuses[$url] = $status;

            if ($status !== 403 && $status !== 404) {
                $exposed[] = $url;
            }
        }

        return new ExposureResult($exposed, $unreachable, $statuses);
    }
}
