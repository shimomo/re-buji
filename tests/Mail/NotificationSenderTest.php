<?php

declare(strict_types=1);

namespace ReBuji\Tests\Mail;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Auth\AdminRepository;
use ReBuji\Employees\EmployeeCity;
use ReBuji\Employees\EmployeeInput;
use ReBuji\Mail\OutgoingMail;
use ReBuji\Quakes\Notification;
use ReBuji\Tests\Support\QuakeWorld;
use ReBuji\Tests\Support\SteppingClock;

/**
 * @author shimomo
 */
final class NotificationSenderTest extends TestCase
{
    #[Test]
    public function sendsEachAddressItsOwnMail(): void
    {
        $world = self::world();
        $contact = $world->contact($world->person('山田 花子', '2221300', addresses: 2));

        $world->sender()->sendFirstContacts();

        $this->assertSame(['person1@example.com', 'person1-2@example.com'], $world->mailer->recipients());
        $this->assertNotSame($world->mailer->sent[0]->messageId, $world->mailer->sent[1]->messageId);
        $this->assertSame(2, (int) $world->db->fetchValue("SELECT COUNT(*) FROM messages WHERE kind = 'first' AND sent = 1 AND notification_id = ?", [$contact]));
        $sent = $world->contactNow($contact);
        $this->assertSame(Notification::SENT, $sent->state);
        $this->assertSame(1, $sent->sentCount);
        $this->assertEquals($world->clock->now()->modify('+60 minutes'), $sent->nextSendAt);
    }

    #[Test]
    public function staysWithinTheHourlyLimit(): void
    {
        $world = self::world();
        $world->settings->save(['hourly_limit' => '10']);
        $contacts = [];

        for ($i = 0; $i < 3; $i++) {
            $contacts[] = $world->contact($world->person('従業員 ' . $i, '2221300', addresses: 4));
        }

        $world->sender()->sendFirstContacts();

        $this->assertCount(8, $world->mailer->sent);
        $this->assertSame(Notification::PENDING, $world->contactNow($contacts[2])->state);

        $world->clock->advance('+61 minutes');
        $world->sender()->sendFirstContacts();

        $this->assertCount(12, $world->mailer->sent);
        $this->assertSame(Notification::SENT, $world->contactNow($contacts[2])->state);
    }

    #[Test]
    public function sendsWhenThereAreAsManyAdminsAsTheLimit(): void
    {
        $world = self::world();
        $world->settings->save(['hourly_limit' => '10']);
        $admins = new AdminRepository($world->db, $world->clock);

        for ($i = 1; $i <= 10; $i++) {
            $admins->create('管理者 ' . $i, 'admin' . $i . '@example.com', 'correct-horse-battery');
        }

        $contact = $world->contact($world->person('山田 花子', '2221300'));

        $world->sender()->sendFirstContacts();

        $this->assertSame(Notification::SENT, $world->contactNow($contact)->state);
    }

    #[Test]
    public function sendsTheStrongestFirst(): void
    {
        $world = self::world();
        $world->settings->save(['hourly_limit' => '10']);
        $weaker = $world->contact($world->person('先に 登録', '2221300', addresses: 3), 5);
        $stronger = $world->contact($world->person('後に 登録', '2221300', addresses: 3), 7);

        for ($i = 0; $i < 7; $i++) {
            $world->messages->record(null, 'test', 'admin@example.com', 'm' . $i . '@example.co.jp', true, '', $world->clock->now()->modify('-5 minutes'));
        }

        $world->sender()->sendFirstContacts();

        $this->assertSame(['person2@example.com', 'person2-2@example.com', 'person2-3@example.com'], $world->mailer->recipients());
        $this->assertSame(Notification::SENT, $world->contactNow($stronger)->state);
        $this->assertSame(Notification::PENDING, $world->contactNow($weaker)->state);
    }

