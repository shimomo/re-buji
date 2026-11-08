<?php

declare(strict_types=1);

namespace ReBuji;

use DI\ContainerBuilder;
use JmaXml\Feed\FeedClient;
use JmaXml\Feed\StateStore;
use League\Plates\Engine;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use ReBuji\Config\ConfigFile;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Feed\DatabaseStateStore;
use ReBuji\Http\CsrfMiddlewareFactory;
use ReBuji\Http\ErrorHandler;
use ReBuji\Http\Flash;
use ReBuji\Http\InstalledGate;
use ReBuji\Http\NativeSession;
use ReBuji\Http\Session;
use ReBuji\Http\Url;
use ReBuji\Log\FileLogger;
use ReBuji\Mail\Imap\ImapMailbox;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\MailSender;
use ReBuji\Mail\SmtpMailSender;
use ReBuji\Settings\Settings;
use Slim\Csrf\Guard;
use Slim\Psr7\Factory\ResponseFactory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * The service container. Tests and later plans replace entries through $overrides.
 *
 * @author shimomo
 */
final class Container
{
    /**
     * @param array<string, mixed> $overrides
     */
    public static function build(Paths $paths, array $overrides = []): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            Paths::class => $paths,
            'basePath' => '',
            'migrations' => static fn(): array => Migrations::all(),
            Url::class => static fn(ContainerInterface $c): Url => new Url((string) $c->get('basePath')),
            ConfigFile::class => static fn(): ConfigFile => new ConfigFile($paths->storage('config.php')),
            ClockInterface::class => static fn(): ClockInterface => new SystemClock(),
            LoggerInterface::class => static fn(ContainerInterface $c): LoggerInterface => new FileLogger($paths->storage('logs/re-buji.log'), self::clock($c)),
            Session::class => static fn(): Session => new NativeSession($paths->storage('sessions'), self::isHttps()),
            ResponseFactoryInterface::class => static fn(): ResponseFactoryInterface => new ResponseFactory(),
            RequestFactoryInterface::class => static fn(): RequestFactoryInterface => new Psr17Factory(),
            ClientInterface::class => static fn(): ClientInterface => new Psr18Client(HttpClient::create(['timeout' => 10, 'max_redirects' => 0]), new Psr17Factory(), new Psr17Factory()),
            Database::class => static function (ContainerInterface $c) use ($paths): Database {
                $configFile = $c->get(ConfigFile::class);
                assert($configFile instanceof ConfigFile);

                return Database::fromConfig($configFile->load(), $paths);
            },
            Migrator::class => static function (ContainerInterface $c): Migrator {
                $db = $c->get(Database::class);
                $migrations = $c->get('migrations');
                assert($db instanceof Database && is_array($migrations));

                /** @var list<\ReBuji\Database\Migration> $migrations */
                return new Migrator($db, $migrations, self::clock($c));
            },
            StateStore::class => static function (ContainerInterface $c): StateStore {
                $store = $c->get(DatabaseStateStore::class);
                assert($store instanceof DatabaseStateStore);

                return $store;
            },
            FeedClient::class => static function (ContainerInterface $c): FeedClient {
                $http = $c->get(ClientInterface::class);
                $requests = $c->get(RequestFactoryInterface::class);
                $store = $c->get(StateStore::class);
                assert($http instanceof ClientInterface && $requests instanceof RequestFactoryInterface && $store instanceof StateStore);

                return new FeedClient($http, $requests, $store, self::clock($c), 'Re-Buji/' . Version::CURRENT . ' (+https://github.com/shimomo/re-buji)');
            },
            Mailbox::class => static function (ContainerInterface $c): Mailbox {
                $settings = $c->get(Settings::class);
                assert($settings instanceof Settings);

                return new ImapMailbox($settings->imap());
            },
            MailSender::class => static function (ContainerInterface $c): MailSender {
                $settings = $c->get(Settings::class);
                assert($settings instanceof Settings);

                return new SmtpMailSender($settings->smtp());
            },
            SystemState::class => static function (ContainerInterface $c): SystemState {
                $db = $c->get(Database::class);
                assert($db instanceof Database);

                return new SystemState($db, self::clock($c));
            },
            Engine::class => static function (ContainerInterface $c) use ($paths): Engine {
                $url = $c->get(Url::class);
                assert($url instanceof Url);
                $engine = new Engine($paths->templates());
                $engine->registerFunction('url', static fn(string $path): string => $url->to($path));
                $engine->registerFunction('flash', static function () use ($c): ?string {
                    $flash = $c->get(Flash::class);
                    assert($flash instanceof Flash);

                    return $flash->take();
                });
                $engine->registerFunction('csrf', static function () use ($c): string {
                    $guard = $c->get(Guard::class);
                    assert($guard instanceof Guard);
                    $name = $guard->getTokenName();
                    $value = $guard->getTokenValue();

                    if ($name === null || $value === null) {
                        $token = $guard->generateToken();
                        $name = (string) $token[$guard->getTokenNameKey()];
                        $value = (string) $token[$guard->getTokenValueKey()];
                    }

                    return sprintf(
                        '<input type="hidden" name="%s" value="%s"><input type="hidden" name="%s" value="%s">',
                        htmlspecialchars($guard->getTokenNameKey()),
                        htmlspecialchars($name),
                        htmlspecialchars($guard->getTokenValueKey()),
                        htmlspecialchars($value),
                    );
                });

                return $engine;
            },
            Guard::class => static function (ContainerInterface $c): Guard {
                $responses = $c->get(ResponseFactoryInterface::class);
                $session = $c->get(Session::class);
                $templates = $c->get(Engine::class);
                assert($responses instanceof ResponseFactoryInterface && $session instanceof Session && $templates instanceof Engine);

                return CsrfMiddlewareFactory::create($responses, $session, $templates);
            },
            InstalledGate::class => static function (ContainerInterface $c): InstalledGate {
                $configFile = $c->get(ConfigFile::class);
                $url = $c->get(Url::class);
                $responses = $c->get(ResponseFactoryInterface::class);
                assert($configFile instanceof ConfigFile && $url instanceof Url && $responses instanceof ResponseFactoryInterface);

                return new InstalledGate($configFile, $url, $responses);
            },
            ErrorHandler::class => static function (ContainerInterface $c): ErrorHandler {
                $responses = $c->get(ResponseFactoryInterface::class);
                $templates = $c->get(Engine::class);
                $logger = $c->get(LoggerInterface::class);
                assert($responses instanceof ResponseFactoryInterface && $templates instanceof Engine && $logger instanceof LoggerInterface);

                return new ErrorHandler($responses, $templates, $logger);
            },
        ]);
        $builder->addDefinitions($overrides);

        return $builder->build();
    }

    private static function clock(ContainerInterface $c): ClockInterface
    {
        $clock = $c->get(ClockInterface::class);
        assert($clock instanceof ClockInterface);

        return $clock;
    }

    private static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        return $https !== '' && strtolower($https) !== 'off';
    }
}
