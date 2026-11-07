<?php

declare(strict_types=1);

namespace ReBuji\Quakes;

use League\Plates\Engine;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Database\Database;
use ReBuji\Employees\Employee;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Http\Flash;
use ReBuji\Http\Url;

/**
 * Drills (design 4): a contact to everyone or to chosen people, marked as a drill in the subject and the body, and
 * followed on the same screens as a quake.
 *
 * @author shimomo
 */
final class DrillsController
{
    public function __construct(
        private readonly EmployeeRepository $employees,
        private readonly EarthquakeRepository $earthquakes,
        private readonly NotificationRepository $notifications,
        private readonly Database $db,
        private readonly Engine $templates,
        private readonly ResponseFactoryInterface $responses,
        private readonly Url $url,
        private readonly Flash $flash,
        private readonly ClockInterface $clock,
    ) {
        //
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return $this->render($request, []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $chosen = array_map(intval(...), self::strings($body['employees'] ?? null));
        $everyone = ($body['target'] ?? null) === 'all';
        $people = array_values(array_filter(
            $this->active(),
            static fn(Employee $employee): bool => $everyone || in_array($employee->id, $chosen, true),
        ));

        if ($people === []) {
            return $this->render($request, ['送る人を選んでください。']);
        }

        $this->db->transaction(function () use ($people): void {
            $now = $this->clock->now();
            $drill = $this->earthquakes->createDrill($now);

            foreach ($people as $employee) {
                $this->notifications->create($drill, $employee, array_map(MatchedCity::registered(...), $employee->cities), 0, Notification::PENDING, $now);
            }
        });
        $this->flash->put('訓練の連絡を ' . count($people) . ' 人に送ります。cron が順に送ります。');

        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/earthquakes'));
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @return list<Employee>
     */
    private function active(): array
    {
        return array_values(array_filter($this->employees->all(), static fn(Employee $employee): bool => $employee->active));
    }

    /**
     * @param list<string> $errors
     */
    private function render(ServerRequestInterface $request, array $errors): ResponseInterface
    {
        $people = array_map(static fn(Employee $employee): array => ['id' => $employee->id, 'name' => $employee->name, 'department' => $employee->department], $this->active());
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('drills/new', [
            'title' => '訓練',
            'admin' => $request->getAttribute('admin'),
            'people' => $people,
            'errors' => $errors,
        ]));

        return $response;
    }
}