    #[Test]
    public function waitsForTheDelay(): void
    {
        $world = self::world();
        $contact = $world->contact($world->person('山田 花子', '2221300'), notBefore: $world->clock->now()->modify('+1 minute'));

        $world->sender()->sendFirstContacts();

        $this->assertSame([], $world->mailer->sent);
        $this->assertSame(Notification::PENDING, $world->contactNow($contact)->state);
    }

    #[Test]
    public function marksRefusedAddresses(): void
    {
        $world = self::world();
        $partly = $world->contact($world->person('山田 花子', '2221300', addresses: 2));
        $wholly = $world->contact($world->person('佐藤 一郎', '2221300'));
        $world->mailer->failures = ['person1-2@example.com' => 'refuse', 'person2@example.com' => 'refuse'];

        $world->sender()->sendFirstContacts();

        $this->assertSame(Notification::SENT, $world->contactNow($partly)->state);
        $this->assertSame(Notification::FAILED, $world->contactNow($wholly)->state);
        $this->assertSame(
            '550 5.1.1 User unknown: person2@example.com',
            $world->db->fetchValue('SELECT error FROM messages WHERE notification_id = ? AND sent = 0', [$wholly]),
        );

        $world->clock->advance('+2 hours');
        $world->mailer->clear();
        $world->sender()->sendResends();

        $this->assertSame(['person1@example.com'], $world->mailer->recipients());
    }

    #[Test]
    public function keepsContactsWhenTheServerIsUnavailable(): void
    {
        $world = self::world();
        $first = $world->contact($world->person('山田 花子', '2221300'));
        $second = $world->contact($world->person('佐藤 一郎', '2221300'));
        $world->mailer->unavailable = true;

        $world->sender()->sendFirstContacts();

        $this->assertSame(Notification::PENDING, $world->contactNow($first)->state);
        $this->assertSame(Notification::PENDING, $world->contactNow($second)->state);
        $this->assertSame(0, (int) $world->db->fetchValue('SELECT COUNT(*) FROM messages'));
        $this->assertSame('Connection to "smtp.example.co.jp:587" timed out.', $world->state->get('mail.last_error'));

        $world->mailer->unavailable = false;
        $world->sender()->sendFirstContacts();

        $this->assertSame(Notification::SENT, $world->contactNow($second)->state);
        $this->assertSame('', $world->state->get('mail.last_error'));
    }

    #[Test]
    public function keepsAContactSentWhenTheServerFailsHalfway(): void
    {
        $world = self::world();
        $contact = $world->contact($world->person('山田 花子', '2221300', addresses: 2));
        $world->mailer->failures = ['person1-2@example.com' => 'unavailable'];

        $world->sender()->sendFirstContacts();

        $this->assertSame(['person1@example.com'], $world->mailer->recipients());
        $this->assertSame(Notification::SENT, $world->contactNow($contact)->state);
        $this->assertNotSame('', $world->state->get('mail.last_error'));
    }

    #[Test]
    public function reportsMissingMailSettings(): void
    {
        $world = self::world();
        $contact = $world->contact($world->person('山田 花子', '2221300'));
        $world->settings->save(['smtp.host' => '']);

        $world->sender()->sendFirstContacts();

        $this->assertSame(Notification::PENDING, $world->contactNow($contact)->state);
        $this->assertStringContainsString('メールの送信の設定がありません', (string) $world->state->get('mail.last_error'));
    }

    #[Test]
    public function skipsStoppedOrDeletedPeople(): void
    {
        $world = self::world();
        $stopped = $world->person('停止 一郎', '2221300');
        $deleted = $world->person('削除 二郎', '2221300');
        $stoppedContact = $world->contact($stopped);
        $deletedContact = $world->contact($deleted);
        $world->employees->update($stopped, new EmployeeInput('停止 一郎', '', ['person1@example.com'], [new EmployeeCity('2221300', '')], '', false));
        $world->employees->delete($deleted);

        $world->sender()->sendFirstContacts();

        $this->assertSame([], $world->mailer->sent);
        $this->assertSame(Notification::SKIPPED, $world->contactNow($stoppedContact)->state);
        $this->assertSame(Notification::SKIPPED, $world->contactNow($deletedContact)->state);
    }

