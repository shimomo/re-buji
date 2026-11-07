<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use DateTimeImmutable;
use LogicException;
use Psr\Log\NullLogger;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Employees\EmployeeCity;
use ReBuji\Employees\EmployeeInput;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Feed\TelegramRepository;
use ReBuji\Quakes\DispatchJob;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\EarthquakeRepository;
use ReBuji\Quakes\NotificationRepository;
use ReBuji\Settings\Settings;

/**
 * A migrated in-memory database with the quake parts wired together, the clock stopped at a sample earthquake.
 *
 * @author shimomo
 */
final class QuakeWorld
{
    public readonly Database $db;

    public readonly FrozenClock $clock;

    public readonly EmployeeRepository $employees;

    public readonly TelegramRepository $telegrams;

    public readonly EarthquakeRepository $earthquakes;

    public readonly NotificationRepository $notifications;

    public readonly Settings $settings;

    public readonly DispatchJob $dispatch;

    private int $people = 0;

    public function __construct(string $now)
    {
        $this->db = Database::sqliteInMemory();
        $this->clock = new FrozenClock(new DateTimeImmutable($now));
        (new Migrator($this->db, Migrations::all(), $this->clock))->migrate();
        $this->employees = new EmployeeRepository($this->db, $this->clock);
        $this->telegrams = new TelegramRepository($this->db);
        $this->earthquakes = new EarthquakeRepository($this->db, $this->clock);
        $this->notifications = new NotificationRepository($this->db, $this->clock);
        $this->settings = new Settings($this->db, $this->clock);
        $this->dispatch = new DispatchJob($this->db, $this->telegrams, $this->earthquakes, $this->notifications, $this->employees, $this->settings, new NullLogger());
    }

    /**
     * An employee with one municipality and as many addresses as asked (person1@example.com, …).
     */
    public function person(string $name, string $code, string $label = '', bool $active = true, int $addresses = 1): int
    {
        $this->people++;
        $emails = [];

        for ($i = 1; $i <= $addresses; $i++) {
            $emails[] = 'person' . $this->people . ($i === 1 ? '' : '-' . $i) . '@example.com';
        }

        return $this->employees->create(new EmployeeInput($name, '', $emails, [new EmployeeCity($code, $label)], '', $active));
    }

    /**
     * Stores sample telegrams as the feed would, then runs the dispatch job.
     */
    public function receive(string ...$files): void
    {
        JmaSamples::store($this->telegrams, $this->clock->now(), ...$files);
        $this->dispatch->run($this->clock->now());
    }

    public function quake(string $eventId): Earthquake
    {
        return $this->earthquakes->findByEventId($eventId) ?? throw new LogicException('No quake for ' . $eventId);
    }
}
