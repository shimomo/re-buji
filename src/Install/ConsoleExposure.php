<?php

declare(strict_types=1);

namespace ReBuji\Install;

use Closure;
use ReBuji\Console\ConsoleIo;

/**
 * The console's version of the web setup's exposure check: confirms that the public URL reaches this copy and that the
 * database and config files cannot be read from the web. When the server cannot reach its own URL, the owner's browser
 * is asked to open it instead.
 *
 * @author shimomo
 */
final class ConsoleExposure
{
    public const int VISIT_WAIT_SECONDS = 600;

    private const int POLL_SECONDS = 2;

    /**
     * @param Closure(int): void $sleep waits for the given number of seconds
     */
    public function __construct(private readonly ExposureCheck $check, private readonly ProbeVisit $visit, private readonly Closure $sleep)
    {
        //
    }

    /**
     * Asks $publicUrl for the probe, following one redirect that keeps the probe's path.
     *
     * Returns the URL that reached this copy; false when this URL goes somewhere else (another one may do); null when
     * it stopped. For false and null the reason has been written with $io->error().
     *
     * @param string $directory the directory the URL must open, named in the messages
     */
    public function reach(string $publicUrl, string $directory, ConsoleIo $io): ReachedUrl|false|null
    {
        return $this->reachFollowing($publicUrl, $directory, $io, false);
    }

    /**
     * Checks the web side of a reached URL and that the private files cannot be read from it. Returns true when the
     * install may go on; otherwise the reason has been written with $io->error().
     *
     * @param string $driver 'sqlite' or 'mysql'
     */
    public function finish(ReachedUrl $reached, string $driver, ConsoleIo $io): bool
    {
        $web = $reached->web;
        $publicUrl = $reached->url;

        if (!$this->webSideIsFit($web, $driver, $io)) {
            return false;
        }

        $base = PackagePath::url($web->packagePath, $publicUrl);

        if ($reached->byBrowser) {
            return $this->askOwner($io, 'ブラウザで次の URL を開き、どれも表示されない（403 か 404 になる）ことを確かめてください：', $this->urls($base, ExposureCheck::PATHS), '✓ ブラウザで確かめました');
        }

        $result = $this->check->files($base);

        if ($result->exposed !== []) {
            foreach (ExposureCheck::exposedErrors($result) as $error) {
                $io->error($error);
            }

            return false;
        }

        if ($result->unreachable !== []) {
            return $this->askOwner($io, 'サーバーから次の URL を確かめられませんでした。ブラウザで開き、どれも表示されない（403 か 404 になる）ことを確かめてください：', $result->unreachable, '✓ ブラウザで確かめました');
        }

        $io->line('✓ この URL がこの Re:無事 に届き、データベースや設定がウェブから読めないことを確かめました');

        return true;
    }

    private function reachFollowing(string $publicUrl, string $directory, ConsoleIo $io, bool $followed): ReachedUrl|false|null
    {
        $answer = $this->check->reach($publicUrl);

        switch ($answer->outcome) {
            case ProbeOutcome::Reached:
                assert($answer->web !== null);

                return new ReachedUrl(rtrim($publicUrl, '/'), $answer->web, false);
            case ProbeOutcome::Unreachable:
                $web = $this->waitForBrowser($publicUrl, $answer->detail, $io);

                return $web === null ? null : new ReachedUrl(rtrim($publicUrl, '/'), $web, true);
            case ProbeOutcome::Refused:
                $io->error('サーバーから ' . $publicUrl . ' を開きましたが、この Re:無事 に届きませんでした（' . $answer->detail . '）。');
                $io->error('その URL が ' . $directory . ' を開くか、ウェブの PHP が 8.3 以上かを確かめてください。');

                return false;
            case ProbeOutcome::Redirected:
                $location = $this->absolute($answer->detail, $publicUrl);
                $position = strpos($location, '/install/probe/');

                if (!$followed && $position !== false && preg_match('#\Ahttps?://#i', $location) === 1) {
                    $next = rtrim(substr($location, 0, $position), '/');
                    $io->line('→ ' . $next . ' に転送されたので、こちらを使います');

                    return $this->reachFollowing($next, $directory, $io, true);
                }

                $io->error($publicUrl . ' は ' . self::withoutProbeQuery($location) . ' に転送されました。' . $directory . ' に届いていません。');

                return false;
        }
    }