    #[Test]
    public function resendsUntilTheConfiguredCount(): void
    {
        $world = self::world();
        $world->settings->save(['resend_count' => '2']);
        $waiting = $world->contact($world->person('山田 花子', '2221300'));
        $replied = $world->contact($world->person('佐藤 一郎', '2221300'));
        $world->sender()->sendFirstContacts();
        $world->db->execute('UPDATE notifications SET replied_at = ? WHERE id = ?', ['2009-08-10T20:20:00Z', $replied]);

        $world->clock->advance('+59 minutes');
        $world->sender()->sendResends();
        $this->assertCount(2, $world->mailer->sent);

        $world->clock->advance('+1 minute');
        $world->sender()->sendResends();
        $world->clock->advance('+60 minutes');
        $world->sender()->sendResends();
        $world->clock->advance('+60 minutes');
        $world->sender()->sendResends();

        $resends = array_values(array_filter($world->mailer->sent, static fn(OutgoingMail $mail): bool => str_contains($mail->subject, '【再送】')));
        $this->assertCount(2, $resends);
        $this->assertSame(['person1@example.com', 'person1@example.com'], array_map(static fn(OutgoingMail $mail): string => $mail->to, $resends));
        $this->assertSame(2, (int) $world->db->fetchValue("SELECT COUNT(*) FROM messages WHERE kind = 'resend'"));
        $this->assertSame(3, $world->contactNow($waiting)->sentCount);
        $this->assertNull($world->contactNow($waiting)->nextSendAt);
    }

    #[Test]
    public function resendsToTheAddressesTheyHaveNow(): void
    {
        $world = self::world();
        $person = $world->person('山田 花子', '2221300');
        $contact = $world->contact($person);
        $world->sender()->sendFirstContacts();
        $world->employees->update($person, new EmployeeInput('山田 花子', '', ['hanako.new@example.com'], [new EmployeeCity('2221300', '')], '', true));

        $world->clock->advance('+60 minutes');
        $world->sender()->sendResends();

        $this->assertSame(['person1@example.com', 'hanako.new@example.com'], $world->mailer->recipients());
        $this->assertSame(2, $world->contactNow($contact)->sentCount);
    }

    #[Test]
    public function sendsTheCancelNoticeToAddressesThatGotTheContact(): void
    {
        $world = self::world();
        $contact = $world->contact($world->person('山田 花子', '2221300', addresses: 2));
        $world->mailer->failures = ['person1-2@example.com' => 'refuse'];
        $world->sender()->sendFirstContacts();
        $world->notifications->cancelFor($world->contactNow($contact)->earthquakeId);
        $world->mailer->clear();

        $world->sender()->sendCancelNotices();

        $this->assertSame(['person1@example.com'], $world->mailer->recipients());
        $this->assertStringContainsString('先ほどの連絡は取り消されました', $world->mailer->sent[0]->subject);
        $this->assertSame(1, (int) $world->db->fetchValue("SELECT COUNT(*) FROM messages WHERE kind = 'cancel'"));
        $this->assertSame('sent', $world->contactNow($contact)->cancelNotice);

        $world->clock->advance('+2 hours');
        $world->sender()->sendCancelNotices();
        $world->sender()->sendResends();

        $this->assertCount(1, $world->mailer->sent);
    }

    #[Test]
    public function neverSendsAContactOfACancelledQuake(): void
    {
        $world = self::world();
        $contact = $world->contact($world->person('山田 花子', '2221300'));
        $world->earthquakes->markCancelled($world->contactNow($contact)->earthquakeId, $world->clock->now());

        $world->sender()->sendFirstContacts();

        $this->assertSame([], $world->mailer->attempts);
        $this->assertSame(Notification::CANCELLED, $world->contactNow($contact)->state);
    }

