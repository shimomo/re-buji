<?php

declare(strict_types=1);

namespace ReBuji\Tests\Quakes;

use DateTimeImmutable;
use JmaXml\Earthquake\EarthquakeEvent;
use JmaXml\Report;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Employees\EmployeeCity;
use ReBuji\Employees\EmployeeInput;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\EarthquakeRepository;
use ReBuji\Quakes\MatchedCity;
use ReBuji\Quakes\Notification;
use ReBuji\Quakes\NotificationRepository;
use ReBuji\Quakes\QuakeSnapshot;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\JmaSamples;
use ReBuji\Tests\Support\TestDatabase;

/**
 * @author shimomo
 */
final class NotificationRepositoryTest extends TestCase
{
    #[Test]
    #[DataProvider('drivers')]
    public function roundTripsAContact(string $driver): void
    {
        $db = TestDatabase::open($driver);
        $clock = new FrozenClock(new DateTimeImmutable('2009-08-10T20:12:00Z'));
        (new Migrator($db, Migrations::all(), $clock))->migrate();
        $employees = new EmployeeRepository($db, $clock);
        $employee = $employees->find($employees->create(new EmployeeInput('山田 花子', '静岡支店', ['hanako@example.com'], [new EmployeeCity('2221300', '勤務地')], '', true)));
        $this->assertNotNull($employee);
        $event = EarthquakeEvent::fromReports([Report::fromString(JmaSamples::xml('32-35_07_01_100915_VXSE51.xml'))]);
        $quake = (new EarthquakeRepository($db, $clock))->createQuake('20090811050711', QuakeSnapshot::fromEvent($event, null), '5-', Earthquake::AUTO, false, new DateTimeImmutable('2009-08-10T20:07:00Z'));
        $notifications = new NotificationRepository($db, $clock);
        $cities = [
            new MatchedCity('2221300', '勤務地', '静岡県 掛川市', '6-', false, MatchedCity::BASIS_AREA),
            new MatchedCity('2210200', '', '静岡県 静岡駿河区', null, true, MatchedCity::BASIS_CITY),
        ];

        $id = $notifications->create($quake, $employee, $cities, 7, Notification::PENDING, new DateTimeImmutable('2009-08-10T20:07:00Z'));
        $contact = $notifications->find($id);

        $this->assertNotNull($contact);
        $this->assertSame($quake, $contact->earthquakeId);
        $this->assertSame($employee->id, $contact->employeeId);
        $this->assertSame('静岡支店', $contact->employeeDepartment);
        $this->assertEquals($cities, $contact->cities);
        $this->assertSame(7, $contact->priority);
        $this->assertSame(Notification::PENDING, $contact->state);
        $this->assertEquals(new DateTimeImmutable('2009-08-10T20:07:00Z'), $contact->notBefore);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $contact->token);
        $this->assertSame(0, $contact->sentCount);
        $this->assertNull($contact->answer);
        $this->assertNull($contact->answerSource);
        $this->assertNull($contact->autoRepliedAt);
        $this->assertNull($contact->checkedAt);
        $this->assertEquals($contact, $notifications->findFor($quake, $employee->id));

        $notifications->updateCities($id, [$cities[0]], 5);
        $this->assertSame(7, $notifications->find($id)?->priority);
        $this->assertEquals([$cities[0]], $notifications->find($id)?->cities);

        $notifications->markSent($id, $clock->now(), $clock->now()->modify('+60 minutes'));
        $sent = $notifications->find($id);

        $this->assertNotNull($sent);
        $this->assertSame(Notification::SENT, $sent->state);
        $this->assertSame(1, $sent->sentCount);
        $this->assertEquals($clock->now(), $sent->firstSentAt);
        $this->assertEquals($clock->now()->modify('+60 minutes'), $sent->nextSendAt);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        return TestDatabase::each();
    }
}
