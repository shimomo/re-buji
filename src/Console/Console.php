<?php

declare(strict_types=1);

namespace ReBuji\Console;

use Psr\Container\ContainerInterface;
use ReBuji\Config\Environment;
use ReBuji\Paths;
use Throwable;

/**
 * bin/console: runs the named command. Services come from the container, which builds them only when a command asks.
 *
 * @author shimomo
 */
final class Console
{
    /**
     * Every command. Add a class here to register one.
     *
     * @var list<class-string<Command>>
     */
    public const array COMMANDS = [
        BootCommand::class,
        AdminCreateCommand::class,
        StatusUrlCommand::class,
        CheckCommand::class,
        BackupCommand::class,
        UpgradeInstallCommand::class,
        InstallCommand::class,
    ];

    public function __construct(
        private readonly Paths $paths,
        private readonly Environment $environment,
        private readonly ContainerInterface $container,
    ) {
        //
    }

    /**
     * @param list<string> $argv the arguments after the script name
     */
    public function run(array $argv, ConsoleIo $io): int
    {
        $name = $argv[0] ?? 'help';

        if ($name === 'help') {
            $this->help($io);

            return 0;
        }

        foreach ($this->commands() as $command) {
            if ($command->name() === $name) {
                try {
                    return $command->run(array_slice($argv, 1), $io);
                } catch (Throwable $exception) {
                    $this->explain($exception, $io);

                    return 1;
                }
            }
        }

        $io->error('知らないコマンドです：' . $name);
        $this->help($io);

        return 2;
    }

    /**
     * @return list<Command>
     */
    private function commands(): array
    {
        return array_map(
            fn(string $class): Command => new $class($this->paths, $this->environment, $this->container),
            self::COMMANDS,
        );
    }

    /**
     * An error no command expected, in two lines instead of PHP's stack trace: what went wrong, then its kind and place
     * to copy into a report. No trace, and nothing logged: the logger may fail for the same reason (storage/ cannot be
     * written), and the two lines on the screen are enough to report.
     */
    private function explain(Throwable $exception, ConsoleIo $io): void
    {
        $root = $this->paths->root() . '/';
        $file = $exception->getFile();
        $place = str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;

        $io->error('✕ 思わぬエラーで止まりました：' . $exception->getMessage());
        $io->error('  （' . $exception::class . '、' . $place . ':' . $exception->getLine() . '）');
    }

    private function help(ConsoleIo $io): void
    {
        $io->line('使い方：console <コマンド> [引数]');
        $io->line('');

        foreach ($this->commands() as $command) {
            $io->line(sprintf('  %-14s %s', $command->name(), $command->summary()));
        }
    }
}
