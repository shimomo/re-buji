<?php

declare(strict_types=1);

namespace ReBuji\Tests\Replies;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Mail\Imap\MailboxUnavailable;
use ReBuji\Quakes\Notification;
use ReBuji\Tests\Support\MailFixtures;
use ReBuji\Tests\Support\QuakeWorld;
use ReBuji\Tests\Support\SteppingClock;

/**
 * Yamada Hanako (person1@example.com) has been sent a contact; replies arrive in the mailbox.
 *
 * @author shimomo
 */
final class ReplyJobTest extends TestCase
{
    #[Test]
    public function recordsAnEmptyReplyAsSafe(): void
    {
        [$world, $contact] = self::sent();
        $uid = $world->mailbox->add(MailFixtures::reply('person1@example.com', "\n> 山田 花子 さん\n", $world->messageIdSentTo('person1@example.com'), 'Re: ' . $world->mailer->sent[0]->subject));

        $world->replyJob()->run($world->clock->now());

        $answered = $world->contactNow($contact);
        $this->assertSame(Notification::SAFE, $answered->answer);
        $this->assertSame('mail', $answered->answerSource);
        $this->assertEquals(new DateTimeImmutable(MailFixtures::DATE), $answered->repliedAt);
        $this->assertNull($answered->nextSendAt);
        $this->assertEquals([['kind' => 'reply', 'body' => '', 'receivedAt' => new DateTimeImmutable(MailFixtures::DATE)]], array_map(
            static fn(array $row): array => ['kind' => $row['kind'], 'body' => $row['body'], 'receivedAt' => $row['receivedAt']],
            $world->replies->forNotification($contact),
        ));
        $this->assertSame([$uid], $world->mailbox->seen);
    }

    #[Test]
    public function recordsAWrittenReplyForChecking(): void
    {
        [$world, $contact] = self::sent();
        $world->mailbox->add(MailFixtures::reply('person1@example.com', "足を怪我しました\n\n> 山田 花子 さん\n", $world->messageIdSentTo('person1@example.com'), 'Re: ' . $world->mailer->sent[0]->subject));

        $world->replyJob()->run($world->clock->now());

        $this->assertSame(Notification::NEEDS_CHECK, $world->contactNow($contact)->answer);
        $this->assertSame('足を怪我しました', $world->replies->forNotification($contact)[0]['body']);
    }

    #[Test]
    public function neverWeakensAnAnswer(): void
    {
        [$world, $contact] = self::sent();
        $thread = $world->messageIdSentTo('person1@example.com');
        $world->mailbox->add(MailFixtures::reply('person1@example.com', '足を怪我しました', $thread));
        $world->mailbox->add(MailFixtures::reply('person1@example.com', '', $thread));
        $world->replyJob()->run($world->clock->now());

        $this->assertSame(Notification::NEEDS_CHECK, $world->contactNow($contact)->answer);

        $world->notifications->markChecked($contact, '安否 太郎', $world->clock->now());
        $world->mailbox->add(MailFixtures::reply('person1@example.com', '病院に行きます', $thread));
        $world->replyJob()->run($world->clock->now());

        $checked = $world->contactNow($contact);
        $this->assertSame(Notification::NEEDS_CHECK, $checked->answer);
        $this->assertNull($checked->checkedAt);
        $this->assertNull($checked->checkedBy);
    }

    #[Test]
    public function doesNotCountAnAutoReply(): void
    {
        [$world, $contact] = self::sent();
        $world->mailbox->add(MailFixtures::autoReply('person1@example.com', $world->messageIdSentTo('person1@example.com')));

        $world->replyJob()->run($world->clock->now());

        $auto = $world->contactNow($contact);
        $this->assertNull($auto->answer);
        $this->assertNull($auto->repliedAt);
        $this->assertEquals($world->clock->now(), $auto->autoRepliedAt);

        $world->clock->advance('+60 minutes');
        $world->sender()->sendResends();
        $this->assertSame(2, $world->contactNow($contact)->sentCount);
    }

