<?php

declare(strict_types=1);

namespace ReBuji\Console;

use RuntimeException;

/**
 * The streams a command talks through, so tests can use memory instead of a terminal.
 *
 * @author shimomo
 */
final class ConsoleIo
{
    /**
     * @param resource $in
     * @param resource $out
     * @param resource $err
     */
    public function __construct(private $in, private $out, private $err)
    {
        //
    }

    public static function process(): self
    {
        return new self(self::open('php://stdin', 'r'), self::open('php://stdout', 'w'), self::open('php://stderr', 'w'));
    }

    /**
     * Memory streams: feed input with $input, read what was written with output() and errors().
     */
    public static function memory(string $input = ''): self
    {
        $in = self::open('php://memory', 'w+');
        fwrite($in, $input);
        rewind($in);

        return new self($in, self::open('php://memory', 'w+'), self::open('php://memory', 'w+'));
    }

    public function line(string $text): void
    {
        fwrite($this->out, $text . "\n");
    }

    public function error(string $text): void
    {
        fwrite($this->err, $text . "\n");
    }

    public function ask(string $prompt): string
    {
        fwrite($this->out, $prompt);

        return $this->readLine();
    }

    /**
     * Like ask(), but a terminal does not show what is typed.
     */
    public function secret(string $prompt): string
    {
        if (!$this->interactive()) {
            return $this->ask($prompt);
        }

        fwrite($this->out, $prompt);
        // A fixed command with no input from outside.
        /** @psalm-suppress ForbiddenCode */
        shell_exec('stty -echo');

        try {
            $value = $this->readLine();
        } finally {
            /** @psalm-suppress ForbiddenCode */
            shell_exec('stty echo');
            fwrite($this->out, "\n");
        }

        return $value;
    }

    public function interactive(): bool
    {
        return stream_isatty($this->in);
    }

    public function output(): string
    {
        return $this->contents($this->out);
    }

    public function errors(): string
    {
        return $this->contents($this->err);
    }

    private function readLine(): string
    {
        $line = fgets($this->in);

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    /**
     * @param resource $stream
     */
    private function contents(mixed $stream): string
    {
        $position = ftell($stream);
        rewind($stream);
        $contents = (string) stream_get_contents($stream);

        if ($position !== false) {
            fseek($stream, $position);
        }

        return $contents;
    }

    /**
     * @return resource
     */
    private static function open(string $target, string $mode)
    {
        $stream = fopen($target, $mode);

        if ($stream === false) {
            throw new RuntimeException('Cannot open ' . $target);
        }

        return $stream;
    }
}
