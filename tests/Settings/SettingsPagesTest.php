<?php

declare(strict_types=1);

namespace ReBuji\Tests\Settings;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReBuji\Database\Database;
use ReBuji\Mail\MailSender;
use ReBuji\Settings\Settings;
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
            $response = $app->handle(TestApp::request('POST', '/settings/test-mail', TestApp::csrf($container)));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('テストメールを送れませんでした：Connection to', (string) $response->getBody());
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

            for ($i = 0; $i < 10; $i++) {
                $app->handle(TestApp::request('POST', '/settings/test-mail', TestApp::csrf($container)));
            }

            $page = (string) $app->handle(TestApp::request('POST', '/settings/test-mail', TestApp::csrf($container)))->getBody();

            $this->assertCount(10, $mailer->sent);
            $this->assertStringContainsString('1 時間の上限に達しているため、テストメールを送れません。', $page);
        } finally {
            TemporaryDirectory::remove($root);
        }
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
        [$app, $container, $root] = TestApp::installed([MailSender::class => $mailer]);
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
