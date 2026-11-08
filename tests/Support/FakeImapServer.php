<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use RuntimeException;

/**
 * Runs fake-imap-server.php in its own process for a test, so the IMAP client talks to a real socket.
 *
 * @author shimomo
 */
final class FakeImapServer
{
    /**
     * @param resource $process
     */
    private function __construct(private $process, private readonly int $port, private readonly string $transcript)
    {
        //
    }

    /**
     * @param string $mailDir holds the inbox as {uid}.eml (and {uid}.seen for seen messages)
     * @param array{rejectLogin?: bool} $options
     */
    public static function start(string $mailDir, array $options = []): self
    {
        $transcript = (string) tempnam(sys_get_temp_dir(), 'rebuji-imap-');
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fake-imap-server.php', $mailDir, $transcript, json_encode($options, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the fake IMAP server');
        }

        $port = (int) fgets($pipes[1]);

        // Read stderr only once the script has exited: while it serves, the pipe never ends.
        if ($port <= 0) {
            throw new RuntimeException('The fake IMAP server did not start: ' . (string) stream_get_contents($pipes[2]));
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
     * Every line received (C:) and sent (S:) so far, without message bodies.
     */
    public function transcript(): string
    {
        return (string) file_get_contents($this->transcript);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->transcript);
    }
}