    #[Test]
    public function marksABouncedAddress(): void
    {
        $world = self::world();
        $contact = $world->contact($world->person('山田 花子', '2221300', addresses: 2));
        $world->sender()->sendFirstContacts();
        $world->mailbox->add(MailFixtures::bounce('person1-2@example.com', $world->messageIdSentTo('person1-2@example.com')));

        $world->replyJob()->run($world->clock->now());

        $this->assertNotNull($world->db->fetchValue("SELECT bounced_at FROM messages WHERE address = 'person1-2@example.com'"));
        $this->assertSame(Notification::SENT, $world->contactNow($contact)->state);
        $world->clock->advance('+60 minutes');
        $world->mailer->clear();
        $world->sender()->sendResends();
        $this->assertSame(['person1@example.com'], $world->mailer->recipients());

        // The other address comes back too, this time without the original Message-ID.
        $world->mailbox->add(MailFixtures::bounce('person1@example.com', null));
        $world->replyJob()->run($world->clock->now());

        $this->assertSame(Notification::FAILED, $world->contactNow($contact)->state);
        $this->assertNull($world->contactNow($contact)->nextSendAt);
        $this->assertSame(['bounce', 'bounce'], array_column($world->replies->forNotification($contact), 'kind'));
    }

    #[Test]
    public function checksABodyItCannotRead(): void
    {
        [$world, $contact] = self::sent();
        $world->mailbox->add(str_replace('charset=Shift_JIS', 'charset=x-sjis', MailFixtures::reply('person1@example.com', '足を骨折しました', $world->messageIdSentTo('person1@example.com'), 'Re: 地震', 'Shift_JIS')));

        $world->replyJob()->run($world->clock->now());

        $this->assertSame(Notification::NEEDS_CHECK, $world->contactNow($contact)->answer);
    }

    #[Test]
    public function checksAReplyWithOnlyAnAttachment(): void
    {
        [$world, $contact] = self::sent();
        $world->mailbox->add(MailFixtures::withAttachment('person1@example.com', $world->messageIdSentTo('person1@example.com'), 'IMG_0001.jpg'));

        $world->replyJob()->run($world->clock->now());

        $this->assertSame(Notification::NEEDS_CHECK, $world->contactNow($contact)->answer);
        $this->assertStringContainsString('IMG_0001.jpg', $world->replies->forNotification($contact)[0]['body']);
    }

    #[Test]
    public function checksWordsInTheSubject(): void
    {
        [$world, $contact] = self::sent();
        $sent = $world->mailer->sent[0];
        $world->mailbox->add(MailFixtures::reply('person1@example.com', '', $sent->messageId, 'Re: [安否確認 #' . $world->contactNow($contact)->token . '] 足を骨折、動けません'));

        $world->replyJob()->run($world->clock->now());

        $this->assertSame(Notification::NEEDS_CHECK, $world->contactNow($contact)->answer);
        $this->assertStringContainsString('足を骨折、動けません', $world->replies->forNotification($contact)[0]['body']);
    }

    #[Test]
    public function keepsAnUnchangedSubjectSafe(): void
    {
        [$world, $contact] = self::sent();
        $sent = $world->mailer->sent[0];
        $world->mailbox->add(MailFixtures::reply('person1@example.com', '', $sent->messageId, 'RE: Re: ' . $sent->subject));

        $world->replyJob()->run($world->clock->now());

        $this->assertSame(Notification::SAFE, $world->contactNow($contact)->answer);
    }

    #[Test]
    public function takesADelayNoticeAsNews(): void
    {
        [$world, $contact] = self::sent();
        $world->mailbox->add(MailFixtures::delay('person1@example.com', $world->messageIdSentTo('person1@example.com')));

        $world->replyJob()->run($world->clock->now());

        $kept = $world->contactNow($contact);
        $this->assertSame(Notification::SENT, $kept->state);
        $this->assertNotNull($kept->nextSendAt);
        $this->assertNull($world->db->fetchValue('SELECT bounced_at FROM messages'));
        $this->assertSame(['delayed'], array_column($world->replies->forNotification($contact), 'kind'));
    }

    #[Test]
    public function keepsReadingPastAMailItCannotRecord(): void
    {
        [$world, $contact] = self::sent();
        $world->db->execute("CREATE TRIGGER refuse_poison BEFORE INSERT ON replies WHEN NEW.subject = 'poison' BEGIN SELECT RAISE(ABORT, 'disk full'); END");
        $poison = $world->mailbox->add(MailFixtures::reply('stranger@example.net', '', null, 'poison'));
        $world->mailbox->add(MailFixtures::reply('person1@example.com', '', $world->messageIdSentTo('person1@example.com'), 'Re: ' . $world->mailer->sent[0]->subject));

        $world->replyJob()->run($world->clock->now());

        $this->assertSame(Notification::SAFE, $world->contactNow($contact)->answer);
        $this->assertContains($poison, $world->mailbox->seen);
        $this->assertSame('unreadable', $world->replies->unmatched()[0]['kind']);
    }

