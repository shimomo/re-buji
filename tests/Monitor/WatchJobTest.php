<?php

declare(strict_types=1);

namespace ReBuji\Tests\Monitor;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReBuji\Auth\AdminRepository;
use ReBuji\Mail\MessageRepository;
use ReBuji\Mail\NotificationMail;
use ReBuji\Mail\RateLimit;
use ReBuji\Monitor\WatchJob;
use ReBuji\Replies\ReplyReading;
use ReBuji\Tests\Support\QuakeWorld;

/**
 * Two admins watch over the copy; the clock is at 2009-08-10T20:12:00Z.
 *
 * @author shimomo
 */
final class WatchJobTest extends TestCase
{
    #[Test]
    public function tellsTheAdminsAboutANewProblem(): void
    {
        $world = self::world();
        $world->state->set('feed.gap_since', '2009-08-10T19:00:00Z');

        self::job($world)->run($world->clock->now());

        $this->assertSame(['admin@example.com', 'hanako@example.com'], $world->mailer->recipients());
        $this->assertSame('[Re:無事] 確かめてほしいことがあります', $world->mailer->sent[0]->subject);
        $this->assertStringContainsString('・気象庁の情報に抜けがあります（8月11日 4時00分 以降）', $world->mailer->sent[0]->body);
        $this->assertSame(2, (int) $world->db->fetchValue("SELECT COUNT(*) FROM messages WHERE kind = 'watch' AND notification_id IS NULL"));
    }

    #[Test]
    public function tellsEachProblemOnce(): void
    {
        $world = self::world();
        $world->state->set('mail.last_error', 'Connection timed out');
        self::job($world)->run($world->clock->now());
        $world->clock->advance('+2 hours');

        self::job($world)->run($world->clock->now());

        $this->assertCount(2, $world->mailer->sent);

        $world->state->set('mail.last_error', 'Failed to authenticate');
        self::job($world)->run($world->clock->now());

        $this->assertCount(4, $world->mailer->sent);
        $this->assertStringContainsString('Failed to authenticate', $world->mailer->sent[2]->body);
    }

    #[Test]
    public function tellsAgainWhenAProblemComesBack(): void
    {
        $world = self::world();
        $world->state->set('imap.last_error', 'IMAP のサーバーが断りました');
        self::job($world)->run($world->clock->now());
        $world->state->set('imap.last_error', '');
        $world->clock->advance('+2 hours');
        self::job($world)->run($world->clock->now());
        $world->state->set('imap.last_error', 'IMAP のサーバーが断りました');
        $world->clock->advance('+2 hours');

        self::job($world)->run($world->clock->now());

        $this->assertCount(4, $world->mailer->sent);
    }

    #[Test]
    public function groupsProblemsInOneMail(): void
    {
        $world = self::world();
        $world->state->set('mail.last_error', 'Connection timed out');
        $world->state->set('imap.last_error', 'IMAP のサーバーが断りました');

        self::job($world)->run($world->clock->now());

        $this->assertCount(2, $world->mailer->sent);
        $this->assertStringContainsString('・メールを送れていません：Connection timed out', $world->mailer->sent[0]->body);
        $this->assertStringContainsString('・返信を読めていません：IMAP のサーバーが断りました', $world->mailer->sent[0]->body);
    }

    #[Test]
    public function tellsWhenRepliesHaveNotBeenRead(): void
    {
        $world = self::world();
        $world->settings->save(['imap.host' => 'imap.example.co.jp', 'smtp.username' => 'anpi', 'smtp.password' => 'secret']);
        $world->state->set('imap.last_checked_at', '2009-08-10T19:00:00Z');

        self::job($world)->run($world->clock->now());

        $this->assertStringContainsString('・返信を 30 分以上読めていません（最後は 8月11日 4時00分）。', $world->mailer->sent[0]->body);
    }

    #[Test]
    public function tellsAboutTelegramsItCouldNotFetchOrRead(): void
    {
        $world = self::world();
        $world->state->set('feed.failed_telegrams', json_encode([['kind' => 'read', 'title' => '震源・震度に関する情報', 'url' => 'https://example.com/broken.xml', 'reason' => 'not a JMA telegram', 'at' => '2009-08-10T20:10:00Z']], JSON_THROW_ON_ERROR));

        self::job($world)->run($world->clock->now());

        $this->assertStringContainsString('・取れなかったか読めなかった電文が 1 通あります。', $world->mailer->sent[0]->body);
    }

