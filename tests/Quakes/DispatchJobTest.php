<?php

declare(strict_types=1);

namespace ReBuji\Tests\Quakes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\MatchedCity;
use ReBuji\Quakes\Notification;
use ReBuji\Tests\Support\JmaSamples;
use ReBuji\Tests\Support\QuakeWorld;

/**
 * Judges the Suruga Bay earthquake of 2009-08-11 (EventID 20090811050711, detected 2009-08-10T20:07:00Z, up to 6弱),
 * five minutes after it was detected.
 *
 * @author shimomo
 */
final class DispatchJobTest extends TestCase
{
    private const string EVENT = '20090811050711';

    private const string FIRST = '32-35_07_01_100915_VXSE51.xml';

    private const string LATER_SHINDO = '32-35_07_05_100915_VXSE51.xml';

    private const string CITIES = '32-35_07_06_240613_VXSE53.xml';

    private const string CANCELLED_EVENT = '20080614084350';

    /**
     * Both kinds of report of the 2008-06-14 earthquake, cancelled.
     */
    private const array CANCELLATIONS = ['32-35_06_02_100915_VXSE52.xml', '32-35_06_04_240613_VXSE53.xml', '32-35_06_05_240613_VXSE53.xml', '32-35_06_06_100915_VXSE53.xml'];

    private const string EVENT_A = '20100705065511';

    private const array QUAKE_A = ['32-35_08_01_100915_VXSE51.xml', '32-35_08_03_100915_VXSE51.xml', '32-35_08_05_100915_VXSE51.xml'];

    private const string EVENT_B = '20100705065610';

    private const string QUAKE_B = '32-35_08_02_100915_VXSE51.xml';

    #[Test]
    public function contactsPeopleWhoseMunicipalityReachedTheThreshold(): void
    {
        $world = self::world();
        $yamada = $world->person('山田 花子', '2221300', '勤務地');
        $sato = $world->person('佐藤 一郎', '1310100');

        $world->receive(self::FIRST);

        $quake = $world->quake(self::EVENT);
        $this->assertSame(Earthquake::KIND_QUAKE, $quake->kind);
        $this->assertSame('最大震度6弱', $quake->title);
        $this->assertSame(Earthquake::AUTO, $quake->delivery);
        $this->assertFalse($quake->late);
        $this->assertSame('5-', $quake->threshold);
        $this->assertSame('震度速報', $quake->basisTitle);
        $this->assertEquals(new DateTimeImmutable('2009-08-10T20:07:00Z'), $quake->detectedAt);

        $contacts = $world->notifications->forEarthquake($quake->id);
        $this->assertCount(1, $contacts);
        $contact = $contacts[0];
        $this->assertSame($yamada, $contact->employeeId);
        $this->assertSame('山田 花子', $contact->employeeName);
        $this->assertSame(Notification::PENDING, $contact->state);
        $this->assertSame(7, $contact->priority);
        $this->assertEquals([new MatchedCity('2221300', '勤務地', '静岡県 掛川市', '6-', false, MatchedCity::BASIS_AREA)], $contact->cities);
        $this->assertEquals(new DateTimeImmutable('2009-08-10T20:07:00Z'), $contact->notBefore);
        $this->assertNull($world->notifications->findFor($quake->id, $sato));
        $this->assertSame([], $world->telegrams->unprocessedEventIds());
    }

    #[Test]
    public function skipsStoppedPeople(): void
    {
        $world = self::world();
        $world->person('山田 花子', '2221300', '勤務地', false);

        $world->receive(self::FIRST);

        $this->assertNull($world->earthquakes->findByEventId(self::EVENT));
    }

