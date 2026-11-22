<?php

declare(strict_types=1);

namespace ReBuji\Console;

use Psr\Container\ContainerInterface;
use ReBuji\Config\Environment;
use ReBuji\Paths;

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
                return $command->run(array_slice($argv, 1), $io);
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

    private function help(ConsoleIo $io): void
    {
        $io->line('使い方：console <コマンド> [引数]');
        $io->line('');

        foreach ($this->commands() as $command) {
            $io->line(sprintf('  %-14s %s', $command->name(), $command->summary()));
        }
    }
}
