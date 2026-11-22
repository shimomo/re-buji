<?php

declare(strict_types=1);

namespace ReBuji\Console;

use Override;
use Psr\Container\ContainerInterface;
use ReBuji\Config\ConfigFile;
use ReBuji\Config\Environment;
use ReBuji\Database\Database;
use ReBuji\Paths;
use ReBuji\PrivateFile;
use Throwable;

/**
 * Copies the SQLite database to a file of the owner's alone. MySQL is backed up with mysqldump instead.
 *
 * @author shimomo
 */
final class BackupCommand implements Command
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
        return 'backup';
    }

    #[Override]
    public function summary(): string
    {
        return 'SQLite のデータベースを 1 つのファイルに写す（引数：出力先のパス）';
    }

    #[Override]
    public function run(array $args, ConsoleIo $io): int
    {
        if (count($args) !== 1 || $args[0] === '') {
            $io->error('使い方：console backup <出力先のパス>');

            return 2;
        }

        $path = $args[0];

        try {
            $configFile = $this->container->get(ConfigFile::class);
            assert($configFile instanceof ConfigFile);

            if ($configFile->load()->get('db.driver') === 'mysql') {
                $io->error('MySQL では mysqldump でバックアップを取ってください。');

                return 1;
            }

            if (file_exists($path)) {
                $io->error('ファイルがもうあります：' . $path);

                return 1;
            }

            $db = $this->container->get(Database::class);
            assert($db instanceof Database);

            // An empty file made private first, so the copy is never readable by anyone else, even briefly.
            if (!PrivateFile::create($path)) {
                $io->error('ファイルを作れません：' . $path);

                return 1;
            }

            try {
                $db->execute('VACUUM INTO ?', [$path]);
            } catch (Throwable $exception) {
                @unlink($path);

                throw $exception;
            }
        } catch (Throwable $exception) {
            $io->error('バックアップを作れませんでした：' . $exception->getMessage());

            return 1;
        }

        $io->line('バックアップを作りました：' . $path);

        return 0;
    }
}
