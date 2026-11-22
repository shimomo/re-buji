<?php

declare(strict_types=1);

namespace ReBuji\Tests\Settings;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Config\Environment;
use ReBuji\Database\Database;
use ReBuji\Mail\Imap\ImapSettings;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\MailSender;
use ReBuji\Settings\Settings;
use ReBuji\SystemState;
use ReBuji\Tests\Support\EnvExample;
use ReBuji\Tests\Support\FakeMailbox;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\RecordingMailSender;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;

/**
 * @author shimomo
 */
final class SettingsPagesTest extends TestCase
{
    #[Test]
    public function showsTheCurrentSettings(): void
    {
        [$app, $container, $root] = self::loggedIn(new RecordingMailSender());

        try {
            self::settings($container)->save(['smtp.password' => 'secret-password']);
            $page = (string) $app->handle(TestApp::request('GET', '/settings'))->getBody();

            $this->assertStringContainsString('<option value="4">震度4</option>', $page);
            $this->assertStringContainsString('<option value="7">震度7</option>', $page);
            $this->assertStringContainsString('<option value="5-" selected>震度5弱</option>', $page);
            $this->assertStringContainsString('value="地震がありました（{地震}）"', $page);
            $this->assertStringContainsString('<input type="password" name="smtp_password" value=""', $page);
            $this->assertStringNotContainsString('secret-password', $page);
            $this->assertStringContainsString('ロリポップ エコノミー', $page);
            $this->assertStringContainsString('このメールボックスは Re:無事 の専用にしてください。', $page);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function savesValidSettings(): void
    {
        [$app, $container, $root] = self::loggedIn(new RecordingMailSender());

        try {
            $response = $app->handle(TestApp::request('POST', '/settings', ['hourly_limit' => '300', 'threshold' => '6-'] + self::form() + TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/settings', $response->getHeaderLine('Location'));
            $this->assertStringContainsString('設定を保存しました。', (string) $app->handle(TestApp::request('GET', '/settings'))->getBody());
            $settings = self::settings($container);
            $this->assertSame(300, $settings->int('hourly_limit'));
            $this->assertSame('6-', $settings->get('threshold'));
            $smtp = $settings->smtp();
            $this->assertNotNull($smtp);
            $this->assertSame('smtp.example.co.jp', $smtp->host);
            $this->assertSame('anpi-password', $smtp->password);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesValuesOutOfRange(): void
    {
        [$app, $container, $root] = self::loggedIn(new RecordingMailSender());

        try {
            $response = $app->handle(TestApp::request('POST', '/settings', [
                'hourly_limit' => '5',
                'delay_minutes' => '61',
                'resend_count' => '6',
                'mail_body' => "{名前} さん\n{部署} の皆さん\n",
            ] + self::form() + TestApp::csrf($container)));
            $page = (string) $response->getBody();

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('1 時間の上限は 10〜10000 通にしてください。', $page);
            $this->assertStringContainsString('送るまでの待ち時間は 0〜60 分にしてください。', $page);
            $this->assertStringContainsString('再送の回数は 0〜5 回にしてください。', $page);
            $this->assertStringContainsString('文面に使えない差し込みがあります：{部署}', $page);
            $this->assertStringContainsString('value="5"', $page);
            $this->assertSame(100, self::settings($container)->int('hourly_limit'));
            $this->assertNull(self::settings($container)->smtp());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesASenderNameOfFullWidthSpaces(): void
    {
        [$app, $container, $root] = self::loggedIn(new RecordingMailSender());

        try {
            $response = $app->handle(TestApp::request('POST', '/settings', ['mail_from_name' => '　　'] + self::form() + TestApp::csrf($container)));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('送信元の名前は 1〜50 文字にしてください。', (string) $response->getBody());
            $this->assertSame('安否確認', self::settings($container)->get('mail.from_name'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function needsTheServerAndTheSenderTogether(): void
    {
        [$app, $container, $root] = self::loggedIn(new RecordingMailSender());

        try {
            $page = (string) $app->handle(TestApp::request('POST', '/settings', ['mail_from_address' => ''] + self::form() + TestApp::csrf($container)))->getBody();
            $this->assertStringContainsString('SMTP のサーバー名と送信元のアドレスを入れてください。', $page);

            $page = (string) $app->handle(TestApp::request('POST', '/settings', ['mail_from_address' => 'anpi@', 'smtp_port' => '70000'] + self::form() + TestApp::csrf($container)))->getBody();
            $this->assertStringContainsString('送信元のアドレスの形が正しくありません。', $page);
            $this->assertStringContainsString('ポート番号は 1〜65535 にしてください。', $page);

            $cleared = $app->handle(TestApp::request('POST', '/settings', [
                'smtp_host' => '',
                'smtp_username' => '',
                'smtp_password' => '',
                'mail_from_address' => '',
            ] + self::form() + TestApp::csrf($container)));
            $this->assertSame(302, $cleared->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function keepsThePasswordWhenLeftEmpty(): void
    {
        [$app, $container, $root] = self::loggedIn(new RecordingMailSender());

        try {
            $app->handle(TestApp::request('POST', '/settings', self::form() + TestApp::csrf($container)));
            $app->handle(TestApp::request('POST', '/settings', ['smtp_password' => '', 'hourly_limit' => '200'] + self::form() + TestApp::csrf($container)));

            $this->assertSame('anpi-password', self::settings($container)->smtp()?->password);
            $this->assertSame(200, self::settings($container)->int('hourly_limit'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function sendsATestMailToTheAdmin(): void
    {
        $mailer = new RecordingMailSender();
        [$app, $container, $root] = self::loggedIn($mailer);

        try {
            $app->handle(TestApp::request('POST', '/settings', self::form() + TestApp::csrf($container)));
            $response = $app->handle(TestApp::request('POST', '/settings/test-mail', TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame([TestApp::ADMIN_EMAIL], $mailer->recipients());
            $this->assertSame('Re:無事 のテストメール', $mailer->sent[0]->subject);
            $db = $container->get(Database::class);
            $this->assertInstanceOf(Database::class, $db);
            $this->assertSame(1, (int) $db->fetchValue("SELECT COUNT(*) FROM messages WHERE kind = 'test' AND notification_id IS NULL AND sent = 1"));
            $this->assertStringContainsString(
                'テストメールを admin@example.com に送りました。届いたか確かめてください。',
                (string) $app->handle(TestApp::request('GET', '/settings'))->getBody(),
            );
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function showsWhyTheTestMailFailed(): void
    {
        $mailer = new RecordingMailSender();
        $mailer->unavailable = true;
        [$app, $container, $root] = self::loggedIn($mailer);

        try {
            $app->handle(TestApp::request('POST', '/settings', self::form() + TestApp::csrf($container)));
            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(SystemState::class, $state);
            $state->set('mail.last_error', 'Failed to authenticate on SMTP server');
            $response = $app->handle(TestApp::request('POST', '/settings/test-mail', TestApp::csrf($container)));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('テストメールを送れませんでした：Connection to', (string) $response->getBody());
            $this->assertSame('Failed to authenticate on SMTP server', $state->get('mail.last_error'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function clearsTheMailErrorOnceATestMailGoesThrough(): void
    {
        [$app, $container, $root] = self::loggedIn(new RecordingMailSender());

        try {
            $app->handle(TestApp::request('POST', '/settings', self::form() + TestApp::csrf($container)));
            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(SystemState::class, $state);
            $state->set('mail.last_error', 'Failed to authenticate on SMTP server');
            $this->assertStringContainsString('メールを送れていません：Failed to authenticate on SMTP server', (string) $app->handle(TestApp::request('GET', '/'))->getBody());

            $response = $app->handle(TestApp::request('POST', '/settings/test-mail', TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertStringNotContainsString('メールを送れていません', (string) $app->handle(TestApp::request('GET', '/'))->getBody());
            $this->assertSame('', $state->get('mail.last_error'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function explainsATestMailRefusedForNow(): void
    {
        [$app, $container, $root] = self::loggedIn(new RecordingMailSender(['admin@example.com' => 'defer']));

        try {
            $app->handle(TestApp::request('POST', '/settings', self::form() + TestApp::csrf($container)));
            $response = $app->handle(TestApp::request('POST', '/settings/test-mail', TestApp::csrf($container)));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('テストメールを送れませんでした：450 4.1.2', (string) $response->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function keepsTheTestMailWithinTheLimit(): void
    {
        $mailer = new RecordingMailSender();
        [$app, $container, $root] = self::loggedIn($mailer);

        try {
            $app->handle(TestApp::request('POST', '/settings', ['hourly_limit' => '10'] + self::form() + TestApp::csrf($container)));

            // One of the 10 is kept back for the admin's notice (RateLimit), so test mails get 9.
            for ($i = 0; $i < 9; $i++) {
                $app->handle(TestApp::request('POST', '/settings/test-mail', TestApp::csrf($container)));
            }

            $page = (string) $app->handle(TestApp::request('POST', '/settings/test-mail', TestApp::csrf($container)))->getBody();

            $this->assertCount(9, $mailer->sent);
            $this->assertStringContainsString('1 時間の上限に達しているため、テストメールを送れません。', $page);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function savesTheImapAccount(): void
    {
        [$app, $container, $root] = self::loggedIn(new RecordingMailSender());

        try {
            $app->handle(TestApp::request('POST', '/settings', self::imap() + self::form() + TestApp::csrf($container)));

            $this->assertEquals(new ImapSettings('imap.example.co.jp', 993, 'ssl', 'anpi@example.co.jp', 'anpi-password'), self::settings($container)->imap());
            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(SystemState::class, $state);
            $this->assertSame('2026-11-05T03:12:00Z', $state->get('imap.since'));
            $this->assertStringContainsString('name="imap_host" value="imap.example.co.jp"', (string) $app->handle(TestApp::request('GET', '/settings'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesAnImapPortOutOfRange(): void
    {
        [$app, $container, $root] = self::loggedIn(new RecordingMailSender());

        try {
            $page = (string) $app->handle(TestApp::request('POST', '/settings', ['imap_port' => '0', 'imap_encryption' => 'plain'] + self::imap() + self::form() + TestApp::csrf($container)))->getBody();

            $this->assertStringContainsString('IMAP のポート番号は 1〜65535 にしてください。', $page);
            $this->assertStringContainsString('受信の暗号化の方式を選んでください。', $page);
            $this->assertNull(self::settings($container)->imap());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function checksTheMailbox(): void
    {
        $mailbox = new FakeMailbox();
        [$app, $container, $root] = TestApp::installed([MailSender::class => new RecordingMailSender(), Mailbox::class => $mailbox, ClockInterface::class => new FrozenClock()]);

        try {
            TestApp::logIn($app, $container);
            $response = $app->handle(TestApp::request('POST', '/settings/test-imap', TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertStringContainsString('受信できました。', (string) $app->handle(TestApp::request('GET', '/settings'))->getBody());

            $mailbox->unavailable = true;
            $failed = $app->handle(TestApp::request('POST', '/settings/test-imap', TestApp::csrf($container)));

            $this->assertSame(200, $failed->getStatusCode());
            $this->assertStringContainsString('メールを受信できませんでした：Connection to imap.example.co.jp:993 timed out', (string) $failed->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function lockedFieldsAreReadOnly(): void
    {
        [$app, $container, $root] = self::lockedIn();

        try {
            $page = (string) $app->handle(TestApp::request('GET', '/settings'))->getBody();

            $this->assertMatchesRegularExpression('/<input[^>]*name="smtp_host"[^>]*readonly/', $page);
            $this->assertMatchesRegularExpression('/<select[^>]*name="smtp_encryption"[^>]*disabled/', $page);
            $this->assertStringContainsString('<input type="hidden" name="smtp_encryption" value="ssl">', $page);
            $this->assertStringContainsString('環境変数 REBUJI_SMTP_HOST で決まっています。', $page);
            $this->assertStringContainsString('環境変数 REBUJI_SMTP_PASSWORD で決まっています。', $page);
            $this->assertStringNotContainsString('env-secret', $page);
            $this->assertStringNotContainsString('環境変数 REBUJI_SMTP_USERNAME で決まっています。', $page);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function postedLockedValuesAreIgnored(): void
    {
        [$app, $container, $root] = self::lockedIn();

        try {
            $response = $app->handle(TestApp::request('POST', '/settings', ['smtp_host' => 'evil.example', 'smtp_password' => 'evil', 'imap_host' => 'evil.example', 'hourly_limit' => '300'] + self::form() + self::imap() + TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $settings = self::settings($container);
            $this->assertSame('env.example', $settings->get('smtp.host'));
            $this->assertSame('env-secret', $settings->get('smtp.password'));
            $this->assertSame('imap.env.example', $settings->get('imap.host'));
            $this->assertSame(300, $settings->int('hourly_limit'));
            $state = $container->get(SystemState::class);
            $this->assertInstanceOf(SystemState::class, $state);
            $this->assertNull($state->get('imap.since'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function savesWithBlankEnvironmentMail(): void
    {
        $environment = new Environment(['REBUJI_ENV_CONFIG' => '1'] + EnvExample::blankMail());
        [$app, $container, $root] = TestApp::installed([Environment::class => $environment, MailSender::class => new RecordingMailSender(), ClockInterface::class => new FrozenClock()]);

        try {
            TestApp::logIn($app, $container);
            $response = $app->handle(TestApp::request('POST', '/settings', ['mail_from_name' => '総務部'] + self::form() + self::imap() + TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $settings = self::settings($container);
            $this->assertSame('総務部', $settings->get('mail.from_name'));
            $this->assertSame('smtp.example.co.jp', $settings->get('smtp.host'));
            $this->assertSame('imap.example.co.jp', $settings->get('imap.host'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * @return array{App<ContainerInterface>, ContainerInterface, string}
     */
    private static function lockedIn(): array
    {
        $environment = new Environment([
            'REBUJI_ENV_CONFIG' => '1',
            'REBUJI_SMTP_HOST' => 'env.example',
            'REBUJI_SMTP_ENCRYPTION' => 'ssl',
            'REBUJI_SMTP_PASSWORD' => 'env-secret',
            'REBUJI_MAIL_FROM_ADDRESS' => 'anpi@example.co.jp',
            'REBUJI_IMAP_HOST' => 'imap.env.example',
        ]);
        [$app, $container, $root] = TestApp::installed([Environment::class => $environment, MailSender::class => new RecordingMailSender(), ClockInterface::class => new FrozenClock()]);
        TestApp::logIn($app, $container);

        return [$app, $container, $root];
    }

    /**
     * The reply mailbox's fields, with the user name and password left to the sending account's.
     *
     * @return array<string, string>
     */
    private static function imap(): array
    {
        return ['imap_host' => 'imap.example.co.jp', 'imap_port' => '993', 'imap_encryption' => 'ssl', 'imap_username' => '', 'imap_password' => ''];
    }

    /**
     * Every field of the form, filled in as an admin would.
     *
     * @return array<string, string>
     */
    private static function form(): array
    {
        return [
            'threshold' => '5-',
            'delay_minutes' => '0',
            'late_after_hours' => '3',
            'resend_count' => '3',
            'resend_interval_minutes' => '60',
            'hourly_limit' => '100',
            'mail_subject' => '地震がありました（{地震}）',
            'mail_body' => Settings::DEFAULTS['mail.body'],
            'smtp_host' => 'smtp.example.co.jp',
            'smtp_port' => '587',
            'smtp_encryption' => 'starttls',
            'smtp_username' => 'anpi@example.co.jp',
            'smtp_password' => 'anpi-password',
            'mail_from_address' => 'anpi@example.co.jp',
            'mail_from_name' => '安否確認',
        ];
    }

    /**
     * @return array{App<ContainerInterface>, ContainerInterface, string}
     */
    private static function loggedIn(RecordingMailSender $mailer): array
    {
        [$app, $container, $root] = TestApp::installed([MailSender::class => $mailer, ClockInterface::class => new FrozenClock()]);
        TestApp::logIn($app, $container);

        return [$app, $container, $root];
    }

    private static function settings(ContainerInterface $container): Settings
    {
        $settings = $container->get(Settings::class);
        assert($settings instanceof Settings);

        return $settings;
    }
}
