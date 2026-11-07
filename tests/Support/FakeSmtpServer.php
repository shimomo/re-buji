<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use RuntimeException;

/**
 * Runs fake-smtp-server.php in its own process for a test, so the real SMTP transport talks to a real socket.
 *
 * @author shimomo
 */
final class FakeSmtpServer
{
    /**
     * @param resource $process
     */
    private function __construct(private $process, private readonly int $port, private readonly string $transcript)
    {
        //
    }

    /**
     * @param array{rejectRecipients?: list<string>, deferRecipients?: list<string>, policyRecipients?: list<string>, rejectAuth?: bool} $options
     */
    public static function start(array $options = []): self
    {
        $transcript = (string) tempnam(sys_get_temp_dir(), 'rebuji-smtp-');
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fake-smtp-server.php', $transcript, json_encode($options, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the fake SMTP server');
        }

        $port = (int) fgets($pipes[1]);

        // Read stderr only once the script has exited: while it serves, the pipe never ends.
        if ($port <= 0) {
            throw new RuntimeException('The fake SMTP server did not start: ' . (string) stream_get_contents($pipes[2]));
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        return new self($process, $port, $transcript);
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Every line received (C:) and sent (S:) so far.
     */
    public function transcript(): string
    {
        return (string) file_get_contents($this->transcript);
    }

    /**
     * The headers of the last message received, unfolded and decoded.
     *
     * @return array<array-key, string>
     */
    public function lastHeaders(): array
    {
        $lines = explode("\n", $this->transcript());
        $start = null;

        foreach ($lines as $index => $line) {
            if ($line === 'S: 354 End data with <CR><LF>.<CR><LF>') {
                $start = $index + 1;
            }
        }

        if ($start === null) {
            return [];
        }

        $headers = [];

        for ($i = $start; $i < count($lines) && $lines[$i] !== 'C: '; $i++) {
            $headers[] = substr($lines[$i], 3);
        }

        $decoded = iconv_mime_decode_headers(implode("\r\n", $headers), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return array_map(self::text(...), is_array($decoded) ? $decoded : []);
    }

    private static function text(mixed $value): string
    {
        return is_array($value) ? implode(', ', array_map(strval(...), $value)) : (string) $value;
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->transcript);
    }
}
