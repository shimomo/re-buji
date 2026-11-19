<?php

declare(strict_types=1);

namespace ReBuji\Mail\Imap;

/**
 * Why an IMAP connection failed, taken from PHP's warnings: a failed TLS handshake leaves the stream functions' own
 * error message empty, and says why only in a warning.
 *
 * @author shimomo
 */
final class StreamWarnings
{
    /**
     * Calls a stream function with PHP's errors caught instead of shown, as the @ operator would, and keeps its warnings.
     * Under a web server html_errors is on, and PHP would hand the handler the message escaped for HTML ("&quot;"), which
     * the page then escapes again; it is off during the call.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return array{T, string} what the call returned, and its first warning without the function's name, on one line
     */
    public static function catch(callable $call): array
    {
        $warnings = [];
        $html = ini_set('html_errors', '0');
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            // Only a warning says why; a deprecation or a notice on the way is not the reason.
            if ($level === E_WARNING) {
                $warnings[] = $message;
            }

            return true;
        });

        try {
            $result = $call();
        } finally {
            restore_error_handler();

            if ($html !== false) {
                ini_set('html_errors', $html);
            }
        }

        $first = $warnings[0] ?? '';

        return [$result, trim((string) preg_replace(['/^\w+\(\): /', '/\s+/'], ['', ' '], $first))];
    }
}
