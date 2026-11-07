<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use DateTimeImmutable;
use LogicException;
use Psr\Container\ContainerInterface;
use ReBuji\Database\Database;
use ReBuji\Employees\EmployeeCity;
use ReBuji\Employees\EmployeeInput;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Quakes\Earthquake;
use ReBuji\Quakes\EarthquakeRepository;
use ReBuji\Quakes\Notification;
use ReBuji\Quakes\NotificationRepository;
use ReBuji\Quakes\QuakeSnapshot;

/**
 * Quakes and contacts for page tests, written through the app's own repositories.
 *
 * @author shimomo
 */
final class QuakeRecords
{
    public readonly Database $db;

    public readonly EarthquakeRepository $earthquakes;

    public readonly NotificationRepository $notifications;

    private readonly EmployeeRepository $employees;

    private int $people = 0;

    public function __construct(ContainerInterface $container)
    {
        $db = $container->get(Database::class);
        $earthquakes = $container->get(EarthquakeRepository::class);
        $notifications = $container->get(NotificationRepository::class);
        $employees = $container->get(EmployeeRepository::class);
        assert($db instanceof Database && $earthquakes instanceof EarthquakeRepository && $notifications instanceof NotificationRepository && $employees instanceof EmployeeRepository);
        $this->db = $db;
        $this->earthquakes = $earthquakes;
        $this->notifications = $notifications;
        $this->employees = $employees;
    }

    public function quake(string $eventId, string $title, string $detectedAt, string $delivery = Earthquake::AUTO): int
    {
        return $this->earthquakes->createQuake(
            $eventId,
            new QuakeSnapshot($title, null, null, null, null, null, null),
            '5-',
            $delivery,
            $delivery === Earthquake::HELD,
            new DateTimeImmutable($detectedAt),
        );
    }

    public function drill(string $at): int
    {
        $this->db->execute(
            "INSERT INTO earthquakes (kind, event_id, title, detected_at, threshold, delivery, late, created_at, updated_at) VALUES ('drill', NULL, '訓練', ?, NULL, 'auto', 0, ?, ?)",
            [$at, $at, $at],
        );

        return $this->db->lastInsertId();
    }

    /**
     * A contact for a new employee.
     */
    public function contact(int $quakeId, string $state = Notification::PENDING, ?string $notBefore = null): int
    {
        $this->people++;
        $id = $this->employees->create(new EmployeeInput('従業員 ' . $this->people, '', ['person' . $this->people . '@example.com'], [new EmployeeCity('2221300', '')], '', true));
        $employee = $this->employees->find($id) ?? throw new LogicException('No employee ' . $id);

        $notBefore = $notBefore === null ? null : new DateTimeImmutable($notBefore);

        if ($state !== Notification::SENT) {
            return $this->notifications->create($quakeId, $employee, [], 5, $state, $notBefore);
        }

        // Sent the way the sender records it.
        $contact = $this->notifications->create($quakeId, $employee, [], 5, Notification::PENDING, $notBefore);
        $this->notifications->markSent($contact, new DateTimeImmutable('2026-11-05T03:00:00Z'), null);

        return $contact;
    }
}
