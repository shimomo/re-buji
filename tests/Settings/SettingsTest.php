<?php

declare(strict_types=1);

namespace ReBuji\Tests\Settings;

use JmaXml\Earthquake\SeismicIntensity;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Settings\Settings;
use ReBuji\Settings\SmtpSettings;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\TestDatabase;

/**
 * @author shimomo
 */
final class SettingsTest extends TestCase
{
    #[Test]
    #[DataProvider('drivers')]
    public function returnsTheDefaultsUntilSaved(string $driver): void
    {
        $settings = new Settings($this->migrated($driver), new FrozenClock());

        $this->assertSame(SeismicIntensity::FiveLower, $settings->threshold());
        $this->assertSame(0, $settings->int('delay_minutes'));
        $this->assertSame(3, $settings->int('late_after_hours'));
        $this->assertSame(3, $settings->int('resend_count'));
        $this->assertSame(60, $settings->int('resend_interval_minutes'));
        $this->assertSame(100, $settings->int('hourly_limit'));
        $this->assertSame('地震がありました（{地震}）', $settings->get('mail.subject'));
        $this->assertStringContainsString('{市町村と震度}', $settings->get('mail.body'));
        $this->assertNull($settings->smtp());
    }

    #[Test]
    #[DataProvider('drivers')]
    public function keepsSavedValues(string $driver): void
    {
        $db = $this->migrated($driver);
        (new Settings($db, new FrozenClock()))->save(['threshold' => '6-', 'hourly_limit' => '300']);

        $settings = new Settings($db, new FrozenClock());

        $this->assertSame(SeismicIntensity::SixLower, $settings->threshold());
        $this->assertSame(300, $settings->int('hourly_limit'));
        $this->assertSame(3, $settings->int('resend_count'));
    }

    #[Test]
    #[DataProvider('drivers')]
    public function overwritesASavedValue(string $driver): void
    {
        $settings = new Settings($this->migrated($driver), new FrozenClock());
        $settings->save(['hourly_limit' => '300']);
        $settings->save(['hourly_limit' => '1000']);

        $this->assertSame(1000, $settings->int('hourly_limit'));
    }

    #[Test]
    public function refusesUnknownNamesWhenSaving(): void
    {
        $settings = new Settings($this->migrated('sqlite'), new FrozenClock());

        $this->expectException(LogicException::class);
        $settings->save(['thresold' => '6-']);
    }

    #[Test]
    public function refusesUnknownNamesWhenReading(): void
    {
        $settings = new Settings($this->migrated('sqlite'), new FrozenClock());

        $this->expectException(LogicException::class);
        $settings->get('thresold');
    }

    #[Test]
    #[DataProvider('drivers')]
    public function hasSmtpOnceTheHostAndTheSenderAreSaved(string $driver): void
    {
        $settings = new Settings($this->migrated($driver), new FrozenClock());
        $settings->save(['smtp.host' => 'smtp.example.co.jp']);

        $this->assertNull($settings->smtp());

        $settings->save(['mail.from_address' => 'anpi@example.co.jp', 'smtp.username' => 'anpi', 'smtp.password' => 'secret']);

        $this->assertEquals(new SmtpSettings('smtp.example.co.jp', 587, 'starttls', 'anpi', 'secret', 'anpi@example.co.jp', '安否確認'), $settings->smtp());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        return TestDatabase::each();
    }

    private function migrated(string $driver): Database
    {
        $db = TestDatabase::open($driver);
        (new Migrator($db, Migrations::all(), new FrozenClock()))->migrate();

        return $db;
    }
}
