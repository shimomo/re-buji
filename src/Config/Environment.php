<?php

declare(strict_types=1);

namespace ReBuji\Config;

use RuntimeException;

/**
 * REBUJI_* environment variables. A secret may also come from a file named by "<NAME>_FILE" (Docker secrets).
 *
 * @author shimomo
 */
final readonly class Environment
{
    public const array DATABASE = [
        'db.driver' => 'REBUJI_DB_DRIVER',
        'db.mysql.host' => 'REBUJI_DB_HOST',
        'db.mysql.port' => 'REBUJI_DB_PORT',
        'db.mysql.database' => 'REBUJI_DB_NAME',
        'db.mysql.username' => 'REBUJI_DB_USERNAME',
        'db.mysql.password' => 'REBUJI_DB_PASSWORD',
    ];

    /**
     * @param array<string, string> $vars
     */
    public function __construct(private array $vars)
    {
        //
    }

    public static function fromProcess(): self
    {
        return new self(getenv());
    }

    public function enabled(): bool
    {
        return ($this->vars['REBUJI_ENV_CONFIG'] ?? '') === '1';
    }

    public function get(string $name): ?string
    {
        if (array_key_exists($name, $this->vars)) {
            return $this->vars[$name];
        }

        $fileName = $name . '_FILE';

        if (!array_key_exists($fileName, $this->vars)) {
            return null;
        }

        $path = $this->vars[$fileName];
        $contents = is_file($path) ? @file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException('環境変数 ' . $fileName . ' のファイルを読めません：' . $path);
        }

        // Drop one trailing newline, as editors and `echo` add it.
        return preg_replace('/\r?\n\z/', '', $contents) ?? $contents;
    }

    /**
     * @return array<string, string|int>
     */
    public function databaseConfig(): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $driver = $this->get(self::DATABASE['db.driver']) ?? 'sqlite';

        if ($driver !== 'mysql') {
            return ['db.driver' => $driver, 'db.sqlite.path' => 'database.sqlite'];
        }

        $config = ['db.driver' => 'mysql'];

        foreach (self::DATABASE as $key => $name) {
            if ($key === 'db.driver') {
                continue;
            }

            $value = $this->get($name);

            if ($key === 'db.mysql.port') {
                $config[$key] = $value === null || $value === '' ? 3306 : (int) $value;
            } elseif ($value !== null) {
                $config[$key] = $value;
            }
        }

        return $config;
    }
}
