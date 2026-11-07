<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use DateTimeImmutable;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use ReBuji\Database\Database;
use ReBuji\Database\Migrations;
use ReBuji\Database\Migrator;
use ReBuji\Employees\EmployeeCity;
use ReBuji\Employees\EmployeeInput;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Feed\TelegramRepository;
use ReBuji\Mail\MessageRepository;
use ReBuji\Mail\NotificationMail;
use ReBuji\Mail\NotificationSender;
use ReBuji\Mail\RateLimit;
use ReBuji\Quakes\DispatchJob;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\EarthquakeRepository;
use ReBuji\Quakes\Notification;
use ReBuji\Quakes\NotificationRepository;
use ReBuji\Quakes\QuakeSnapshot;
use ReBuji\Settings\Settings;
use ReBuji\SystemState;

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

    public readonly MessageRepository $messages;

    public readonly SystemState $state;

    public readonly RecordingMailSender $mailer;

    private int $people = 0;

    private ?int $quake = null;

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
        $this->messages = new MessageRepository($this->db);
        $this->state = new SystemState($this->db, $this->clock);
        $this->mailer = new RecordingMailSender();
        $this->settings->save(['smtp.host' => 'smtp.example.co.jp', 'mail.from_address' => 'anpi@example.co.jp']);
    }

    /**
     * The sender, reading the given clock for its run (the world's clock by default).
     */
    public function sender(?ClockInterface $clock = null): NotificationSender
    {
        return new NotificationSender(
            $this->notifications,
            $this->earthquakes,
            $this->employees,
            $this->messages,
            new RateLimit($this->messages, $this->settings),
            new NotificationMail($this->settings, $this->clock),
            $this->mailer,
            $this->settings,
            $this->state,
            $clock ?? $this->clock,
            new NullLogger(),
        );
    }

    /**
     * A contact for one shared quake, as the dispatch job would make it.
     */
    public function contact(int $employeeId, int $priority = 5, string $state = Notification::PENDING, ?DateTimeImmutable $notBefore = null): int
    {
        $this->quake ??= $this->earthquakes->createQuake(
            '20090811050711',
            new QuakeSnapshot('駿河湾・最大震度6弱', '駿河湾', '6.6', '6-', null, '震源・震度に関する情報', null),
            '5-',
            Earthquake::AUTO,
            false,
            new DateTimeImmutable('2009-08-10T20:07:00Z'),
        );
        $employee = $this->employees->find($employeeId) ?? throw new LogicException('No employee ' . $employeeId);

        return $this->notifications->create($this->quake, $employee, [], $priority, $state, $notBefore ?? $this->clock->now());
    }

    public function contactNow(int $id): Notification
    {
        return $this->notifications->find($id) ?? throw new LogicException('No contact ' . $id);
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
