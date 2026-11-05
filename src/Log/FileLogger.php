<?php

declare(strict_types=1);

namespace ReBuji\Log;

use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use ReBuji\Time;
use Stringable;
use Throwable;

/**
 * Appends one line per entry: time (UTC), level, message and the context as JSON.
 *
 * @author shimomo
 */
final class FileLogger extends AbstractLogger
{
    public function __construct(private readonly string $path, private readonly ClockInterface $clock)
    {
        //
    }

    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $context['exception'] = self::describe($context['exception']);
        }

        $line = sprintf(
            "%s %s %s %s\n",
            Time::format($this->clock->now()),
            strtoupper(is_string($level) ? $level : 'log'),
            (string) $message,
            (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
        );

        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }

    private static function describe(Throwable $exception): string
    {
        return $exception::class . ': ' . $exception->getMessage() . ' at ' . $exception->getFile() . ':' . $exception->getLine();
    }
}
