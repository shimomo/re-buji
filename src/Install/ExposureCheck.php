<?php

declare(strict_types=1);

namespace ReBuji\Install;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use ReBuji\Paths;

/**
 * Requests private files through the copy's own URL. They are protected only if every one is refused (403) or missing
 * (404); any other answer, including a redirect or a server error, counts as exposed.
 *
 * A 404 proves nothing on its own, since a wrong URL or another server answers 404 too. So the check first asks the URL
 * for a random value it has just written to storage/ (/install/probe/{nonce}); unless that comes back, no file is
 * judged.
 *
 * @author shimomo
 */
final class ExposureCheck
{
    /**
     * Relative to the package root. They exist once the database step has run (vendor and src always do).
     */
    public const array PATHS = [
        'storage/config.php',
        'storage/database.sqlite',
        'storage/install-key.txt',
        'src/Version.php',
        'vendor/composer/installed.json',
        'bin/cron.php',
    ];

    public const string PROBE_FILE = 'install-probe.txt';

    public function __construct(private readonly ClientInterface $http, private readonly RequestFactoryInterface $requests, private readonly Paths $paths)
    {
        //
    }

    /**
     * @param string $publicUrl where the app answers; the probe goes here
     * @param ?string $packageUrl where the package root is served, when that is not $publicUrl
     * @param list<string> $files relative to the package root
     */
    public function check(string $publicUrl, ?string $packageUrl = null, array $files = self::PATHS): ExposureResult
    {
        $base = rtrim($packageUrl ?? $publicUrl, '/');
        $urls = array_map(static fn(string $file): string => $base . '/' . $file, $files);
        $probeFailure = $this->probe(rtrim($publicUrl, '/'));

        if ($probeFailure !== null) {
            return new ExposureResult([], $urls, [], $probeFailure);
        }

        return $this->files($base, $files);
    }

    /**
     * Requests the files under $base and judges them, without asking for the probe first.
     *
     * @param string $base where the package root is served
     * @param list<string> $files relative to the package root
     */
    public function files(string $base, array $files = self::PATHS): ExposureResult
    {
        $base = rtrim($base, '/');
        $exposed = [];
        $unreachable = [];
        $statuses = [];

        foreach ($files as $file) {
            $url = $base . '/' . $file;

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

    /**
     * The messages for the files the web could read.
     *
     * @return list<string>
     */
    public static function exposedErrors(ExposureResult $result): array
    {
        $errors = array_map(
            static fn(string $url): string => 'ウェブから見えています：' . $url . '（状態 ' . ($result->statuses[$url] ?? 0) . '）',
            $result->exposed,
        );
        $errors[] = 'このサーバーでは .htaccess が効いていない可能性があります。サーバーの設定を確かめるか、公開ディレクトリの外に本体を置き、public/ の中身だけを公開する方法（docs/install.md）を使ってください。';

        return $errors;
    }

    /**
     * Asks the public URL for the console's probe: whether it reaches this copy, and what the web side of it sees.
     */
    public function reach(string $publicUrl): ProbeAnswer
    {
        $nonce = bin2hex(random_bytes(16));
        $file = $this->paths->storage(self::PROBE_FILE);

        if (@file_put_contents($file, $nonce) === false) {
            return ProbeAnswer::unreachable('確かめるためのファイルを storage に書けません');
        }

        try {
            $response = $this->http->sendRequest($this->requests->createRequest('GET', rtrim($publicUrl, '/') . '/install/probe/' . $nonce . '?from=console'));
        } catch (ClientExceptionInterface $e) {
            return ProbeAnswer::unreachable($e->getMessage());
        } finally {
            @unlink($file);
        }

        $status = $response->getStatusCode();

        if (in_array($status, [301, 302, 303, 307, 308], true) && $response->getHeaderLine('Location') !== '') {
            return ProbeAnswer::redirected($response->getHeaderLine('Location'));
        }

        if ($status !== 200) {
            $detail = '状態 ' . $status;
            $line = trim(explode("\n", trim((string) $response->getBody()))[0]);

            if ($line !== '' && str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/plain')) {
                $detail .= '：' . mb_substr($line, 0, 200);
            }

            return ProbeAnswer::refused($detail);
        }

        $web = WebSide::fromAnswer((string) $response->getBody(), $nonce);

        return $web === null ? ProbeAnswer::refused('別の内容（状態 200）') : ProbeAnswer::reached($web);
    }

    /**
     * Returns why the URL did not reach this copy, or null when it did.
     */
    private function probe(string $publicUrl): ?string
    {
        $nonce = bin2hex(random_bytes(16));
        $file = $this->paths->storage(self::PROBE_FILE);

        if (file_put_contents($file, $nonce) === false) {
            return '確かめるためのファイルを storage に書けません';
        }

        try {
            $response = $this->http->sendRequest($this->requests->createRequest('GET', $publicUrl . '/install/probe/' . $nonce));
        } catch (ClientExceptionInterface $e) {
            return '接続できません：' . $e->getMessage();
        } finally {
            @unlink($file);
        }

        if ($response->getStatusCode() !== 200) {
            return '状態 ' . $response->getStatusCode();
        }

        return hash_equals($nonce, trim((string) $response->getBody())) ? null : '別の内容（状態 200）';
    }
}