    #[Test]
    public function makesOneContactPerPersonAndQuake(): void
    {
        $world = self::world();
        $world->person('山田 花子', '2221300', '勤務地');
        $world->receive(self::FIRST);

        $world->receive(self::CITIES);

        $quake = $world->quake(self::EVENT);
        $this->assertSame('駿河湾・最大震度6弱', $quake->title);
        $this->assertSame('駿河湾', $quake->hypocenter);
        $this->assertSame('6.6', $quake->magnitude);
        $this->assertSame('6-', $quake->maxIntensity);
        $this->assertSame('震源・震度に関する情報', $quake->basisTitle);

        $contacts = $world->notifications->forEarthquake($quake->id);
        $this->assertCount(1, $contacts);
        $this->assertEquals([new MatchedCity('2221300', '勤務地', '静岡県 掛川市', '5-', false, MatchedCity::BASIS_CITY)], $contacts[0]->cities);
        $this->assertSame(7, $contacts[0]->priority);
    }

    #[Test]
    public function addsPeopleWhoMatchLater(): void
    {
        $world = self::world();
        $world->person('山田 花子', '2221300', '勤務地');
        $yasuoka = $world->person('長野 次郎', '2041400', '自宅');
        $world->receive(self::FIRST);

        $this->assertNull($world->notifications->findFor($world->quake(self::EVENT)->id, $yasuoka));

        $world->receive(self::LATER_SHINDO);

        $contact = $world->notifications->findFor($world->quake(self::EVENT)->id, $yasuoka);
        $this->assertNotNull($contact);
        $this->assertSame(Notification::PENDING, $contact->state);
        $this->assertSame(5, $contact->priority);
    }

    #[Test]
    public function keepsTheContactAfterTheIntensityIsRevisedDown(): void
    {
        $world = self::world();
        $atami = $world->person('熱海 三郎', '2220500', '自宅');
        $world->receive(self::FIRST);

        $before = $world->notifications->findFor($world->quake(self::EVENT)->id, $atami);
        $this->assertNotNull($before);
        $this->assertSame('5+', $before->cities[0]->intensity);

        $world->receive(self::CITIES);

        $after = $world->notifications->findFor($world->quake(self::EVENT)->id, $atami);
        $this->assertNotNull($after);
        $this->assertSame(Notification::PENDING, $after->state);
        $this->assertEquals($before->cities, $after->cities);
    }

    #[Test]
    public function holdsAQuakeThatArrivedLate(): void
    {
        $world = self::world();
        $yamada = $world->person('山田 花子', '2221300', '勤務地');
        $world->clock->advance('+2 hours 56 minutes');

        $world->receive(self::FIRST);

        $quake = $world->quake(self::EVENT);
        $this->assertSame(Earthquake::HELD, $quake->delivery);
        $this->assertTrue($quake->late);
        $this->assertSame(Notification::HELD, $world->notifications->findFor($quake->id, $yamada)?->state);
    }

    #[Test]
    public function contactsAtExactlyTheLateLimit(): void
    {
        $world = self::world();
        $world->person('山田 花子', '2221300', '勤務地');
        $world->clock->advance('+2 hours 55 minutes');

        $world->receive(self::FIRST);

        $this->assertSame(Earthquake::AUTO, $world->quake(self::EVENT)->delivery);
    }

    #[Test]
    public function waitsTheConfiguredDelay(): void
    {
        $world = self::world();
        $yamada = $world->person('山田 花子', '2221300', '勤務地');
        $world->settings->save(['delay_minutes' => '10']);

        $world->receive(self::FIRST);

        $this->assertEquals(new DateTimeImmutable('2009-08-10T20:17:00Z'), $world->notifications->findFor($world->quake(self::EVENT)->id, $yamada)?->notBefore);
    }

    #[Test]
    public function keepsTheThresholdOfTheFirstJudgement(): void
    {
        $world = self::world();
        $world->person('山田 花子', '2221300', '勤務地');
        $yasuoka = $world->person('長野 次郎', '2041400', '自宅');
        $world->receive(self::FIRST);
        $world->settings->save(['threshold' => '6-']);

        $world->receive(self::LATER_SHINDO);

        $this->assertNotNull($world->notifications->findFor($world->quake(self::EVENT)->id, $yasuoka));
        $this->assertSame('5-', $world->quake(self::EVENT)->threshold);
    }

