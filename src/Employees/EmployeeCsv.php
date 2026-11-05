<?php

declare(strict_types=1);

namespace ReBuji\Employees;

/**
 * Employees as CSV, for Excel and back. Written as UTF-8 with a BOM and CRLF, which Excel opens as UTF-8; read as
 * UTF-8 or as CP932, which Excel writes for its plain "CSV" format.
 *
 * @author shimomo
 */
final class EmployeeCsv
{
    public const array HEADER = [
        'ID', '名前', '所属', 'メール1', 'メール2', 'メール3', 'メール4',
        '市町村1', '呼び名1', '市町村2', '呼び名2', '市町村3', '呼び名3', '市町村4', '呼び名4', '市町村5', '呼び名5',
        'メモ', '状態',
    ];

    private const string BOM = "\xEF\xBB\xBF";

    /**
     * @param list<Employee> $employees
     */
    public static function export(array $employees): string
    {
        $rows = [self::HEADER];

        foreach ($employees as $employee) {
            $row = [(string) $employee->id, $employee->name, $employee->department, ...array_pad($employee->emails, EmployeeValidator::MAX_EMAILS, '')];

            for ($i = 0; $i < EmployeeValidator::MAX_CITIES; $i++) {
                $row[] = $employee->cities[$i]->code ?? '';
                $row[] = $employee->cities[$i]->label ?? '';
            }

            $rows[] = [...$row, $employee->memo, $employee->active ? '有効' : '停止'];
        }

        return self::document($rows);
    }

    /**
     * Rows as a CSV file Excel opens correctly (BOM, CRLF).
     *
     * @param list<list<string>> $rows
     */
    public static function document(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '', "\r\n");
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return self::BOM . $csv;
    }

    /**
     * Rows that have something in them, each with its line (the header is line 1; a line break inside a cell does not
     * start a new line). A wrong header stops the reading.
     *
     * @return array{rows: list<array{line: int, id: ?int, input: EmployeeInput}>, errors: list<string>}
     */
    public static function parse(string $contents): array
    {
        $text = str_starts_with($contents, self::BOM) ? substr($contents, strlen(self::BOM)) : $contents;

        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'SJIS-win');
        }

        $records = self::read($text);

        if (self::withoutTrailingBlanks(array_map(trim(...), $records[0] ?? [])) !== self::HEADER) {
            return ['rows' => [], 'errors' => ['1 行目の見出しが違います。次の見出しにしてください：' . implode(',', self::HEADER)]];
        }

        $rows = [];
        $errors = [];

        foreach (array_slice($records, 1) as $index => $record) {
            $line = $index + 2;

            if (implode('', array_map(trim(...), $record)) === '') {
                continue;
            }

            $cells = array_pad($record, count(self::HEADER), '');
            $id = trim($cells[0]);
            $state = trim($cells[18]);

            if ($id !== '' && preg_match('/^[0-9]+$/', $id) !== 1) {
                $errors[] = $line . ' 行目：ID は数字で書いてください：' . $id;
            }

            if (!in_array($state, ['', '有効', '停止'], true)) {
                $errors[] = $line . ' 行目：状態は「有効」か「停止」にしてください：' . $state;
            }

            $cities = [];

            for ($i = 0; $i < EmployeeValidator::MAX_CITIES; $i++) {
                $cities[] = ['code' => $cells[7 + $i * 2], 'label' => $cells[8 + $i * 2]];
            }

            $rows[] = [
                'line' => $line,
                'id' => preg_match('/^[0-9]+$/', $id) === 1 ? (int) $id : null,
                'input' => EmployeeInput::from($cells[1], $cells[2], array_slice($cells, 3, EmployeeValidator::MAX_EMAILS), $cities, $cells[17], $state !== '停止'),
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @return list<list<string>>
     */
    private static function read(string $text): array
    {
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);
        fwrite($handle, $text);
        rewind($handle);
        $records = [];

        while (is_array($cells = fgetcsv($handle, null, ',', '"', ''))) {
            $records[] = array_map(static fn(?string $cell): string => $cell ?? '', $cells);
        }

        fclose($handle);

        return $records;
    }

    /**
     * Excel may write empty cells after the last heading.
     *
     * @param list<string> $cells
     *
     * @return list<string>
     */
    private static function withoutTrailingBlanks(array $cells): array
    {
        while ($cells !== [] && $cells[count($cells) - 1] === '') {
            array_pop($cells);
        }

        return $cells;
    }
}
