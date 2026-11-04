<?php

declare(strict_types=1);

namespace ReBuji\Install\Steps;

use Override;
use Psr\Clock\ClockInterface;
use ReBuji\Config\Config;
use ReBuji\Database\Database;
use ReBuji\Database\Migration;
use ReBuji\Database\Migrator;
use ReBuji\Install\InstallContext;
use ReBuji\Install\InstallStep;
use ReBuji\Install\StepResult;
use Throwable;

/**
 * Chooses SQLite or MySQL, tries the connection and creates the tables. Nothing is written to the config file until
 * both succeed.
 *
 * @author shimomo
 */
final class DatabaseStep implements InstallStep
{
    public function __construct(private readonly ClockInterface $clock)
    {
        //
    }

    #[Override]
    public function key(): string
    {
        return 'database';
    }

    #[Override]
    public function title(): string
    {
        return 'データベース';
    }

    #[Override]
    public function template(): string
    {
        return 'database';
    }

    #[Override]
    public function viewData(InstallContext $context): array
    {
        return [
            'sqlite' => extension_loaded('pdo_sqlite'),
            'mysql' => extension_loaded('pdo_mysql'),
            'values' => $context->draft(),
        ];
    }

    #[Override]
    public function submit(InstallContext $context, array $input): StepResult
    {
        $driver = $input['driver'] ?? '';

        foreach (['driver', 'mysql_host', 'mysql_port', 'mysql_database', 'mysql_username'] as $field) {
            $context->putDraft($field, $input[$field] ?? '');
        }

        if ($driver !== 'sqlite' && $driver !== 'mysql') {
            return StepResult::failed(['SQLite か MySQL を選んでください。']);
        }

        if (!extension_loaded('pdo_' . $driver)) {
            return StepResult::failed(['データベースに接続できません。この PHP には pdo_' . $driver . ' がありません。']);
        }

        $host = trim($input['mysql_host'] ?? '');
        $database = trim($input['mysql_database'] ?? '');
        $username = trim($input['mysql_username'] ?? '');
        $password = $input['mysql_password'] ?? '';

        if ($driver === 'mysql' && ($host === '' || $database === '' || $username === '')) {
            return StepResult::failed(['MySQL のホスト、データベース名、ユーザー名を入れてください。']);
        }

        $values = $driver === 'sqlite'
            ? ['db.driver' => 'sqlite', 'db.sqlite.path' => 'database.sqlite']
            : [
                'db.driver' => 'mysql',
                'db.mysql.host' => $host,
                'db.mysql.port' => (int) (($input['mysql_port'] ?? '') === '' ? 3306 : $input['mysql_port']),
                'db.mysql.database' => $database,
                'db.mysql.username' => $username,
                'db.mysql.password' => $password,
            ];

        try {
            $db = Database::fromConfig(new Config($values), $context->paths);
            /** @var list<Migration> $migrations */
            $migrations = $context->container->get('migrations');
            (new Migrator($db, $migrations, $this->clock))->migrate();
        } catch (Throwable $exception) {
            $reason = $exception->getMessage();

            if ($password !== '') {
                $reason = str_replace($password, '********', $reason);
            }

            return StepResult::failed(['データベースに接続できません。' . $reason]);
        }

        $context->saveConfig($values);

        return StepResult::done();
    }
}
