<?php

declare(strict_types=1);

namespace ReBuji\Console;

/**
 * One command of bin/console. A command class is built with (Paths, Environment, ContainerInterface) and registered by
 * adding it to Console::COMMANDS.
 *
 * @author shimomo
 */
interface Command
{
    public function name(): string;

    /**
     * One line for the command list, in Japanese.
     */
    public function summary(): string;

    /**
     * @param list<string> $args what follows the command name
     *
     * @return int the exit status
     */
    public function run(array $args, ConsoleIo $io): int;
}
