<?php

declare(strict_types=1);

namespace ReBuji\Employees;

use DateTimeZone;
use League\Plates\Engine;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use ReBuji\Http\Flash;
use ReBuji\Http\Url;

/**
 * /employees/export, /employees/import and /employees/cities.csv (the codes to write in a CSV).
 *
 * @author shimomo
 */
final class EmployeeCsvController
{
    public function __construct(
        private readonly EmployeeRepository $employees,
        private readonly EmployeeImport $import,
        private readonly Engine $templates,
        private readonly ResponseFactoryInterface $responses,
        private readonly Url $url,
        private readonly Flash $flash,
        private readonly ClockInterface $clock,
    ) {
        //
    }

    public function export(): ResponseInterface
    {
        $date = $this->clock->now()->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Ymd');

        return $this->download('re-buji-employees-' . $date . '.csv', EmployeeCsv::export($this->employees->all()));
    }

    public function cities(): ResponseInterface
    {
        $rows = [['市町村コード', '都道府県', '市町村']];

        foreach (Cities::byPrefecture() as $prefecture) {
            foreach ($prefecture['cities'] as $city) {
                $rows[] = [$city['code'], $prefecture['name'], $city['name']];
            }
        }

        return $this->download('re-buji-cities.csv', EmployeeCsv::document($rows));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->render($request, []);
    }

    public function import(ServerRequestInterface $request): ResponseInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;

        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->render($request, ['ファイルを選んでください。']);
        }

        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE) {
            return $this->render($request, ['ファイルが大きすぎます（このサーバーの上限は ' . (string) ini_get('upload_max_filesize') . ' です）。']);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->render($request, ['ファイルを受け取れませんでした。もう一度お試しください。']);
        }

        $result = $this->import->run((string) $file->getStream());

        if ($result->errors !== []) {
            return $this->render($request, $result->errors);
        }

        $this->flash->put($result->created . ' 人を登録し、' . $result->updated . ' 人を更新しました。');

        return $this->responses->createResponse(302)->withHeader('Location', $this->url->to('/employees'));
    }

    private function download(string $filename, string $csv): ResponseInterface
    {
        $response = $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write($csv);

        return $response;
    }

    /**
     * @param list<string> $errors
     */
    private function render(ServerRequestInterface $request, array $errors): ResponseInterface
    {
        $response = $this->responses->createResponse(200);
        $response->getBody()->write($this->templates->render('employees/import', [
            'title' => 'CSV の取り込み',
            'admin' => $request->getAttribute('admin'),
            'errors' => $errors,
            'header' => EmployeeCsv::HEADER,
        ]));

        return $response;
    }
}
