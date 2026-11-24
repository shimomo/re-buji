<?php

declare(strict_types=1);

namespace ReBuji\Tests\Auth;

use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Auth\AdminRepository;
use ReBuji\Auth\PasswordPolicy;
use ReBuji\Database\Database;
use ReBuji\Install\Steps\AdminStep;
use ReBuji\Mail\Imap\ImapSettings;
use ReBuji\Settings\SmtpSettings;
use ReBuji\Tests\Support\FrozenClock;
use ReflectionMethod;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * @author shimomo
 */
final class SensitiveParametersTest extends TestCase
{
    #[Test]
    public function marksEveryPasswordParameterSensitive(): void
    {
        $parameters = [
            [SmtpSettings::class, '__construct', 'password'],
            [ImapSettings::class, '__construct', 'password'],
            [Database::class, 'mysql', 'password'],
            [AdminRepository::class, 'create', 'password'],
            [AdminRepository::class, 'setPassword', 'password'],
            [PasswordPolicy::class, 'validate', 'password'],
            [AdminStep::class, 'validate', 'password'],
            [AdminStep::class, 'validate', 'confirmation'],
        ];

        foreach ($parameters as [$class, $method, $name]) {
            $found = array_values(array_filter((new ReflectionMethod($class, $method))->getParameters(), static fn($parameter): bool => $parameter->getName() === $name));

            $this->assertCount(1, $found, $class . '::' . $method . ' has no $' . $name);
            $this->assertNotSame([], $found[0]->getAttributes(SensitiveParameter::class), $class . '::' . $method . ' $' . $name);
        }
    }

    #[Test]
    public function keepsTheAdminPasswordOutOfAnExceptionsTrace(): void
    {
        // Servers whose php.ini keeps the arguments in traces would print them with an uncaught exception.
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            // No admins table: create() fails while it holds the password.
            (new AdminRepository(Database::sqliteInMemory(), new FrozenClock()))->create('管理', 'admin@example.jp', 'long-enough-pass');
            $this->fail('create() did not throw');
        } catch (PDOException $exception) {
            $this->assertStringNotContainsString('long-enough-pas', $exception->getTraceAsString(), 'getTraceAsString() cuts strings at 15 characters');
            $frames = array_values(array_filter($exception->getTrace(), static fn(array $frame): bool => ($frame['function'] ?? '') === 'create'));
            $this->assertCount(1, $frames);
            $this->assertInstanceOf(SensitiveParameterValue::class, $frames[0]['args'][2] ?? null);
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }
}