    #[Test]
    public function ignoresQuakesBelowTheThreshold(): void
    {
        $world = self::world();
        $world->person('陸前 高田', '0321000', '自宅');

        $world->receive('32-35_08_02_100915_VXSE51.xml');

        $this->assertSame(0, (int) $world->db->fetchValue('SELECT COUNT(*) FROM earthquakes'));
        $this->assertSame([], $world->telegrams->unprocessedEventIds());
    }

    #[Test]
    public function cancelsContactsNotSentYet(): void
    {
        $world = self::cancelledWorld();
        $oshu = $world->person('奥州 一子', '0321500', '自宅');
        $world->receive('32-35_06_01_240613_VXSE52.xml', '32-35_06_03_240613_VXSE53.xml');
        $quake = $world->quake(self::CANCELLED_EVENT);
        $this->assertSame(Notification::PENDING, $world->notifications->findFor($quake->id, $oshu)?->state);

        $world->receive(...self::CANCELLATIONS);

        $contact = $world->notifications->findFor($quake->id, $oshu);
        $this->assertNotNull($contact);
        $this->assertSame(Notification::CANCELLED, $contact->state);
        $this->assertNull($contact->cancelNotice);
        $this->assertEquals($world->clock->now(), $world->quake(self::CANCELLED_EVENT)->cancelledAt);
    }

    #[Test]
    public function queuesACancelNoticeForPeopleAlreadyContacted(): void
    {
        $world = self::cancelledWorld();
        $oshu = $world->person('奥州 一子', '0321500', '自宅');
        $world->receive('32-35_06_01_240613_VXSE52.xml', '32-35_06_03_240613_VXSE53.xml');
        $quake = $world->quake(self::CANCELLED_EVENT);
        $contact = $world->notifications->findFor($quake->id, $oshu);
        $this->assertNotNull($contact);
        $world->notifications->markSent($contact->id, $world->clock->now(), null);
        $world->db->execute(
            "INSERT INTO messages (notification_id, kind, address, message_id, sent, error, attempted_at) VALUES (?, 'first', 'person1@example.com', 'a@example.co.jp', 1, '', ?)",
            [$contact->id, '2008-06-13T23:48:00Z'],
        );

        $world->receive(...self::CANCELLATIONS);

        $cancelled = $world->notifications->find($contact->id);
        $this->assertNotNull($cancelled);
        $this->assertSame(Notification::CANCELLED, $cancelled->state);
        $this->assertSame('pending', $cancelled->cancelNotice);
    }

    #[Test]
    public function staysCancelledWhateverArrivesLater(): void
    {
        $world = self::cancelledWorld();
        $oshu = $world->person('奥州 一子', '0321500', '自宅');
        $world->receive('32-35_06_01_240613_VXSE52.xml', '32-35_06_03_240613_VXSE53.xml');
        $world->receive(...self::CANCELLATIONS);
        $kitakami = $world->person('北上 二郎', '0320600', '自宅');

        // A 震源・震度に関する情報 issued again after the cancellation.
        $world->telegrams->store(
            'https://www.data.jma.go.jp/developer/xml/data/20080614001000_0_VXSE53_010000.xml',
            JmaSamples::edited('32-35_06_05_240613_VXSE53.xml', ['<ReportDateTime>2008-06-14T09:01:00+09:00</ReportDateTime>' => '<ReportDateTime>2008-06-14T09:10:00+09:00</ReportDateTime>']),
            $world->clock->now(),
        );
        $world->dispatch->run($world->clock->now());

        $quake = $world->quake(self::CANCELLED_EVENT);
        $this->assertTrue($quake->isCancelled());
        $this->assertSame(Notification::CANCELLED, $world->notifications->findFor($quake->id, $oshu)?->state);
        $this->assertNull($world->notifications->findFor($quake->id, $kitakami));
    }

