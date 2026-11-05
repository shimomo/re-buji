<?php

declare(strict_types=1);

namespace ReBuji\Tests\Employees;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use ReBuji\Database\Database;
use ReBuji\Employees\EmployeeInput;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;

/**
 * @author shimomo
 */
final class EmployeePagesTest extends TestCase
{
    #[Test]
    public function registersAnEmployeeFromTheForm(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $response = $this->post($app, $container, '/employees', $this->hanako());
            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/employees', $response->getHeaderLine('Location'));

            $list = (string) $app->handle(TestApp::request('GET', '/employees'))->getBody();
            $this->assertStringContainsString('安否 花子 さんを登録しました。', $list);
            $this->assertStringContainsString('安否 花子', $list);
            $this->assertStringContainsString('東京都 東京千代田区（勤務地）', $list);
            $this->assertStringContainsString('総務部', $list);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function showsErrorsAndKeepsWhatWasEntered(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $response = $this->post($app, $container, '/employees', ['cities' => [['code' => '0110000', 'label' => '']]] + $this->hanako());
            $body = (string) $response->getBody();

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('市町村コード 0110000 は地震の情報で使えません。', $body);
            $this->assertStringContainsString('value="安否 花子"', $body);
            $this->assertSame(0, $this->employees($container)->counts()['all']);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function refusesARowWhoseMunicipalityWasNotChosen(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $body = ['cities' => [
                ['prefecture' => '13', 'code' => '1310100', 'label' => '勤務地'],
                ['prefecture' => '01', 'code' => '', 'label' => '自宅'],
            ]] + $this->hanako();
            $response = $this->post($app, $container, '/employees', $body);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('市町村が入っていません：自宅', (string) $response->getBody());
            $this->assertSame(0, $this->employees($container)->counts()['all']);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function editsAndStopsAnEmployee(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $id = $this->employees($container)->create($this->input());
            $edit = (string) $app->handle(TestApp::request('GET', '/employees/' . $id))->getBody();
            $this->assertStringContainsString('value="hanako@example.com"', $edit);

            $body = $this->hanako();
            unset($body['active']);
            $response = $this->post($app, $container, '/employees/' . $id, $body);

            $this->assertSame('/employees', $response->getHeaderLine('Location'));
            $this->assertStringContainsString('停止', (string) $app->handle(TestApp::request('GET', '/employees'))->getBody());
            $this->assertFalse($this->employees($container)->find($id)?->active);
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function deletesAnEmployee(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $id = $this->employees($container)->create($this->input());
            $response = $this->post($app, $container, '/employees/' . $id . '/delete', []);

            $this->assertSame('/employees', $response->getHeaderLine('Location'));
            $this->assertStringContainsString('安否 花子 さんを削除しました。', (string) $app->handle(TestApp::request('GET', '/employees'))->getBody());
            $this->assertNull($this->employees($container)->find($id));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function editingAMissingEmployeeIsNotFound(): void
    {
        [$app, , $root] = $this->loggedIn();

        try {
            $this->assertSame(404, $app->handle(TestApp::request('GET', '/employees/999'))->getStatusCode());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function formCarriesTheCityList(): void
    {
        [$app, , $root] = $this->loggedIn();

        try {
            $body = (string) $app->handle(TestApp::request('GET', '/employees/new'))->getBody();

            $this->assertStringContainsString('id="rebuji-cities"', $body);
            $this->assertStringContainsString('"1310100"', $body);
            $this->assertStringContainsString('東京千代田区', $body);
            // Five municipality rows, each with a select of the 47 prefectures.
            $this->assertSame(5 * 47, preg_match_all('/<option value="[0-9]{2}"/', $body));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function warnsAboutCodesThatCanNoLongerBeJudged(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $id = $this->employees($container)->create($this->input());
            $db = $container->get(Database::class);
            $this->assertInstanceOf(Database::class, $db);
            $db->execute('INSERT INTO employee_cities (employee_id, position, city_code, label) VALUES (?, 9, ?, ?)', [$id, '2213100', '実家']);

            foreach (['/', '/employees'] as $path) {
                $body = (string) $app->handle(TestApp::request('GET', $path))->getBody();
                $this->assertStringContainsString('判定に使えない市町村が登録されています。', $body, $path);
                $this->assertStringContainsString('2213100', $body, $path);
                $this->assertStringContainsString('安否 花子', $body, $path);
            }
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function homeShowsHowManyEmployeesThereAre(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $this->assertStringContainsString('従業員がまだ登録されていません。', (string) $app->handle(TestApp::request('GET', '/'))->getBody());

            $employees = $this->employees($container);
            $employees->create($this->input());
            $employees->create(EmployeeInput::from('安否 次郎', '', ['jiro@example.com'], [['code' => '1310100', 'label' => '']], '', false));

            $this->assertStringContainsString('従業員：2 人（停止中 1 人）', (string) $app->handle(TestApp::request('GET', '/'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function requiresLogin(): void
    {
        [$app, , $root] = TestApp::installed();

        try {
            $this->assertSame('/login', $app->handle(TestApp::request('GET', '/employees'))->getHeaderLine('Location'));
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    /**
     * @return array{App<ContainerInterface>, ContainerInterface, string}
     */
    private function loggedIn(): array
    {
        [$app, $container, $root] = TestApp::installed();
        TestApp::logIn($app, $container);

        return [$app, $container, $root];
    }

    private function employees(ContainerInterface $container): EmployeeRepository
    {
        $employees = $container->get(EmployeeRepository::class);
        $this->assertInstanceOf(EmployeeRepository::class, $employees);

        return $employees;
    }

    private function input(): EmployeeInput
    {
        return EmployeeInput::from('安否 花子', '総務部', ['hanako@example.com'], [['code' => '1310100', 'label' => '勤務地']], '', true);
    }

    /**
     * @return array<string, mixed>
     */
    private function hanako(): array
    {
        return [
            'name' => '安否 花子',
            'department' => '総務部',
            'emails' => ['hanako@example.com', '', '', ''],
            'cities' => [['prefecture' => '13', 'code' => '1310100', 'label' => '勤務地']],
            'memo' => '',
            'active' => '1',
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $body
     */
    private function post(App $app, ContainerInterface $container, string $path, array $body): ResponseInterface
    {
        // TestApp::request() takes flat string fields; the form sends arrays (emails[], cities[][]).
        return $app->handle(TestApp::request('POST', $path)->withParsedBody($body + TestApp::csrf($container)));
    }
}
