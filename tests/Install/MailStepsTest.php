<?php

declare(strict_types=1);

namespace ReBuji\Tests\Install;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use ReBuji\Config\ConfigFile;
use ReBuji\Database\Database;
use ReBuji\Mail\Imap\Mailbox;
use ReBuji\Mail\MailSender;
use ReBuji\Paths;
use ReBuji\Settings\Settings;
use ReBuji\Tests\Support\FakeMailbox;
use ReBuji\Tests\Support\MailFixtures;
use ReBuji\Tests\Support\RecordingMailSender;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;

/**
 * The installer's mail steps (design 6.3, 2 and 3), from a copy whose first admin is made.
 *
 * @author shimomo
 */
final class MailStepsTest extends TestCase
{
    #[Test]
    public function sendsATestMailToTheFirstAdmin(): void
    {
        [$app, $container, $root, $mailer] = self::upToTheAdmin();

        try {
            $response = self::post($app, $container, '/install/mail', TestApp::mailSettings());

            $this->assertSame(302, $response->getStatusCode(), (string) $response->getBody());
            $this->assertSame('/install/reply', $response->getHeaderLine('Location'));
            $this->assertSame(['admin@example.com'], $mailer->recipients());
            $this->assertStringStartsWith('[Re:無事 の確認 #', $mailer->sent[0]->subject);
            $this->assertStringContainsString('このメールに、そのまま返信してください', $mailer->sent[0]->body);
            $settings = self::settings($root);
            $this->assertSame('smtp.example.co.jp', $settings->smtp()?->host);
            $this->assertSame('imap.example.co.jp', $settings->imap()?->host);
            $this->assertStringContainsString('admin@example.com', (string) $app->handle(TestApp::request('GET', '/install/reply'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function needsBothAccounts(): void
    {
        [$app, $container, $root, $mailer] = self::upToTheAdmin();

        try {
            $page = (string) self::post($app, $container, '/install/mail', ['imap_host' => ''] + TestApp::mailSettings())->getBody();

            $this->assertStringContainsString('返信を受ける IMAP のサーバー名を入れてください。', $page);
            $this->assertSame([], $mailer->sent);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function stopsWhenTheMailboxCannotBeRead(): void
    {
        $mailbox = new FakeMailbox();
        $mailbox->unavailable = true;
        [$app, $container, $root, $mailer] = self::upToTheAdmin($mailbox);

        try {
            $response = self::post($app, $container, '/install/mail', TestApp::mailSettings());

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('メールを受信できませんでした：', (string) $response->getBody());
            $this->assertSame([], $mailer->sent);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function needsTheReplyToGoOn(): void
    {
        $mailbox = new FakeMailbox();
        [$app, $container, $root, $mailer] = self::upToTheAdmin($mailbox);

        try {
            self::post($app, $container, '/install/mail', TestApp::mailSettings());
            $mailbox->add(MailFixtures::reply('someone@example.net', '関係ないメール', null, 'こんにちは'));

            $waiting = self::post($app, $container, '/install/reply');

            $this->assertSame(200, $waiting->getStatusCode());
            $this->assertStringContainsString('まだ返信が見つかりません。返信してから少し待って、もう一度押してください。', (string) $waiting->getBody());
            $this->assertSame('/install/reply', $app->handle(TestApp::request('GET', '/install/exposure'))->getHeaderLine('Location'));

            $uid = $mailbox->add(MailFixtures::reply('admin@example.com', '', $mailer->sent[0]->messageId));
            $done = self::post($app, $container, '/install/reply');

            $this->assertSame(302, $done->getStatusCode());
            $this->assertSame('/install/exposure', $done->getHeaderLine('Location'));
            $this->assertSame([$uid], $mailbox->seen);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function acceptsAReplyCarryingTheNumberOnly(): void
    {
        $mailbox = new FakeMailbox();
        [$app, $container, $root, $mailer] = self::upToTheAdmin($mailbox);

        try {
            self::post($app, $container, '/install/mail', TestApp::mailSettings());
            preg_match('/#([0-9a-f]{8})\]/', $mailer->sent[0]->subject, $number);
            $mailbox->add(MailFixtures::reply('admin@example.com', '', null, 'Re: [Re:無事 の確認 #' . ($number[1] ?? '') . '] このメールに返信してください'));

            $this->assertSame('/install/exposure', self::post($app, $container, '/install/reply')->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * @return array{App<ContainerInterface>, ContainerInterface, string, RecordingMailSender}
     */
    private static function upToTheAdmin(?FakeMailbox $mailbox = null): array
    {
        $mailer = new RecordingMailSender();
        [$app, $container, $root] = TestApp::create([MailSender::class => $mailer, Mailbox::class => $mailbox ?? new FakeMailbox()]);
        self::post($app, $container, '/install/environment');
        $app->handle(TestApp::request('GET', '/install/key'));
        self::post($app, $container, '/install/key', ['install_key' => trim((string) file_get_contents($root . '/storage/install-key.txt'))]);
        self::post($app, $container, '/install/database', ['driver' => 'sqlite']);
        self::post($app, $container, '/install/admin', ['name' => '安否 太郎', 'email' => 'admin@example.com', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery']);

        return [$app, $container, $root, $mailer];
    }

    private static function settings(string $root): Settings
    {
        $paths = new Paths($root);

        return new Settings(Database::fromConfig((new ConfigFile($paths->storage('config.php')))->load(), $paths), new \ReBuji\SystemClock());
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, string> $body
     */
    private static function post(App $app, ContainerInterface $container, string $path, array $body = []): ResponseInterface
    {
        return $app->handle(TestApp::request('POST', $path, $body + TestApp::csrf($container)));
    }
}
