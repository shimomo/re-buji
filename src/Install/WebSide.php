<?php

declare(strict_types=1);

namespace ReBuji\Install;

use JsonException;
use ReBuji\Paths;

/**
 * What the web server's PHP sees of this copy: where the package is served, which PDO drivers it has and whether it
 * can write to storage/. The console's PHP may differ from it, so the console asks the web side through the probe.
 *
 * @author shimomo
 */
final readonly class WebSide
{
    /**
     * @param list<string> $drivers the PDO drivers the web PHP has ('sqlite', 'mysql')
     */
    public function __construct(public ?string $packagePath, public array $drivers, public bool $storageWritable)
    {
        //
    }

    /**
     * @param array<array-key, mixed> $server
     */
    public static function detect(array $server, Paths $paths): self
    {
        $drivers = array_values(array_filter(['sqlite', 'mysql'], static fn(string $driver): bool => extension_loaded('pdo_' . $driver)));

        return new self(
            PackagePath::detect($server, $paths->root()),
            $drivers,
            is_dir($paths->storage()) && is_writable($paths->storage()),
        );
    }

    public function answer(string $nonce): string
    {
        return json_encode([
            'nonce' => $nonce,
            'package_path' => $this->packagePath,
            'drivers' => $this->drivers,
            'storage_writable' => $this->storageWritable,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The web side described by $body, only when the body is for $nonce and every field has its type.
     */
    public static function fromAnswer(string $body, string $nonce): ?self
    {
        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data) || ($data['nonce'] ?? null) !== $nonce) {
            return null;
        }

        $path = $data['package_path'] ?? null;
        $drivers = $data['drivers'] ?? null;
        $writable = $data['storage_writable'] ?? null;

        if (($path !== null && !is_string($path)) || !is_array($drivers) || !array_is_list($drivers) || !is_bool($writable)) {
            return null;
        }

        $names = [];

        foreach ($drivers as $driver) {
            if (!is_string($driver)) {
                return null;
            }

            $names[] = $driver;
        }

        return new self($path, $names, $writable);
    }
}
