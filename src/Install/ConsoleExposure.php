<?php

declare(strict_types=1);

namespace ReBuji\Install;

use Closure;
use ReBuji\Console\ConsoleIo;
use ReBuji\Console\TimeLeft;
use RuntimeException;

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
                // The status alone cannot tell these apart (a 404 or a 500 either way), so all of them are named.
                $io->error($directory . ' の .htaccess が効いているか（.htaccess と mod_rewrite が使えるサーバーか）も確かめてください。');
                $io->error('ウェブの PHP が SSH とは別の利用者で動く（モジュール版の PHP など）と、storage/ を読めずに届きません。サーバーの管理画面で、CGI 版の PHP を選べるか確かめてください。');

                return false;
            case ProbeOutcome::Redirected:
                $location = $this->absolute($answer->detail, $publicUrl);
                $next = $followed ? null : $this->probeBase($location);

                if ($next !== null && $next !== rtrim($publicUrl, '/')) {
                    $io->line('→ ' . $next . ' に転送されたので、こちらを使います');

                    return $this->reachFollowing($next, $directory, $io, true);
                }

                $io->error($publicUrl . ' は ' . self::withoutProbeQuery($location) . ' に転送されました。' . $directory . ' に届いていません。');

                return false;
        }
    }

    /**
     * The URL a Location leads to when its path still ends in the probe's: scheme, host, port and the path before
     * /install/probe/. Null for any other Location, one that only has the probe in its query included.
     */
    private function probeBase(string $location): ?string
    {
        $parts = parse_url($location);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $path = $parts['path'] ?? '';
        $position = strpos($path, '/install/probe/');

        if ($position === false) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim(substr($path, 0, $position), '/');
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
        $cleaned = (string) preg_replace('/([?&])from=console(?=&|\z)/', '$1', $location);
        $cleaned = str_replace('?&', '?', $cleaned);
        $cleaned = (string) preg_replace('/&&+/', '&', $cleaned);

        return rtrim($cleaned, '?&');
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
                try {
                    $web = $this->visit->seen($nonce);
                } catch (RuntimeException $e) {
                    $io->error('ブラウザで開かれましたが、' . $e->getMessage() . '。ウェブの PHP が SSH とは別の利用者で動く（モジュール版の PHP など）と、こうなります。サーバーの管理画面で、CGI 版の PHP を選べるか確かめてください。');

                    return null;
                }

                if ($web !== null) {
                    $io->line('✓ ブラウザで開かれました');

                    return $web;
                }

                if ($waited >= self::VISIT_WAIT_SECONDS) {
                    break;
                }

                ($this->sleep)(self::POLL_SECONDS);
                $waited += self::POLL_SECONDS;
                $left = TimeLeft::line('まだ開かれていません', $waited - self::POLL_SECONDS, $waited, self::VISIT_WAIT_SECONDS);

                if ($left !== null) {
                    $io->line($left);
                }
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