    #[Test]
    public function foldsARelatedQuakeIntoTheContactNotAnsweredYet(): void
    {
        $world = self::relatedWorld();
        $person = $world->person('陸前 高田', '0321000', '自宅');
        $world->receive(...self::QUAKE_A);

        $world->receive(self::QUAKE_B);

        $first = $world->notifications->findFor($world->quake(self::EVENT_A)->id, $person);
        $second = $world->notifications->findFor($world->quake(self::EVENT_B)->id, $person);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(Notification::PENDING, $first->state);
        $this->assertSame(Notification::MERGED, $second->state);
        $this->assertSame($first->id, $second->mergedIntoId);
    }

    #[Test]
    public function contactsAgainForARelatedQuakeAfterAReply(): void
    {
        $world = self::relatedWorld();
        $person = $world->person('陸前 高田', '0321000', '自宅');
        $world->receive(...self::QUAKE_A);
        $world->db->execute('UPDATE notifications SET replied_at = ?', ['2010-07-04T21:59:00Z']);

        $world->receive(self::QUAKE_B);

        $second = $world->notifications->findFor($world->quake(self::EVENT_B)->id, $person);
        $this->assertNotNull($second);
        $this->assertSame(Notification::PENDING, $second->state);
        $this->assertNull($second->mergedIntoId);
    }

    #[Test]
    public function contactsPeopleFoldedIntoAQuakeThatWasCancelled(): void
    {
        $world = self::relatedWorld();
        $person = $world->person('陸前 高田', '0321000', '自宅');
        $world->receive(...self::QUAKE_A);
        $world->receive(self::QUAKE_B);
        $world->clock->advance('+3 minutes');
        $world->telegrams->store(
            'https://www.data.jma.go.jp/developer/xml/data/20100704220000_0_VXSE51_010000.xml',
            JmaSamples::edited('32-35_08_05_100915_VXSE51.xml', [
                '<InfoType>発表</InfoType>' => '<InfoType>取消</InfoType>',
                '<ReportDateTime>2010-07-05T06:59:00+09:00</ReportDateTime>' => '<ReportDateTime>2010-07-05T07:00:00+09:00</ReportDateTime>',
            ]),
            $world->clock->now(),
        );

        $world->dispatch->run($world->clock->now());

        $first = $world->notifications->findFor($world->quake(self::EVENT_A)->id, $person);
        $second = $world->notifications->findFor($world->quake(self::EVENT_B)->id, $person);
        $this->assertSame(Notification::CANCELLED, $first?->state);
        $this->assertNotNull($second);
        $this->assertSame(Notification::PENDING, $second->state);
        $this->assertNull($second->mergedIntoId);
        $this->assertEquals($world->clock->now(), $second->notBefore);
    }

    #[Test]
    public function doesNotFoldIntoAQuakeWaitingForADecision(): void
    {
        // A arrived just over an hour after it was detected and is held; B, a minute younger, is still on time.
        $world = new QuakeWorld('2010-07-04T22:55:30Z');
        $world->settings->save(['threshold' => '4', 'late_after_hours' => '1']);
        $person = $world->person('陸前 高田', '0321000', '自宅');
        $world->receive(...self::QUAKE_A);
        $world->receive(self::QUAKE_B);
        $this->assertSame(Earthquake::HELD, $world->quake(self::EVENT_A)->delivery);

        $world->notifications->dismiss($world->quake(self::EVENT_A)->id);
        $world->sender()->sendFirstContacts();

        $second = $world->notifications->findFor($world->quake(self::EVENT_B)->id, $person);
        $this->assertNotNull($second);
        $this->assertNull($second->mergedIntoId);
        $this->assertSame(Notification::SENT, $second->state);
        $this->assertSame(['person1@example.com'], $world->mailer->recipients());
    }

