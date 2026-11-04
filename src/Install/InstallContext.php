<?php

declare(strict_types=1);

namespace ReBuji\Install;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Config\Config;
use ReBuji\Config\ConfigFile;
use ReBuji\Http\Session;
use ReBuji\Http\Url;
use ReBuji\Paths;

/**
 * What a step can see and change: the request, the half-filled values and finished steps kept in the session, and the
 * config file.
 *
 * @author shimomo
 */
final class InstallContext
{
    private const string DRAFT = 'install.draft';

    private const string COMPLETED = 'install.completed';

    public function __construct(
        public readonly Paths $paths,
        public readonly ConfigFile $configFile,
        public readonly ContainerInterface $container,
        public readonly ServerRequestInterface $request,
        public readonly Url $url,
        private readonly Session $session,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function draft(): array
    {
        return self::stringKeyed($this->session->get(self::DRAFT));
    }

    public function putDraft(string $key, mixed $value): void
    {
        $this->session->set(self::DRAFT, [$key => $value] + $this->draft());
    }

    public function completed(string $stepKey): bool
    {
        return in_array($stepKey, $this->completedSteps(), true);
    }

    public function markCompleted(string $stepKey): void
    {
        $this->session->set(self::COMPLETED, array_values(array_unique([...$this->completedSteps(), $stepKey])));
    }

    public function forgetProgress(): void
    {
        $this->session->remove(self::DRAFT);
        $this->session->remove(self::COMPLETED);
    }

    public function config(): Config
    {
        return $this->configFile->exists() ? $this->configFile->load() : new Config([]);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function saveConfig(array $values): void
    {
        $this->configFile->save(new Config($values + $this->config()->all()));
    }

    public function isHttps(): bool
    {
        return self::isOn($this->request->getServerParams()['HTTPS'] ?? '');
    }

    /**
     * Scheme, host and base path of this copy, for URLs shown to the owner.
     */
    public function baseUrl(): string
    {
        $uri = $this->request->getUri();
        $port = $uri->getPort();

        return $uri->getScheme() . '://' . $uri->getHost() . ($port === null ? '' : ':' . $port) . $this->url->basePath();
    }

    /**
     * @return list<string>
     */
    private function completedSteps(): array
    {
        return self::stringList($this->session->get(self::COMPLETED));
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyed(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $keyed = [];

        foreach (array_keys($value) as $key) {
            if (is_string($key)) {
                /** @psalm-suppress MixedAssignment the draft holds whatever the steps put in it */
                $keyed[$key] = $value[$key];
            }
        }

        return $keyed;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private static function isOn(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strtolower($value) !== 'off';
    }
}
