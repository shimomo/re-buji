<?php

declare(strict_types=1);

namespace ReBuji\Config;

use RuntimeException;

/**
 * The settings file (storage/config.php): a PHP file returning an array, readable only by its owner.
 *
 * @author shimomo
 */
final class ConfigFile
{
    public function __construct(private readonly string $path)
    {
        //
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function load(): Config
    {
        if (!$this->exists()) {
            throw new RuntimeException('Config file not found: ' . $this->path);
        }

        /** @psalm-suppress UnresolvableInclude */
        $values = require $this->path;

        if (!is_array($values)) {
            throw new RuntimeException('Config file does not return an array: ' . $this->path);
        }

        /** @var array<string, mixed> $values */
        return new Config($values);
    }

    /**
     * Writes a temporary file next to the target and renames it, so a reader never sees half a file.
     */
    public function save(Config $config): void
    {
        $temporary = $this->path . '.' . bin2hex(random_bytes(6));
        $contents = "<?php\n\nreturn " . var_export($config->all(), true) . ";\n";

        if (file_put_contents($temporary, $contents) === false || !chmod($temporary, 0600) || !rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new RuntimeException('Cannot write config file: ' . $this->path);
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->path, true);
        }
    }
}
