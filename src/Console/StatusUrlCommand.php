<?php

declare(strict_types=1);

namespace ReBuji\Console;

use Override;
use Psr\Container\ContainerInterface;
use ReBuji\Config\ConfigFile;
use ReBuji\Config\Environment;
use ReBuji\Paths;

/**
 * Prints the status URL for the monitor to watch, given the address the copy is served at.
 *
 * @author shimomo
 */
final class StatusUrlCommand implements Command
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Environment $environment,
        private readonly ContainerInterface $container,
    ) {
        //
    }

    #[Override]
    public function name(): string
    {
        return 'status-url';
    }

    #[Override]
    public function summary(): string
    {
        return '外から見張るための状態の URL を出す（引数：ベースURL）';
    }

    #[Override]
    public function run(array $args, ConsoleIo $io): int
    {
        if (count($args) !== 1 || $args[0] === '') {
            $io->error('使い方：console status-url <ベースURL>（例：https://anpi.example.jp）');

            return 2;
        }

        $configFile = new ConfigFile($this->paths->storage('config.php'), $this->environment);
        $token = $configFile->exists() ? $configFile->load()->get('status.token') : null;

        if (!is_string($token) || $token === '') {
            $io->error('まだ boot が済んでいません。');

            return 1;
        }

        $io->line((preg_replace('#/\z#', '', $args[0]) ?? $args[0]) . '/status/' . $token);

        return 0;
    }
}
