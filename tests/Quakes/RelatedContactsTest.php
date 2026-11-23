<?php

declare(strict_types=1);

namespace ReBuji\Tests\Quakes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\MatchedCity;
use ReBuji\Quakes\Notification;
use ReBuji\Quakes\QuakeSnapshot;
use ReBuji\Tests\Support\QuakeWorld;

/**
 * Quake B folded into the person's contact for quake A, with the municipalities written out.
 *
 * @author shimomo
 */
final class RelatedContactsTest extends TestCase
{
    #[Test]
    public function keepsTheContactsOwnReadingOnATieWhenFolding(): void
    {
        [$world, $a] = self::contactForA([self::city('0321000', '5-', MatchedCity::BASIS_CITY)]);

        $world->related->fold($a, [self::city('0321000', '5-', MatchedCity::BASIS_AREA)], 5);

        $this->assertSame(MatchedCity::BASIS_CITY, self::cities($world, $a)[0]->basis);
    }

    #[Test]
    public function foldsAReadingNotReceivedYetOnATie(): void
    {
        [$world, $a] = self::contactForA([self::city('0321000', '5-', MatchedCity::BASIS_CITY)]);

        $world->related->fold($a, [self::city('0321000', null, MatchedCity::BASIS_CITY, true)], 5);

        $this->assertTrue(self::cities($world, $a)[0]->notYetReceived);
    }

    #[Test]
    public function keepsAFoldedReadingNotReceivedYetWhenTheContactIsJudgedAgain(): void
    {
        $notYet = self::city('0321000', null, MatchedCity::BASIS_CITY, true);
        [$world, $a] = self::contactForA([$notYet]);
        self::foldB($world, $a, [$notYet]);

        $world->related->revise(self::contact($world, $a), [self::city('0321000', '5-', MatchedCity::BASIS_CITY)], 5);

        $this->assertTrue(self::cities($world, $a)[0]->notYetReceived);
    }

    #[Test]
    public function stopsHoldingTheContactUpOnceTheFoldedQuakeIsCancelled(): void
    {
        $fromB = self::city('0321000', '6-', MatchedCity::BASIS_AREA);
        [$world, $a] = self::contactForA([$fromB]);
        $b = self::foldB($world, $a, [$fromB]);
        $world->notifications->cancelFor($b);

        $world->related->revise(self::contact($world, $a), [self::city('0321000', '4', MatchedCity::BASIS_AREA)], 4);

        $this->assertSame(['4'], array_map(static fn(MatchedCity $city): ?string => $city->intensity, self::cities($world, $a)));
    }

    #[Test]
    public function raisesOnlyTheMunicipalitiesTheFoldedQuakeNames(): void
    {
        $fromB = self::city('0321000', '6-', MatchedCity::BASIS_AREA);
        [$world, $a] = self::contactForA([$fromB, self::city('0320300', '5+', MatchedCity::BASIS_CITY)]);
        self::foldB($world, $a, [$fromB]);

        $world->related->revise(self::contact($world, $a), [self::city('0321000', '4', MatchedCity::BASIS_CITY), self::city('0320300', '5-', MatchedCity::BASIS_CITY)], 5);

        // B does not name 0320300: it takes A's newer reading, weaker as it is.
        $this->assertSame(
            [['0321000', '6-', MatchedCity::BASIS_AREA], ['0320300', '5-', MatchedCity::BASIS_CITY]],
            array_map(static fn(MatchedCity $city): array => [$city->code, $city->intensity, $city->basis], self::cities($world, $a)),
        );
    }

    /**
     * A world with one person and their pending contact for quake A.
     *
     * @param list<MatchedCity> $cities
     *
     * @return array{QuakeWorld, int}
     */
    private static function contactForA(array $cities): array
    {
        $world = new QuakeWorld('2010-07-04T22:00:00Z');
        $person = $world->person('陸前 高田', '0321000', '自宅');
        $quake = self::quake($world, '20100705065511');
        $employee = $world->employees->find($person);
        self::assertNotNull($employee);

        return [$world, $world->notifications->create($quake, $employee, $cities, 5, Notification::PENDING, $world->clock->now())];
    }

    /**
     * Quake B's contact for the same person, folded into $a. Returns quake B's id.
     *
     * @param list<MatchedCity> $cities
     */
    private static function foldB(QuakeWorld $world, int $a, array $cities): int
    {
        $quake = self::quake($world, '20100705065610');
        $employee = $world->employees->find(self::contact($world, $a)->employeeId ?? 0);
        self::assertNotNull($employee);
        $world->notifications->create($quake, $employee, $cities, 5, Notification::MERGED, $world->clock->now(), $a);

        return $quake;
    }

    private static function quake(QuakeWorld $world, string $eventId): int
    {
        return $world->earthquakes->createQuake(
            $eventId,
            new QuakeSnapshot('岩手県沿岸南部・最大震度4', '岩手県沿岸南部', '4.0', '4', null, '震度速報', null),
            '4',
            Earthquake::AUTO,
            false,
            new DateTimeImmutable('2010-07-04T21:55:00Z'),
        );
    }

    private static function contact(QuakeWorld $world, int $id): Notification
    {
        $contact = $world->notifications->find($id);
        self::assertNotNull($contact);

        return $contact;
    }

    /**
     * @return list<MatchedCity>
     */
    private static function cities(QuakeWorld $world, int $id): array
    {
        return self::contact($world, $id)->cities;
    }

    private static function city(string $code, ?string $intensity, string $basis, bool $notYetReceived = false): MatchedCity
    {
        return new MatchedCity($code, '自宅', $code, $intensity, $notYetReceived, $basis);
    }
}
