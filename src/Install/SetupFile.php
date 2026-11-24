<?php

declare(strict_types=1);

namespace ReBuji\Install;

use ReBuji\Config\Environment;
use ReBuji\Settings\Settings;
use ReBuji\Settings\SettingsForm;
use RuntimeException;

/**
 * The settings file given to `bin/console install`, written in the names of Docker's .env (see .env.example).
 * Values are read as written: ${VAR} is not expanded.
 *
 * @author shimomo
 */
final readonly class SetupFile
{
    /**
     * The inputs of DatabaseStep, by the name in the settings file.
     */
    public const array DATABASE = [
        'driver' => Environment::DATABASE['db.driver'],
        'mysql_host' => Environment::DATABASE['db.mysql.host'],
        'mysql_port' => Environment::DATABASE['db.mysql.port'],
        'mysql_database' => Environment::DATABASE['db.mysql.database'],
        'mysql_username' => Environment::DATABASE['db.mysql.username'],
        'mysql_password' => Environment::DATABASE['db.mysql.password'],
    ];

    /**
     * Names in .env.example that only the Docker setup uses.
     */
    public const array DOCKER_ONLY = [
        'REBUJI_VERSION',
        'REBUJI_DOMAIN',
        'REBUJI_MAIL_CA_FILE',
        'REBUJI_HTTPS',
        'REBUJI_TRUSTED_PROXIES',
    ];

    private const array PASSWORDS = ['REBUJI_DB_PASSWORD', 'REBUJI_SMTP_PASSWORD', 'REBUJI_IMAP_PASSWORD'];

    /**
     * @param array<string, string> $values the settings the file gives, by name (empty ones left out)
     * @param array<string, string> $resolved the names this reads (without _FILE), with their values
     * @param list<string> $used the names the file gives that this uses, in the file's order
     * @param list<string> $notices
     */
    private function __construct(
        private string $path,
        private array $values,
        private array $resolved,
        private array $used,
        private array $notices,
        private bool $readableByOthers,
    ) {
        //
    }

    public static function read(string $path): self
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException('設定ファイルを読めません：' . $path);
        }

        $parsed = self::parse($contents, $path);
        $permissions = @fileperms($path);

        return new self(
            $parsed->path,
            $parsed->values,
            $parsed->resolved,
            $parsed->used,
            $parsed->notices,
            $permissions !== false && ($permissions & 0077) !== 0,
        );
    }

    public static function parse(string $contents, string $path = ''): self
    {
        /** @var array<string, array{string, int}> $given */
        $given = [];
        $events = [];

        foreach (explode("\n", $contents) as $index => $raw) {
            $line = ltrim(rtrim($raw, "\r"));

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            if (preg_match('/\A([A-Za-z_][A-Za-z0-9_]*)=(.*)\z/s', $line, $match) !== 1) {
                $events[$index] = '設定ファイルの ' . ($index + 1) . ' 行目を読めません。';

                continue;
            }

            $value = self::value($match[2]);

            if ($value === '') {
                unset($given[$match[1]]);
            } else {
                $given[$match[1]] = [$value, $index];
            }
        }

        $reads = array_merge(array_values(self::DATABASE), array_values(Settings::ENVIRONMENT));
        $vars = array_map(static fn(array $entry): string => $entry[0], $given);
        $environment = new Environment($vars);
        $values = [];
        $resolved = [];
        $used = [];

        foreach ($given as $name => [$value, $index]) {
            if (in_array($name, self::DOCKER_ONLY, true)) {
                $events[$index] = '設定ファイルの ' . $name . ' は Docker だけで使う設定なので、使いません。';
            } elseif (in_array($name, $reads, true) || (str_ends_with($name, '_FILE') && in_array(substr($name, 0, -5), $reads, true))) {
                $values[$name] = $value;
                $used[] = $name;
            } elseif (str_starts_with($name, 'REBUJI_')) {
                $events[$index] = '設定ファイルの ' . $name . ' は知らない名前なので、使いません。';
            }
        }

        foreach ($reads as $name) {
            try {
                $found = $environment->get($name);
            } catch (RuntimeException) {
                throw new RuntimeException('設定ファイルの ' . $name . '_FILE が指すファイルを読めません：' . $vars[$name . '_FILE']);
            }

            if ($found !== null && $found !== '') {
                $resolved[$name] = $found;
            }
        }

        ksort($events);

        return new self($path, $values, $resolved, $used, array_values($events), false);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, string>
     */
    public function database(): array
    {
        $database = [];

        foreach (self::DATABASE as $input => $name) {
            if (isset($this->resolved[$name])) {
                $database[$input] = $this->resolved[$name];
            }
        }

        return $database;
    }

    /**
     * @return array<string, string>
     */
    public function mail(): array
    {
        $mail = [];

        foreach (SettingsForm::FIELDS as $field => $setting) {
            $name = Settings::ENVIRONMENT[$setting] ?? null;

            if ($name !== null && isset($this->resolved[$name])) {
                $mail[$field] = $this->resolved[$name];
            }
        }

        return $mail;
    }

    /**
     * @return list<string>
     */
    public function notices(): array
    {
        return $this->notices;
    }

    public function summary(): string
    {
        if ($this->used === []) {
            return '';
        }

        $parts = [];

        foreach ($this->used as $name) {
            $parts[] = $name . '=' . (str_ends_with($name, '_PASSWORD') ? '********' : $this->values[$name]);
        }

        return '設定ファイルから使います：' . implode('、', $parts);
    }

    public function holdsPassword(): bool
    {
        foreach (self::PASSWORDS as $name) {
            if (isset($this->values[$name])) {
                return true;
            }
        }

        return false;
    }

    public function readableByOthers(): bool
    {
        return $this->readableByOthers;
    }

    private static function value(string $raw): string
    {
        $value = trim($raw);

        // A quoted value is what is inside, as written, when only spaces or a comment follow the closing quote.
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $end = strpos($value, $value[0], 1);

            if ($end !== false && preg_match('/\A(?:\s*|\s+#.*)\z/s', substr($value, $end + 1)) === 1) {
                return substr($value, 1, $end - 1);
            }
        }

        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            return substr($value, 1, -1);
        }

        return trim(preg_replace('/(?:\A|\s)#.*\z/s', '', $value) ?? $value);
    }
}
