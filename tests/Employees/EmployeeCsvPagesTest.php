<?php

declare(strict_types=1);

namespace ReBuji\Tests\Employees;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use ReBuji\Employees\EmployeeCsv;
use ReBuji\Employees\EmployeeInput;
use ReBuji\Employees\EmployeeRepository;
use ReBuji\Tests\Support\TemporaryDirectory;
use ReBuji\Tests\Support\TestApp;
use Slim\App;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UploadedFileFactory;

/**
 * @author shimomo
 */
final class EmployeeCsvPagesTest extends TestCase
{
    #[Test]
    public function downloadsTheEmployees(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $employees = $container->get(EmployeeRepository::class);
            $this->assertInstanceOf(EmployeeRepository::class, $employees);
            $employees->create(EmployeeInput::from('安否 花子', '', ['hanako@example.com'], [['code' => '1310100', 'label' => '']], '', true));
            $response = $app->handle(TestApp::request('GET', '/employees/export'));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
            $this->assertStringContainsString('re-buji-employees-20261105.csv', $response->getHeaderLine('Content-Disposition'));
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            $this->assertStringContainsString('hanako@example.com', (string) $response->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function uploadsAFile(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $csv = implode(',', EmployeeCsv::HEADER) . "\r\n,安否 花子,,hanako@example.com,,,,1310100,,,,,,,,,,,\r\n";
            $response = $this->upload($app, $container, $csv);

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('/employees', $response->getHeaderLine('Location'));
            $this->assertStringContainsString('1 人を登録し、0 人を更新しました。', (string) $app->handle(TestApp::request('GET', '/employees'))->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function showsWhatIsWrongWithAFile(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $response = $this->upload($app, $container, "名前,メール\r\n");

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('1 行目の見出しが違います。', (string) $response->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function asksForAFile(): void
    {
        [$app, $container, $root] = $this->loggedIn();

        try {
            $response = $app->handle(TestApp::request('POST', '/employees/import', TestApp::csrf($container)));

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('ファイルを選んでください。', (string) $response->getBody());
        } finally {
            TemporaryDirectory::remove($root);
        }
    }

    #[Test]
    public function downloadsTheCityCodes(): void
    {
        [$app, , $root] = $this->loggedIn();

        try {
            $response = $app->handle(TestApp::request('GET', '/employees/cities.csv'));
            $body = (string) $response->getBody();

            $this->assertStringStartsWith("\xEF\xBB\xBF市町村コード,都道府県,市町村\r\n", $body);
            $this->assertStringContainsString("1310100,東京都,東京千代田区\r\n", $body);
            $this->assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
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

    /**
     * @param App<ContainerInterface> $app
     */
    private function upload(App $app, ContainerInterface $container, string $csv): ResponseInterface
    {
        $file = (new UploadedFileFactory())->createUploadedFile((new StreamFactory())->createStream($csv), strlen($csv), UPLOAD_ERR_OK, 'employees.csv', 'text/csv');

        return $app->handle(TestApp::request('POST', '/employees/import', TestApp::csrf($container))->withUploadedFiles(['file' => $file]));
    }
}
