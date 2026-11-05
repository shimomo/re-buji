<?php

declare(strict_types=1);

namespace ReBuji\Employees;

use League\Plates\Engine;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReBuji\Http\Flash;
use ReBuji\Http\Url;
use Slim\Exception\HttpNotFoundException;

/**
 * /employees: the list, and the form to add, edit and delete one employee.
 *
 * @author shimomo
 */
final class EmployeesController
{
    public function __construct(
        private readonly EmployeeRepository $employees,
        private readonly EmployeeValidator $validator,
        private readonly Engine $templates,
        private readonly ResponseFactoryInterface $responses,
        private readonly Url $url,
        private readonly Flash $flash,
    ) {
        //
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $employees = array_map(static fn(Employee $employee): array => [
            'id' => $employee->id,
            'name' => $employee->name,
            'department' => $employee->department,
            'cities' => array_map(self::cityLabel(...), $employee->cities),
            'emails' => count($employee->emails),
            'active' => $employee->active,
        ], $this->employees->all());

        return $this->render($request, 'employees/index', [
            'title' => '従業員',
            'employees' => $employees,
            'unknownCities' => $this->employees->unknownCities(),
        ]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return $this->form($request, null, new EmployeeInput('', '', [], [], '', true), []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = self::input($request);
        $errors = $this->validator->validate($input);

        if ($errors !== []) {
            return $this->form($request, null, $input, $errors);
        }

        $this->employees->create($input);
        $this->flash->put($input->name . ' さんを登録しました。');

        return $this->redirect('/employees');
    }

    /**
     * @param array<string, string> $args
     */
    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $employee = $this->find($request, $args);
        $input = new EmployeeInput($employee->name, $employee->department, $employee->emails, $employee->cities, $employee->memo, $employee->active);

        return $this->form($request, $employee->id, $input, []);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $employee = $this->find($request, $args);
        $input = self::input($request);
        $errors = $this->validator->validate($input, $employee->id);

        if ($errors !== []) {
            return $this->form($request, $employee->id, $input, $errors);
        }

        $this->employees->update($employee->id, $input);
        $this->flash->put($input->name . ' さんの登録を直しました。');

        return $this->redirect('/employees');
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $employee = $this->find($request, $args);
        $this->employees->delete($employee->id);
        $this->flash->put($employee->name . ' さんを削除しました。');

        return $this->redirect('/employees');
    }

    /**
     * "東京都 東京千代田区（勤務地）", or the bare code when the current code table no longer has it.
     */
    private static function cityLabel(EmployeeCity $city): string
    {
        $name = Cities::find($city->code)?->label() ?? $city->code . '（判定に使えないコード）';

        return $city->label === '' ? $name : $name . '（' . $city->label . '）';
    }

    /**
     * @param array<string, string> $args
     */
    private function find(ServerRequestInterface $request, array $args): Employee
    {
        $employee = $this->employees->find((int) ($args['id'] ?? '0'));

        if ($employee === null) {
            throw new HttpNotFoundException($request);
        }

        return $employee;
    }

    /**
     * @param list<string> $errors
     */
    private function form(ServerRequestInterface $request, ?int $id, EmployeeInput $input, array $errors): ResponseInterface
    {
        $byPrefecture = Cities::byPrefecture();
        $citiesOf = [];

        foreach ($byPrefecture as $prefecture) {
            $citiesOf[$prefecture['code']] = $prefecture['cities'];
        }

        // Each row starts with its prefecture's municipalities, so an edit form is right before any script runs.
        $rows = [];

        foreach ($input->cities as $city) {
            $prefecture = Cities::find($city->code)?->prefectureCode ?? substr($city->code, 0, 2);
            $rows[] = ['prefecture' => $prefecture, 'code' => $city->code, 'label' => $city->label, 'cities' => $citiesOf[$prefecture] ?? []];
        }

        while (count($rows) < EmployeeValidator::MAX_CITIES) {
            $rows[] = ['prefecture' => '', 'code' => '', 'label' => '', 'cities' => []];
        }

        return $this->render($request, 'employees/form', [
            'title' => $id === null ? '従業員の追加' : '従業員の編集',
            'action' => $id === null ? '/employees' : '/employees/' . $id,
            'id' => $id,
            'errors' => $errors,
            'values' => [
                'name' => $input->name,
                'department' => $input->department,
                'emails' => array_pad($input->emails, EmployeeValidator::MAX_EMAILS, ''),
                'memo' => $input->memo,
                'active' => $input->active,
            ],
            'rows' => $rows,
            'prefectures' => Cities::prefectures(),
            'cityData' => json_encode($byPrefecture, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Only strings are taken from the form; anything shaped otherwise counts as empty.
     */
    private static function input(ServerRequestInterface $request): EmployeeInput
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $emails = array_map(self::text(...), self::list($body['emails'] ?? null));
        $cities = array_map(static function (mixed $row): array {
            $fields = is_array($row) ? $row : [];

            return ['code' => self::text($fields['code'] ?? null), 'label' => self::text($fields['label'] ?? null)];
        }, self::list($body['cities'] ?? null));

        return EmployeeInput::from(
            self::text($body['name'] ?? null),
            self::text($body['department'] ?? null),
            $emails,
            $cities,
            self::text($body['memo'] ?? null),
            self::text($body['active'] ?? null) === '1',
        );
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(ServerRequestInterface $request, string $template, array $data): ResponseInterface
    {
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render($template, $data + ['admin' => $request->getAttribute('admin')]));

        return $response;
    }

    private function redirect(string $path): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to($path));
    }
}
