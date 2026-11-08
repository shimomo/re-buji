<?php

declare(strict_types=1);

namespace ReBuji\Tests\Replies;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Replies\IncomingMail;
use ReBuji\Replies\ReplyMatch;
use ReBuji\Tests\Support\MailFixtures;
use ReBuji\Tests\Support\QuakeWorld;

/**
 * @author shimomo
 */
final class ReplyMatcherTest extends TestCase
{
    #[Test]
    public function matchesTheThreadFirst(): void
    {
        $world = self::world();
        $hanako = $world->contact($world->person('山田 花子', '2221300'));
        $ichiro = $world->contact($world->person('佐藤 一郎', '2221300'));
        $world->sender()->sendFirstContacts();
        $otherToken = $world->contactNow($ichiro)->token;

        $mail = IncomingMail::parse(MailFixtures::reply('person1@example.com', '', $world->messageIdSentTo('person1@example.com'), 'Re: [安否確認 #' . $otherToken . '] 地震'));

        $this->assertEquals(new ReplyMatch($hanako, 'thread'), $world->matcher()->match($mail));
    }

    #[Test]
    public function matchesTheNumberInTheSubject(): void
    {
        $world = self::world();
        $hanako = $world->contact($world->person('山田 花子', '2221300'));
        $world->sender()->sendFirstContacts();

        $mail = IncomingMail::parse(MailFixtures::reply('private@example.net', '', null, 'Re: [安否確認 #' . $world->contactNow($hanako)->token . '] 地震がありました'));

        $this->assertEquals(new ReplyMatch($hanako, 'number'), $world->matcher()->match($mail));
    }

    #[Test]
    public function matchesTheSenderToTheirLatestUnansweredContact(): void
    {
        $world = self::world();
        $person = $world->person('山田 花子', '2221300');
        $answered = $world->contact($person);
        $world->sender()->sendFirstContacts();
        $world->db->execute('UPDATE notifications SET replied_at = ? WHERE id = ?', ['2009-08-10T20:20:00Z', $answered]);
        $world->clock->advance('+10 minutes');
        $open = $world->contact($person, quake: $world->otherQuake('20090811060000'));
        $world->sender()->sendFirstContacts();

        $mail = IncomingMail::parse(MailFixtures::reply('Person1@Example.com', '無事です', null, '無事です'));

        $this->assertEquals(new ReplyMatch($open, 'sender'), $world->matcher()->match($mail));
    }

    #[Test]
    public function leavesUnknownSendersUnmatched(): void
    {
        $world = self::world();
        $world->contact($world->person('山田 花子', '2221300'));
        $world->sender()->sendFirstContacts();

        $this->assertNull($world->matcher()->match(IncomingMail::parse(MailFixtures::reply('stranger@example.net', '無事です', null, '無事です'))));
    }

    private static function world(): QuakeWorld
    {
        return new QuakeWorld('2009-08-10T20:12:00Z');
    }
}
