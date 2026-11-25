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
    private const string SHOWN = '  この PHP では shell_exec が止められているため、打ったパスワードが画面に見えます。';

    private bool $ended = false;

    private readonly bool $terminal;

    private readonly bool $canHide;

    private bool $toldShown = false;

    /**
     * @param resource $in
     * @param resource $out
     * @param resource $err
     * @param bool|null $terminal whether $in is a terminal; null asks the stream
     * @param bool|null $canHide whether typing can be hidden, which runs stty through shell_exec; null asks PHP, where a
     *                           function stopped by disable_functions does not exist
     */
    public function __construct(private $in, private $out, private $err, ?bool $terminal = null, ?bool $canHide = null)
    {
        $this->terminal = $terminal ?? stream_isatty($in);
        $this->canHide = $canHide ?? function_exists('shell_exec');
    }

    public static function process(): self
    {
        return new self(self::open('php://stdin', 'r'), self::open('php://stdout', 'w'), self::open('php://stderr', 'w'));
    }

    /**
     * Memory streams: feed input with $input, read what was written with output() and errors(). $terminal and $canHide
     * stand in for a terminal and for a PHP that can or cannot hide typing.
     */
    public static function memory(string $input = '', bool $terminal = false, bool $canHide = true): self
    {
        $in = self::open('php://memory', 'w+');
        fwrite($in, $input);
        rewind($in);

        return new self($in, self::open('php://memory', 'w+'), self::open('php://memory', 'w+'), $terminal, $canHide);
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
     * Like ask(), but a terminal does not show what is typed. Where PHP cannot run stty (shell_exec is disabled), it says
     * once that typing shows and asks as ask() does, rather than stopping: the first admin's password has no other way in.
     */
    public function secret(string $prompt): string
    {
        if (!$this->interactive()) {
            return $this->ask($prompt);
        }

        if (!$this->canHide) {
            if (!$this->toldShown) {
                $this->line(self::SHOWN);
                $this->toldShown = true;
            }

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
        return $this->terminal;
    }

    /**
     * Whether a read found no more input. Unlike an empty line, which is an answer, it means nobody is left to answer.
     */
    public function ended(): bool
    {
        return $this->ended;
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

        if ($line === false) {
            $this->ended = true;

            return '';
        }

        return rtrim($line, "\r\n");
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
