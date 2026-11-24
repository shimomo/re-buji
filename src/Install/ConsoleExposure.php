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
     * Returns true when the install may go on; otherwise the reason has been written with $io->error().
     *
     * @param string $driver 'sqlite' or 'mysql'
     */
    public function run(string $publicUrl, string $driver, ConsoleIo $io): bool
    {
        $answer = $this->check->reach($publicUrl);

        switch ($answer->outcome) {
            case ProbeOutcome::Redirected:
                $position = strpos($answer->detail, '/install/probe/');
                $target = $position === false ? $answer->detail : substr($answer->detail, 0, $position);
                $io->error($publicUrl . ' は ' . $target . ' に転送されます。--url ' . $target . ' を付けて、もう一度流してください。');

                return false;
            case ProbeOutcome::Refused:
                $io->error('サーバーから ' . $publicUrl . ' を開きましたが、この Re:無事 に届きませんでした（' . $answer->detail . '）。');
                $io->error('URL が正しいか、その URL で公開ディレクトリが公開されているか、ウェブの PHP が 8.3 以上かを確かめてください。');

                return false;
            case ProbeOutcome::Unreachable:
                $web = $this->waitForBrowser($publicUrl, $answer->detail, $io);
                $byBrowser = true;

                break;
            case ProbeOutcome::Reached:
                $web = $answer->web;
                $byBrowser = false;

                break;
        }

        if ($web === null || !$this->webSideIsFit($web, $driver, $io)) {
            return false;
        }

        $base = PackagePath::url($web->packagePath, $publicUrl);

        if ($byBrowser) {
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
