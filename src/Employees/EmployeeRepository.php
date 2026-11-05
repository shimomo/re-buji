<?php

declare(strict_types=1);

namespace ReBuji\Employees;

use Psr\Clock\ClockInterface;
use ReBuji\Auth\AdminRepository;
use ReBuji\Database\Database;
use ReBuji\Time;

/**
 * Employees in three tables: the person, their addresses and their municipalities. Saving replaces the addresses and
 * municipalities as a whole, inside a transaction.
 *
 * @author shimomo
 */
final class EmployeeRepository
{
    public function __construct(private readonly Database $db, private readonly ClockInterface $clock)
    {
        //
    }

    public function create(EmployeeInput $input): int
    {
        return $this->db->transaction(function (Database $db) use ($input): int {
            $now = Time::format($this->clock->now());
            $db->execute(
                'INSERT INTO employees (name, department, memo, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$input->name, $input->department, $input->memo, $input->active ? 1 : 0, $now, $now],
            );
            $id = $db->lastInsertId();
            self::writeDetails($db, $id, $input);

            return $id;
        });
    }

    public function update(int $id, EmployeeInput $input): void
    {
        $this->db->transaction(function (Database $db) use ($id, $input): void {
            $db->execute(
                'UPDATE employees SET name = ?, department = ?, memo = ?, active = ?, updated_at = ? WHERE id = ?',
                [$input->name, $input->department, $input->memo, $input->active ? 1 : 0, Time::format($this->clock->now()), $id],
            );
            $db->execute('DELETE FROM employee_emails WHERE employee_id = ?', [$id]);
            $db->execute('DELETE FROM employee_cities WHERE employee_id = ?', [$id]);
            self::writeDetails($db, $id, $input);
        });
    }

    /**
     * Removes these employees' addresses, so an import can hand an address from one person to another in any row
     * order: the unique index would otherwise see the address twice between two rows.
     *
     * @param list<int> $ids
     */
    public function clearAddresses(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->db->execute('DELETE FROM employee_emails WHERE employee_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids);
    }

    /**
     * The addresses and municipalities go with the employee (ON DELETE CASCADE).
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM employees WHERE id = ?', [$id]);
    }

    public function find(int $id): ?Employee
    {
        $row = $this->db->fetchOne('SELECT id, name, department, memo, active FROM employees WHERE id = ?', [$id]);

        if ($row === null) {
            return null;
        }

        $emails = $this->db->fetchAll('SELECT employee_id, address FROM employee_emails WHERE employee_id = ? ORDER BY position', [$id]);
        $cities = $this->db->fetchAll('SELECT employee_id, city_code, label FROM employee_cities WHERE employee_id = ? ORDER BY position', [$id]);

        return self::assemble([$row], $emails, $cities)[0];
    }

    /**
     * Every employee in the order they were registered, read with three queries.
     *
     * @return list<Employee>
     */
    public function all(): array
    {
        return self::assemble(
            $this->db->fetchAll('SELECT id, name, department, memo, active FROM employees ORDER BY id'),
            $this->db->fetchAll('SELECT employee_id, address FROM employee_emails ORDER BY employee_id, position'),
            $this->db->fetchAll('SELECT employee_id, city_code, label FROM employee_cities ORDER BY employee_id, position'),
        );
    }

    /**
     * @return array{all: int, active: int}
     */
    public function counts(): array
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS all_count, COALESCE(SUM(active), 0) AS active_count FROM employees');

        return ['all' => (int) ($row['all_count'] ?? 0), 'active' => (int) ($row['active_count'] ?? 0)];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    public function addressOwner(string $address): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT e.id, e.name FROM employee_emails a JOIN employees e ON e.id = a.employee_id WHERE a.address_key = ?',
            [self::addressKey($address)],
        );

        return $row === null ? null : ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    /**
     * Saved codes that the current code table no longer knows, so these people could not be matched against an
     * earthquake (design 3.1: re-check after a version with a newer table is installed).
     *
     * @return list<array{employee_id: int, name: string, code: string}>
     */
    public function unknownCities(): array
    {
        $unknown = [];
        $rows = $this->db->fetchAll(
            'SELECT c.employee_id, e.name, c.city_code FROM employee_cities c JOIN employees e ON e.id = c.employee_id ORDER BY c.employee_id, c.position',
        );

        foreach ($rows as $row) {
            $code = (string) $row['city_code'];

            if (Cities::find($code) === null) {
                $unknown[] = ['employee_id' => (int) $row['employee_id'], 'name' => (string) $row['name'], 'code' => $code];
            }
        }

        return $unknown;
    }

    /**
     * Addresses compare without case, like admin logins.
     */
    public static function addressKey(string $address): string
    {
        return AdminRepository::normalize($address);
    }

    private static function writeDetails(Database $db, int $id, EmployeeInput $input): void
    {
        foreach ($input->emails as $position => $address) {
            $db->execute(
                'INSERT INTO employee_emails (employee_id, position, address, address_key) VALUES (?, ?, ?, ?)',
                [$id, $position, $address, self::addressKey($address)],
            );
        }

        foreach ($input->cities as $position => $city) {
            $db->execute('INSERT INTO employee_cities (employee_id, position, city_code, label) VALUES (?, ?, ?, ?)', [$id, $position, $city->code, $city->label]);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $emailRows
     * @param list<array<string, mixed>> $cityRows
     *
     * @return list<Employee>
     */
    private static function assemble(array $rows, array $emailRows, array $cityRows): array
    {
        $emails = [];
        $cities = [];

        foreach ($emailRows as $row) {
            $emails[(int) $row['employee_id']][] = (string) $row['address'];
        }

        foreach ($cityRows as $row) {
            $cities[(int) $row['employee_id']][] = new EmployeeCity((string) $row['city_code'], (string) $row['label']);
        }

        return array_map(static function (array $row) use ($emails, $cities): Employee {
            $id = (int) $row['id'];

            return new Employee($id, (string) $row['name'], (string) $row['department'], $emails[$id] ?? [], $cities[$id] ?? [], (string) $row['memo'], (int) $row['active'] === 1);
        }, $rows);
    }
}