    #[Test]
    public function keepsAFoldedContactCancelledWithItsQuake(): void
    {
        $world = self::relatedWorld();
        $person = $world->person('陸前 高田', '0321000', '自宅');
        $world->receive(...self::QUAKE_A);
        $world->receive(self::QUAKE_B);
        $world->telegrams->store(
            'https://www.data.jma.go.jp/developer/xml/data/20100704220100_0_VXSE51_010000.xml',
            JmaSamples::edited(self::QUAKE_B, [
                '<InfoType>発表</InfoType>' => '<InfoType>取消</InfoType>',
                '<ReportDateTime>2010-07-05T06:57:00+09:00</ReportDateTime>' => '<ReportDateTime>2010-07-05T07:01:00+09:00</ReportDateTime>',
            ]),
            $world->clock->now(),
        );
        $world->dispatch->run($world->clock->now());
        $world->telegrams->store(
            'https://www.data.jma.go.jp/developer/xml/data/20100704220200_0_VXSE51_010000.xml',
            JmaSamples::edited('32-35_08_05_100915_VXSE51.xml', [
                '<InfoType>発表</InfoType>' => '<InfoType>取消</InfoType>',
                '<ReportDateTime>2010-07-05T06:59:00+09:00</ReportDateTime>' => '<ReportDateTime>2010-07-05T07:02:00+09:00</ReportDateTime>',
            ]),
            $world->clock->now(),
        );

        $world->dispatch->run($world->clock->now());
        $world->sender()->sendFirstContacts();

        $this->assertSame(Notification::CANCELLED, $world->notifications->findFor($world->quake(self::EVENT_B)->id, $person)?->state);
        $this->assertSame([], $world->mailer->sent);
    }

    #[Test]
    public function foldsTheStrongerShakingIntoTheContact(): void
    {
        // A contact still waiting for the delay takes the main shock's intensity and its place in the queue.
        $world = self::relatedWorld();
        $world->settings->save(['delay_minutes' => '10']);
        $person = $world->person('陸前 高田', '0321000', '自宅');
        $world->receive(...self::QUAKE_A);
        $first = $world->notifications->findFor($world->quake(self::EVENT_A)->id, $person);
        $this->assertNotNull($first);
        $this->assertSame(4, $first->priority);

        $world->telegrams->store(
            JmaSamples::url(self::QUAKE_B),
            JmaSamples::edited(self::QUAKE_B, ['<Area><Name>岩手県沿岸南部</Name><Code>211</Code><MaxInt>4</MaxInt></Area>' => '<Area><Name>岩手県沿岸南部</Name><Code>211</Code><MaxInt>6-</MaxInt></Area>']),
            $world->clock->now(),
        );
        $world->dispatch->run($world->clock->now());

        $this->assertSame(Notification::MERGED, $world->notifications->findFor($world->quake(self::EVENT_B)->id, $person)?->state);
        $folded = $world->notifications->find($first->id);
        $this->assertNotNull($folded);
        $this->assertSame(7, $folded->priority);
        $this->assertSame(['6-'], array_map(static fn(MatchedCity $city): ?string => $city->intensity, $folded->cities));
    }

    private static function world(): QuakeWorld
    {
        return new QuakeWorld('2009-08-10T20:12:00Z');
    }

    /**
     * Five minutes after the 2008-06-14 earthquake (detected 2008-06-13T23:43:00Z) that JMA later cancelled.
     */
    private static function cancelledWorld(): QuakeWorld
    {
        return new QuakeWorld('2008-06-13T23:48:00Z');
    }

    /**
     * Two quakes a minute apart on 2010-07-05 (A detected 21:55Z, B 21:56Z), both shaking 岩手県沿岸南部 at 4.
     */
    private static function relatedWorld(): QuakeWorld
    {
        $world = new QuakeWorld('2010-07-04T22:00:00Z');
        $world->settings->save(['threshold' => '4']);

        return $world;
    }
}