    #[Test]
    public function keepsUnmatchedMail(): void
    {
        [$world] = self::sent();
        $world->mailbox->add(MailFixtures::reply('stranger@example.net', "山田です。私用のアドレスから送ります。無事です。\n", null, '無事です'));

        $world->replyJob()->run($world->clock->now());

        $this->assertSame(1, $world->replies->countUnmatched());
        $this->assertSame('stranger@example.net', $world->replies->unmatched()[0]['fromAddress']);
        $this->assertStringContainsString('私用のアドレス', $world->replies->unmatched()[0]['body']);
    }

    #[Test]
    public function readsTheSameMailOnce(): void
    {
        [$world, $contact] = self::sent();
        $reply = MailFixtures::reply('person1@example.com', '足を怪我しました', $world->messageIdSentTo('person1@example.com'), messageId: 'r1@phone.example');
        $world->mailbox->add($reply);
        $second = $world->mailbox->add($reply);

        $world->replyJob()->run($world->clock->now());

        $this->assertCount(1, $world->replies->forNotification($contact));
        $this->assertContains($second, $world->mailbox->seen);
    }

    #[Test]
    public function readsOnlyMailSinceTheSettings(): void
    {
        [$world] = self::sent();

        $world->replyJob()->run($world->clock->now());

        $this->assertSame('2009-08-10T20:12:00Z', $world->state->get('imap.since'));
        $this->assertEquals([['since' => $world->clock->now(), 'limit' => 50]], $world->mailbox->asked);

        $world->clock->advance('+1 day');
        $world->replyJob()->run($world->clock->now());

        $this->assertEquals(new DateTimeImmutable('2009-08-10T20:12:00Z'), $world->mailbox->asked[1]['since']);
    }

    #[Test]
    public function keepsUnreadableMail(): void
    {
        [$world] = self::sent();
        $uid = $world->mailbox->add('not a mail');

        $world->replyJob()->run($world->clock->now());

        $this->assertSame('unreadable', $world->replies->unmatched()[0]['kind']);
        $this->assertSame('（読めないメール）', $world->replies->unmatched()[0]['subject']);
        $this->assertSame([$uid], $world->mailbox->seen);
    }

    #[Test]
    public function reportsAMailboxThatCannotBeRead(): void
    {
        [$world] = self::sent();
        $world->mailbox->unavailable = true;

        try {
            $world->replyJob()->run($world->clock->now());
            $this->fail('A mailbox that cannot be read should reach the cron runner');
        } catch (MailboxUnavailable) {
            // expected
        }

        $this->assertSame('Connection to imap.example.co.jp:993 timed out', $world->state->get('imap.last_error'));

        $world->mailbox->unavailable = false;
        $world->replyJob()->run($world->clock->now());

        $this->assertSame('', $world->state->get('imap.last_error'));
        $this->assertSame('2009-08-10T20:12:00Z', $world->state->get('imap.last_checked_at'));
    }

    #[Test]
    public function stopsAfterTheTimeBudget(): void
    {
        [$world] = self::sent();

        for ($i = 0; $i < 5; $i++) {
            $world->mailbox->add(MailFixtures::reply('stranger' . $i . '@example.net', '無事です', null, '無事です'));
        }

        $world->replyJob(new SteppingClock(new DateTimeImmutable('2009-08-10T20:12:00Z'), 30))->run($world->clock->now());

        $this->assertSame([1, 2], $world->mailbox->seen);
    }

    #[Test]
    public function waitsForTheImapSettings(): void
    {
        [$world] = self::sent();
        $world->settings->save(['imap.host' => '']);

        $world->replyJob()->run($world->clock->now());

        $this->assertSame([], $world->mailbox->asked);
    }

    private static function world(): QuakeWorld
    {
        $world = new QuakeWorld('2009-08-10T20:12:00Z');
        $world->settings->save(['imap.host' => 'imap.example.co.jp']);

        return $world;
    }

    /**
     * @return array{QuakeWorld, int}
     */
    private static function sent(): array
    {
        $world = self::world();
        $contact = $world->contact($world->person('山田 花子', '2221300'));
        $world->sender()->sendFirstContacts();

        return [$world, $contact];
    }
}
