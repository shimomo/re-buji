<?php

declare(strict_types=1);

namespace ReBuji\Database;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use ReBuji\Config\Config;
use ReBuji\Paths;
use Throwable;

/**
 * A thin layer over PDO that works the same on SQLite and MySQL.
 *
 * SQLite runs with the rollback journal (journal_mode = DELETE), not WAL: WAL corrupts databases on network file
 * systems, which a rental server's home directory may be.
 *
 * @author shimomo
 */
final class Database
{
    private function __construct(private readonly PDO $pdo, private readonly string $driver)
    {
        //
    }

    public static function fromConfig(Config $config, Paths $paths): self
    {
        return match ($config->get('db.driver')) {
            'sqlite' => self::sqliteFile($paths->storage(self::string($config, 'db.sqlite.path'))),
            'mysql' => self::mysql(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    self::string($config, 'db.mysql.host'),
                    (int) $config->get('db.mysql.port', 3306),
                    self::string($config, 'db.mysql.database'),
                ),
                self::string($config, 'db.mysql.username'),
                (string) $config->get('db.mysql.password', ''),
            ),
            default => throw new InvalidArgumentException('Unknown db.driver'),
        };
    }

    public static function sqliteFile(string $path): self
    {
        return self::sqlite('sqlite:' . $path);
    }

    public static function sqliteInMemory(): self
    {
        return self::sqlite('sqlite::memory:');
    }

    public static function mysql(string $dsn, string $username, string $password): self
    {
        // MYSQL_ATTR_FOUND_ROWS: UPDATE reports matched rows, so writing the same value again is not "0 rows".
        $pdo = new PDO($dsn, $username, $password, self::options() + [PDO::MYSQL_ATTR_FOUND_ROWS => true]);

        return new self($pdo, 'mysql');
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * @param array<string|int, scalar|null> $params
     *
     * @return int the number of affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * @param array<string|int, scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string|int, scalar|null> $params
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, scalar|null> $params
     */
    public function fetchValue(string $sql, array $params = []): int|float|string|bool|null
    {
        /** @var int|float|string|bool|null|false $value */
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @template T
     *
     * @param callable(self): T $callback
     *
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private static function sqlite(string $dsn): self
    {
        $pdo = new PDO($dsn, null, null, self::options());
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');

        return new self($pdo, 'sqlite');
    }

    /**
     * @return array<int, int>
     */
    private static function options(): array
    {
        return [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    }

    private static function string(Config $config, string $key): string
    {
        $value = $config->get($key);

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException($key . ' is not set');
        }

        return $value;
    }

    /**
     * @param array<string|int, scalar|null> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }
}
