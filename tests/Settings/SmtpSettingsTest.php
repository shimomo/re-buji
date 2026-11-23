<?php

declare(strict_types=1);

namespace ReBuji\Tests\Settings;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Settings\SmtpSettings;

/**
 * @author shimomo
 */
final class SmtpSettingsTest extends TestCase
{
    #[Test]
    public function seesTheSameSettingsAsTheSame(): void
    {
        $this->assertFalse(SmtpSettings::differ(self::settings(), self::settings()));
        $this->assertFalse(SmtpSettings::differ(null, null));
    }

    #[Test]
    public function tellsAPasswordOfDigitsApart(): void
    {
        $this->assertTrue(SmtpSettings::differ(self::settings(password: '0123'), self::settings(password: '123')));
        $this->assertTrue(SmtpSettings::differ(self::settings(password: '1e3'), self::settings(password: '1000')));
    }

    #[Test]
    public function tellsAnyFieldApart(): void
    {
        $this->assertTrue(SmtpSettings::differ(self::settings(port: 587), self::settings(port: 465)));
        $this->assertTrue(SmtpSettings::differ(self::settings(), self::settings(fromName: '総務部')));
    }

    #[Test]
    public function tellsMissingSettingsApart(): void
    {
        $this->assertTrue(SmtpSettings::differ(null, self::settings()));
        $this->assertTrue(SmtpSettings::differ(self::settings(), null));
    }

    private static function settings(int $port = 587, string $password = 'anpi-password', string $fromName = '安否確認'): SmtpSettings
    {
        return new SmtpSettings('smtp.example.co.jp', $port, 'starttls', 'anpi@example.co.jp', $password, 'anpi@example.co.jp', $fromName);
    }
}
