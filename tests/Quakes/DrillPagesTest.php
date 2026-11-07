<?php

declare(strict_types=1);

namespace ReBuji\Tests\Quakes;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use ReBuji\Employees\EmployeeCity;
use ReBuji\Employees\EmployeeInput;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\MatchedCity;
use ReBuji\Quakes\Notification;
use ReBuji\Tests\Support\FrozenClock;
use ReBuji\Tests\Support\QuakeRecords;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;

/**
 * @author shimomo
 */
final class DrillPagesTest extends TestCase
{
    #[Test]
    public function sendsADrillToEveryone(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            [$hanako, , $stopped] = self::people($container);
            $form = (string) $app->handle(TestApp::request('GET', '/drills/new'))->getBody();
            $this->assertStringContainsString('山田 花子', $form);
            $this->assertStringNotContainsString('停止 三郎', $form);

            $response = $app->handle(TestApp::request('POST', '/drills', ['target' => 'all'] + TestApp::csrf($container)));

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/earthquakes', $response->getHeaderLine('Location'));
            $records = new QuakeRecords($container);
            $drills = $records->earthquakes->summaries();
            $this->assertCount(1, $drills);
            $drill = $drills[0]['earthquake'];
            $this->assertSame(Earthquake::KIND_DRILL, $drill->kind);
            $this->assertSame('訓練', $drill->title);
            $this->assertNull($drill->eventId);
            $this->assertEquals(new DateTimeImmutable('2026-11-05T03:12:00Z'), $drill->detectedAt);

            $contacts = $records->notifications->forEarthquake($drill->id);
            $this->assertCount(2, $contacts);
            $this->assertSame($hanako, $contacts[0]->employeeId);
            $this->assertSame(Notification::PENDING, $contacts[0]->state);
            $this->assertSame(0, $contacts[0]->priority);
            $this->assertEquals(new DateTimeImmutable('2026-11-05T03:12:00Z'), $contacts[0]->notBefore);
            $this->assertEquals([new MatchedCity('2221300', '勤務地', '静岡県 掛川市', null, false, MatchedCity::BASIS_NONE)], $contacts[0]->cities);
            $this->assertNull($records->notifications->findFor($drill->id, $stopped));
            $this->assertStringContainsString('訓練の連絡を 2 人に送ります。cron が順に送ります。', (string) $app->handle(TestApp::request('GET', '/earthquakes'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function sendsADrillToChosenPeople(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            [, $ichiro, $stopped] = self::people($container);

            $app->handle(TestApp::request('POST', '/drills')->withParsedBody(['target' => 'chosen', 'employees' => [(string) $ichiro, (string) $stopped]] + TestApp::csrf($container)));

            $records = new QuakeRecords($container);
            $contacts = $records->notifications->forEarthquake($records->earthquakes->summaries()[0]['earthquake']->id);
            $this->assertCount(1, $contacts);
            $this->assertSame($ichiro, $contacts[0]->employeeId);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesADrillWithNobody(): void
    {
        [$app, $container, $root] = self::loggedIn();

        try {
            self::people($container);

            $response = $app->handle(TestApp::request('POST', '/drills', ['target' => 'chosen'] + TestApp::csrf($container)));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('送る人を選んでください。', (string) $response->getBody());
            $this->assertSame([], (new QuakeRecords($container))->earthquakes->summaries());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * Two active people and one stopped.
     *
     * @return array{int, int, int}
     */
    private static function people(ContainerInterface $container): array
    {
        $employees = $container->get(EmployeeRepository::class);
        assert($employees instanceof EmployeeRepository);

        return [
            $employees->create(new EmployeeInput('山田 花子', '静岡支店', ['hanako@example.com'], [new EmployeeCity('2221300', '勤務地')], '', true)),
            $employees->create(new EmployeeInput('佐藤 一郎', '本社', ['ichiro@example.com'], [new EmployeeCity('1310100', '')], '', true)),
            $employees->create(new EmployeeInput('停止 三郎', '本社', ['saburo@example.com'], [new EmployeeCity('1310100', '')], '', false)),
        ];
    }

    /**
     * @return array{App<ContainerInterface>, ContainerInterface, string}
     */
    private static function loggedIn(): array
    {
        [$app, $container, $root] = TestApp::installed([ClockInterface::class => new FrozenClock()]);
        TestApp::logIn($app, $container);

        return [$app, $container, $root];
    }
}
