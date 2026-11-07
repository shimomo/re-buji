<?php

declare(strict_types=1);

namespace ReBuji\Tests\Quakes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\MatchedCity;
use ReBuji\Quakes\Notification;
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

    private static function world(): QuakeWorld
    {
        return new QuakeWorld('2009-08-10T20:12:00Z');
    }
}