    /**
     * A Location made absolute against the URL that was asked: the scheme and host (and port) are kept.
     */
    private function absolute(string $location, string $asked): string
    {
        $parts = parse_url($asked);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? 'https') : 'https';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $port = is_array($parts) && isset($parts['port']) ? ':' . $parts['port'] : '';

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        return str_starts_with($location, '/') ? $scheme . '://' . $host . $port . $location : $location;
    }

    /**
     * $location without the probe's own from=console in its query; other parameters stay, and so does no empty ?.
     */
    private static function withoutProbeQuery(string $location): string
    {
        $mark = strpos($location, '?');

        if ($mark === false) {
            return $location;
        }

        $kept = array_filter(explode('&', substr($location, $mark + 1)), static fn(string $pair): bool => $pair !== 'from=console' && $pair !== '');

        return substr($location, 0, $mark) . ($kept === [] ? '' : '?' . implode('&', $kept));
    }

    private function waitForBrowser(string $publicUrl, string $detail, ConsoleIo $io): ?WebSide
    {
        $nonce = $this->visit->open();

        try {
            $io->line('サーバーから ' . $publicUrl . ' に接続できませんでした（' . $detail . '）。');
            $io->line('ブラウザで次の URL を開いてください。開かれるのを待っています（10 分まで）…');
            $io->line('  ' . $publicUrl . '/install/probe/' . $nonce . '?from=browser');

            $waited = 0;

            while (true) {
                $web = $this->visit->seen($nonce);

                if ($web !== null) {
                    $io->line('✓ ブラウザで開かれました');

                    return $web;
                }

                if ($waited >= self::VISIT_WAIT_SECONDS) {
                    break;
                }

                ($this->sleep)(self::POLL_SECONDS);
                $waited += self::POLL_SECONDS;
            }

            $io->error('10 分のあいだに、ブラウザで開かれませんでした。URL が正しいか、ウェブから storage/ に書けるかを確かめて、もう一度流してください。');

            return null;
        } finally {
            $this->visit->close();
        }
    }

    private function webSideIsFit(WebSide $web, string $driver, ConsoleIo $io): bool
    {
        if (!in_array($driver, $web->drivers, true)) {
            $io->error('ウェブの PHP に pdo_' . $driver . ' がありません。サーバーの管理画面で、ウェブの PHP の設定を確かめてください。');

            return false;
        }

        if (!$web->storageWritable) {
            $io->error('ウェブの PHP から storage/ に書けません。storage/ の権限を 705 か 755 にしてください。');

            return false;
        }

        return true;
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function urls(string $base, array $files): array
    {
        return array_map(static fn(string $file): string => rtrim($base, '/') . '/' . $file, $files);
    }

    /**
     * @param list<string> $urls
     */
    private function askOwner(ConsoleIo $io, string $message, array $urls, string $confirmed): bool
    {
        $io->line($message);

        foreach ($urls as $url) {
            $io->line('  ' . $url);
        }

        $answer = strtolower(trim($io->ask('どれも 403 か 404 になりましたか？ [y/N] ')));

        if ($answer === 'y' || $answer === 'yes') {
            $io->line($confirmed);

            return true;
        }

        $io->error('確かめが済んでいないので、止めます。表示された URL があれば、本体を公開ディレクトリの外に置く（--public）か、.htaccess が効くようにしてから、もう一度流してください。');

        return false;
    }
}
