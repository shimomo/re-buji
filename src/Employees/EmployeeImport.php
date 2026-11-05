<?php

declare(strict_types=1);

namespace ReBuji\Employees;

use ReBuji\Database\Database;

/**
 * Imports a CSV of employees all or nothing: every row is checked first, and one wrong row means nothing is written,
 * so nobody has to work out how far an import got. Rows with an ID update that employee, rows without one add a new
 * employee, and employees missing from the file are left alone.
 *
 * @author shimomo
 */
final class EmployeeImport
{
    public function __construct(
        private readonly Database $db,
        private readonly EmployeeRepository $employees,
        private readonly EmployeeValidator $validator,
    ) {
        //
    }

    public function run(string $contents): ImportResult
    {
        $parsed = EmployeeCsv::parse($contents);
        $errors = $parsed['errors'];
        $rows = $parsed['rows'];
        $existing = [];

        foreach ($this->employees->all() as $employee) {
            $existing[$employee->id] = $employee;
        }

        $idsInFile = array_flip(array_filter(array_map(static fn(array $row): ?int => $row['id'], $rows), static fn(?int $id): bool => $id !== null));

        // Addresses are counted as they will be after the import: employees in the file by the file, the rest by the
        // database. So an address may move from one person to another within the same file.
        $owners = [];

        foreach ($existing as $id => $employee) {
            if (!isset($idsInFile[$id])) {
                foreach ($employee->emails as $address) {
                    $owners[EmployeeRepository::addressKey($address)] = ['line' => null, 'name' => $employee->name];
                }
            }
        }

        $idLines = [];

        foreach ($rows as $row) {
            $prefix = $row['line'] . ' 行目：';
            $id = $row['id'];

            if ($id !== null && isset($idLines[$id])) {
                $errors[] = $prefix . 'ID ' . $id . ' は ' . $idLines[$id] . ' 行目にもあります。';
            } elseif ($id !== null && !isset($existing[$id])) {
                $errors[] = $prefix . 'ID ' . $id . ' の人はいません。';
            }

            if ($id !== null) {
                $idLines[$id] ??= $row['line'];
            }

            foreach ($this->validator->validateFields($row['input']) as $error) {
                $errors[] = $prefix . $error;
            }

            $keys = [];

            foreach ($row['input']->emails as $address) {
                $key = EmployeeRepository::addressKey($address);

                if (!EmployeeValidator::isAddress($address) || isset($keys[$key])) {
                    continue;
                }

                $keys[$key] = true;
                $owner = $owners[$key] ?? null;

                if ($owner === null) {
                    $owners[$key] = ['line' => $row['line'], 'name' => $row['input']->name];
                } elseif ($owner['line'] !== null) {
                    $errors[] = $prefix . 'このメールアドレスは ' . $owner['line'] . ' 行目と同じです：' . $address;
                } else {
                    $errors[] = $prefix . 'このメールアドレスは' . $owner['name'] . ' さんが使っています：' . $address;
                }
            }
        }

        if ($errors !== []) {
            return new ImportResult($errors, 0, 0);
        }

        return $this->db->transaction(function () use ($rows): ImportResult {
            $created = 0;
            $updated = 0;

            foreach ($rows as $row) {
                if ($row['id'] === null) {
                    $this->employees->create($row['input']);
                    $created++;
                } else {
                    $this->employees->update($row['id'], $row['input']);
                    $updated++;
                }
            }

            return new ImportResult([], $created, $updated);
        });
    }
}