    #[Test]
    public function tellsAsManyAdminsAsTheLimitAllows(): void
    {
        $world = self::world();
        $world->settings->save(['hourly_limit' => '10']);
        $admins = new AdminRepository($world->db, $world->clock);

        for ($i = 1; $i <= 10; $i++) {
            $admins->create('管理者 ' . $i, 'admin' . $i . '@example.com', 'correct-horse-battery');
        }

        for ($i = 0; $i < 9; $i++) {
            $world->messages->record(null, 'test', 'admin@example.com', bin2hex(random_bytes(16)) . '@example.co.jp', true, '', $world->clock->now()->modify('-10 minutes'));
        }

        $world->state->set('feed.gap_since', '2009-08-10T19:00:00Z');

        self::job($world)->run($world->clock->now());

        $this->assertSame(['admin@example.com'], $world->mailer->recipients());
    }

    #[Test]
    public function tellsAboutContactsHeldBackByTheLimitOnce(): void
    {
        $world = self::world();
        $world->settings->save(['hourly_limit' => '20']);

        for ($i = 0; $i < 17; $i++) {
            $world->messages->record(null, 'test', 'admin@example.com', 'm' . $i . '@example.co.jp', true, '', new DateTimeImmutable('2009-08-10T20:05:00Z'));
        }

        $world->contact($world->person('山田 花子', '2221300'), notBefore: new DateTimeImmutable('2009-08-10T20:07:00Z'));
        $world->contact($world->person('佐藤 一郎', '2221300'), notBefore: new DateTimeImmutable('2009-08-10T20:07:00Z'));
        $world->messages->record(null, 'test', 'admin@example.com', 'late@example.co.jp', true, '', new DateTimeImmutable('2009-08-10T20:06:00Z'));

        // Contacts have used their share (18 of 20); the 2 kept back are for the two admins' notice.
        self::job($world)->run($world->clock->now());

        $this->assertCount(2, $world->mailer->sent);
        $this->assertStringContainsString('1 時間の上限のため、連絡が送信待ちになっています。', $world->mailer->sent[0]->body);
    }

    #[Test]
    public function waitsWhenMailCannotGoOut(): void
    {
        $world = self::world();
        $world->state->set('feed.gap_since', '2009-08-10T19:00:00Z');
        $world->mailer->unavailable = true;
        self::job($world)->run($world->clock->now());

        $this->assertSame('', (string) $world->state->get('watch.sent.feed_gap'));
        $this->assertCount(1, $world->mailer->attempts);

        $world->mailer->unavailable = false;
        $world->clock->advance('+59 minutes');
        self::job($world)->run($world->clock->now());

        $this->assertCount(1, $world->mailer->attempts);

        $world->clock->advance('+1 minute');
        self::job($world)->run($world->clock->now());

        $this->assertCount(2, $world->mailer->sent);
    }

    #[Test]
    public function waitsWhenTheLimitIsUsedUp(): void
    {
        $world = self::world();
        $world->settings->save(['hourly_limit' => '10']);

        for ($i = 0; $i < 10; $i++) {
            $world->messages->record(null, 'test', 'admin@example.com', 'm' . $i . '@example.co.jp', true, '', new DateTimeImmutable('2009-08-10T20:05:00Z'));
        }

        $world->state->set('feed.gap_since', '2009-08-10T19:00:00Z');

        self::job($world)->run($world->clock->now());

        $this->assertSame([], $world->mailer->attempts);
    }

    private static function world(): QuakeWorld
    {
        $world = new QuakeWorld('2009-08-10T20:12:00Z');
        $admins = new AdminRepository($world->db, $world->clock);
        $admins->create('安否 太郎', 'admin@example.com', 'correct-horse-battery');
        $admins->create('安否 花子', 'hanako@example.com', 'correct-horse-battery');

        return $world;
    }

    private static function job(QuakeWorld $world): WatchJob
    {
        $messages = new MessageRepository($world->db);

        return new WatchJob(
            $world->state,
            $world->notifications,
            new RateLimit($messages, $world->settings, new AdminRepository($world->db, $world->clock)),
            new AdminRepository($world->db, $world->clock),
            new NotificationMail($world->settings, $world->clock),
            $world->mailer,
            $messages,
            $world->clock,
            new NullLogger(),
            new ReplyReading($world->settings, $world->state),
        );
    }
}