    #[Test]
    public function keepsSendingPastATemporarilyRefusedRecipient(): void
    {
        $world = self::world();
        $stuck = $world->contact($world->person('山田 花子', '2221300'), 7);
        $next = $world->contact($world->person('佐藤 一郎', '2221300'), 5);
        $world->mailer->failures = ['person1@example.com' => 'defer'];

        $world->sender()->sendFirstContacts();

        $this->assertSame(['person2@example.com'], $world->mailer->recipients());
        $this->assertSame(Notification::SENT, $world->contactNow($next)->state);
        $deferred = $world->contactNow($stuck);
        $this->assertSame(Notification::PENDING, $deferred->state);
        $this->assertEquals($world->clock->now()->modify('+15 minutes'), $deferred->notBefore);
        $this->assertStringContainsString('450 4.1.2', (string) $world->state->get('mail.last_error'));
        $this->assertSame(0, (int) $world->db->fetchValue('SELECT COUNT(*) FROM messages WHERE notification_id = ?', [$stuck]));
    }

    #[Test]
    public function stopsAfterSeveralTemporaryRefusalsInARow(): void
    {
        $world = self::world();

        for ($i = 1; $i <= 5; $i++) {
            $world->contact($world->person('従業員 ' . $i, '2221300'));
            $world->mailer->failures['person' . $i . '@example.com'] = 'defer';
        }

        $world->sender()->sendFirstContacts();

        $this->assertCount(3, $world->mailer->attempts);
    }

    #[Test]
    public function keepsTheErrorUntilARunHasNoProblem(): void
    {
        $world = self::world();
        $world->contact($world->person('山田 花子', '2221300'));
        $resent = $world->contact($world->person('佐藤 一郎', '2221300'));
        $world->sender()->sendFirstContacts();
        $world->clock->advance('+60 minutes');
        $world->contact($world->person('鈴木 三郎', '2221300'));
        $world->mailer->failures = ['person3@example.com' => 'defer'];

        // One cron run: the first contacts meet a temporary refusal, the resends then go out fine.
        $sender = $world->sender();
        $sender->sendFirstContacts();
        $sender->sendResends();

        $this->assertSame(2, $world->contactNow($resent)->sentCount);
        $this->assertStringContainsString('450 4.1.2', (string) $world->state->get('mail.last_error'));

        $world->mailer->failures = [];
        $world->clock->advance('+15 minutes');
        $world->sender()->sendFirstContacts();

        $this->assertSame('', $world->state->get('mail.last_error'));
    }

    #[Test]
    public function skipsTheLaterStepsOnceTheServerIsUnavailable(): void
    {
        $world = self::world();
        $world->contact($world->person('山田 花子', '2221300'));
        $resent = $world->contact($world->person('佐藤 一郎', '2221300'));
        $world->sender()->sendFirstContacts();
        $world->clock->advance('+60 minutes');
        $world->contact($world->person('鈴木 三郎', '2221300'));
        $world->mailer->unavailable = true;
        $world->mailer->attempts = [];

        $sender = $world->sender();
        $sender->sendFirstContacts();
        $sender->sendCancelNotices();
        $sender->sendResends();

        $this->assertCount(1, $world->mailer->attempts);
        $this->assertSame(1, $world->contactNow($resent)->sentCount);
    }

    #[Test]
    public function stopsAfterTheTimeBudget(): void
    {
        $world = self::world();
        $contacts = [];

        for ($i = 0; $i < 3; $i++) {
            $contacts[] = $world->contact($world->person('従業員 ' . $i, '2221300'));
        }

        $world->sender(new SteppingClock(new DateTimeImmutable('2009-08-10T20:12:00Z'), 50))->sendFirstContacts();

        $this->assertCount(2, $world->mailer->sent);
        $this->assertSame(Notification::PENDING, $world->contactNow($contacts[2])->state);
    }

    private static function world(): QuakeWorld
    {
        return new QuakeWorld('2009-08-10T20:12:00Z');
    }
}
