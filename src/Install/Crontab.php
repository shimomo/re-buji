<?php

declare(strict_types=1);

namespace ReBuji\Install;

use Closure;
use RuntimeException;

/**
 * Adds the line that runs bin/cron.php to the user's crontab through the crontab command, keeping every other line
 * as it is and never adding a second line for the same cron.php. The command is run through a closure so tests never
 * touch a real crontab. A PHP that stops proc_open or escapeshellarg (disable_functions) still gets the line to register
 * by hand, not a crash.
 *
 * @author shimomo
 */
final class Crontab
{
    private const string CANNOT_RUN = 'この PHP では proc_open が止められているため、crontab を動かせません。';

    /** @var Closure(list<string>, string): array{int, string, string} */
    private readonly Closure $run;

    private readonly bool $canRun;

    /**
     * @param (Closure(list<string>, string): array{int, string, string})|null $run Runs a command with an input and returns its status, output and error
     * @param bool|null $canRun whether PHP may start a command; null asks PHP, where a function stopped by disable_functions does not exist
     */
    public function __construct(?Closure $run = null, ?bool $canRun = null)
    {
        $this->run = $run ?? self::proc(...);
        $this->canRun = $canRun ?? function_exists('proc_open');
    }

    /**
     * The line that runs cron.php every $minutes minutes.
     */
    public static function line(int $minutes, string $php, string $cronPath): string
    {
        $every = $minutes === 1 ? '*' : '*/' . $minutes;

        return $every . ' * * * * ' . self::quote($php) . ' ' . self::quote($cronPath);
    }

    /**
     * Why the crontab cannot be used, or null when it can.
     */
    public function problem(): ?string
    {
        try {
            $this->read();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Adds $line unless a line that is not a comment already runs $cronPath, as written or quoted as line() writes it.
     * Returns whether it was added. A commented-out line stays, with the new one below it.
     *
     * @throws RuntimeException With the reason when the crontab cannot be read or written
     */
    public function add(string $line, string $cronPath): bool
    {
        $current = $this->read();

        foreach (explode("\n", $current) as $existing) {
            if (str_starts_with(ltrim($existing), '#')) {
                continue;
            }

            if (str_contains($existing, $cronPath) || str_contains($existing, self::quote($cronPath))) {
                return false;
            }
        }

        $body = $current === '' || str_ends_with($current, "\n") ? $current : $current . "\n";
        [$status, , $error] = ($this->run)(['crontab', '-'], $body . $line . "\n");

        if ($status !== 0) {
            $error = trim($error);

            throw new RuntimeException($error !== '' ? $error : 'crontab - が状態 ' . $status . ' で終わりました。');
        }

        return true;
    }

    /**
     * @throws RuntimeException When the crontab cannot be used
     */
    private function read(): string
    {
        if (!$this->canRun) {
            throw new RuntimeException(self::CANNOT_RUN);
        }

        [$status, $output, $error] = ($this->run)(['crontab', '-l'], '');

        if ($status === 0) {
            return $output;
        }

        if (stripos($error, 'no crontab') !== false) {
            return '';
        }

        if ($status === 127) {
            throw new RuntimeException('crontab が見つかりません。');
        }

        $error = trim($error);

        throw new RuntimeException($error !== '' ? $error : 'crontab -l が状態 ' . $status . ' で終わりました。');
    }

    private static function quote(string $value): string
    {
        // As escapeshellarg() quotes, without it: disable_functions may stop it along with proc_open.
        $quoted = preg_match('/\A[A-Za-z0-9_.\/+-]+\z/', $value) === 1 ? $value : "'" . str_replace("'", "'\\''", $value) . "'";

        return str_replace('%', '\\%', $quoted);
    }

    /**
     * @param list<string> $command
     * @return array{int, string, string}
     */
    private static function proc(array $command, string $input): array
    {
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            return [127, '', ''];
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    }
}
