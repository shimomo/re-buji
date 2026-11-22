<?php

declare(strict_types=1);

namespace ReBuji\Tests\Settings;

use JmaXml\Earthquake\SeismicIntensity;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Config\Environment;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Mail\Imap\ImapSettings;
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

    #[Test]
    #[DataProvider('drivers')]
    public function hasImapOnceTheHostIsSaved(string $driver): void
    {
        $settings = new Settings($this->migrated($driver), new FrozenClock());
        $settings->save(['smtp.username' => 'anpi', 'smtp.password' => 'secret']);

        $this->assertNull($settings->imap());

        $settings->save(['imap.host' => 'imap.example.co.jp']);

        $this->assertEquals(new ImapSettings('imap.example.co.jp', 993, 'ssl', 'anpi', 'secret'), $settings->imap());

        $settings->save(['imap.username' => 'reply', 'imap.password' => 'other', 'imap.port' => '143', 'imap.encryption' => 'starttls']);

        $this->assertEquals(new ImapSettings('imap.example.co.jp', 143, 'starttls', 'reply', 'other'), $settings->imap());
    }

    #[Test]
    public function environmentWinsWhenEnabled(): void
    {
        $db = $this->migrated('sqlite');
        (new Settings($db, new FrozenClock()))->save(['smtp.host' => 'db.example']);
        $settings = new Settings($db, new FrozenClock(), new Environment(['REBUJI_ENV_CONFIG' => '1', 'REBUJI_SMTP_HOST' => 'env.example']));

        $this->assertSame('env.example', $settings->get('smtp.host'));
        $this->assertSame('REBUJI_SMTP_HOST', $settings->lockedBy('smtp.host'));
        $this->assertNull($settings->lockedBy('threshold'));
    }

    #[Test]
    public function environmentIsIgnoredWhenDisabled(): void
    {
        $db = $this->migrated('sqlite');
        (new Settings($db, new FrozenClock()))->save(['smtp.host' => 'db.example']);
        $settings = new Settings($db, new FrozenClock(), new Environment(['REBUJI_SMTP_HOST' => 'env.example']));

        $this->assertSame('db.example', $settings->get('smtp.host'));
        $this->assertNull($settings->lockedBy('smtp.host'));
    }

    #[Test]
    public function saveSkipsLockedNames(): void
    {
        $db = $this->migrated('sqlite');
        (new Settings($db, new FrozenClock()))->save(['smtp.host' => 'db.example']);
        $settings = new Settings($db, new FrozenClock(), new Environment(['REBUJI_ENV_CONFIG' => '1', 'REBUJI_SMTP_HOST' => 'env.example']));
        $settings->save(['smtp.host' => 'x', 'threshold' => '6-']);

        $plain = new Settings($db, new FrozenClock());
        $this->assertSame('db.example', $plain->get('smtp.host'));
        $this->assertSame('6-', $plain->get('threshold'));
    }

    #[Test]
    public function readsAPasswordFromAFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rebuji');
        $this->assertNotFalse($file);
        file_put_contents($file, "from-file\n");

        try {
            $settings = new Settings($this->migrated('sqlite'), new FrozenClock(), new Environment(['REBUJI_ENV_CONFIG' => '1', 'REBUJI_SMTP_PASSWORD_FILE' => $file]));

            $this->assertSame('REBUJI_SMTP_PASSWORD', $settings->lockedBy('smtp.password'));
            $this->assertSame('from-file', $settings->get('smtp.password'));
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function reportsMalformedEnvironmentValues(): void
    {
        $settings = new Settings($this->migrated('sqlite'), new FrozenClock(), new Environment([
            'REBUJI_ENV_CONFIG' => '1',
            'REBUJI_SMTP_PORT' => 'abc',
            'REBUJI_IMAP_ENCRYPTION' => 'none',
            'REBUJI_MAIL_FROM_ADDRESS' => 'anpi',
        ]));

        $errors = $settings->environmentErrors();

        $this->assertCount(3, $errors);
        $this->assertContains('REBUJI_SMTP_PORT は 1〜65535 の数にしてください。', $errors);
        $this->assertContains('REBUJI_IMAP_ENCRYPTION は ssl か starttls にしてください。', $errors);
        $this->assertContains('REBUJI_MAIL_FROM_ADDRESS がメールアドレスの形ではありません。', $errors);
    }

    #[Test]
    public function acceptsWellFormedEnvironmentValues(): void
    {
        $settings = new Settings($this->migrated('sqlite'), new FrozenClock(), new Environment([
            'REBUJI_ENV_CONFIG' => '1',
            'REBUJI_SMTP_PORT' => '465',
            'REBUJI_SMTP_ENCRYPTION' => 'ssl',
            'REBUJI_MAIL_FROM_ADDRESS' => 'anpi@example.co.jp',
        ]));

        $this->assertSame([], $settings->environmentErrors());
        $this->assertSame([], (new Settings($this->migrated('sqlite'), new FrozenClock(), new Environment(['REBUJI_SMTP_PORT' => 'abc'])))->environmentErrors());
    }

    #[Test]
    public function blankEnvironmentValuesAreNotLocked(): void
    {
        $db = $this->migrated('sqlite');
        (new Settings($db, new FrozenClock()))->save(['smtp.host' => 'db.example']);
        $settings = new Settings($db, new FrozenClock(), new Environment([
            'REBUJI_ENV_CONFIG' => '1',
            'REBUJI_SMTP_HOST' => '',
            'REBUJI_MAIL_FROM_ADDRESS' => '',
            'REBUJI_MAIL_FROM_NAME' => '',
            'REBUJI_IMAP_PASSWORD' => '',
        ]));

        $this->assertNull($settings->lockedBy('mail.from_name'));
        $this->assertSame('安否確認', $settings->get('mail.from_name'));
        $this->assertNull($settings->lockedBy('smtp.host'));
        $this->assertSame('db.example', $settings->get('smtp.host'));
        $this->assertNull($settings->lockedBy('imap.password'));
        $this->assertSame([], $settings->environmentErrors());
    }

    #[Test]
    public function reportsWhatTheSettingsPageWouldReject(): void
    {
        $settings = new Settings($this->migrated('sqlite'), new FrozenClock(), new Environment([
            'REBUJI_ENV_CONFIG' => '1',
            'REBUJI_SMTP_USERNAME' => 'anpi@example.co.jp',
            'REBUJI_SMTP_PASSWORD' => 'secret',
            'REBUJI_MAIL_FROM_ADDRESS' => 'anpi@example.co.jp',
            'REBUJI_MAIL_FROM_NAME' => str_repeat('あ', 51),
        ]));

        $errors = $settings->environmentErrors();

        $this->assertCount(2, $errors);
        $this->assertContains('REBUJI_MAIL_FROM_NAME は 1〜50 文字にしてください。', $errors);
        $this->assertContains('REBUJI_SMTP_USERNAME か REBUJI_SMTP_PASSWORD を決めたときは、REBUJI_SMTP_HOST と REBUJI_MAIL_FROM_ADDRESS も決めてください。', $errors);
        $this->assertSame([], (new Settings($this->migrated('sqlite'), new FrozenClock(), new Environment([
            'REBUJI_ENV_CONFIG' => '1',
            'REBUJI_SMTP_HOST' => 'smtp.example.co.jp',
            'REBUJI_MAIL_FROM_ADDRESS' => 'anpi@example.co.jp',
            'REBUJI_SMTP_USERNAME' => 'anpi@example.co.jp',
            'REBUJI_MAIL_FROM_NAME' => str_repeat('あ', 50),
        ])))->environmentErrors());
    }

    #[Test]
    public function passesTheCaFile(): void
    {
        $settings = new Settings($this->migrated('sqlite'), new FrozenClock(), new Environment([
            'REBUJI_ENV_CONFIG' => '1',
            'REBUJI_MAIL_CA_FILE' => '/certs/ca.pem',
            'REBUJI_SMTP_HOST' => 'smtp.example.co.jp',
            'REBUJI_MAIL_FROM_ADDRESS' => 'anpi@example.co.jp',
            'REBUJI_IMAP_HOST' => 'imap.example.co.jp',
        ]));

        $this->assertSame('/certs/ca.pem', $settings->smtp()?->caFile);
        $this->assertSame('/certs/ca.pem', $settings->imap()?->caFile);
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
