<?php

declare(strict_types=1);

namespace ReBuji\Migrations;

use Override;
use ReBuji\Database\Migration;

/**
 * Employees with their addresses (1 to 4) and municipalities (1 to 5). An address belongs to one employee only, since
 * replies are matched to people by their sender address (design 5.2).
 *
 * @author shimomo
 */
final class M0002CreateEmployeeTables implements Migration
{
    #[Override]
    public function version(): string
    {
        return '0002';
    }

    #[Override]
    public function description(): string
    {
        return '従業員、メールアドレス、市町村';
    }

    #[Override]
    public function statements(string $driver): array
    {
        $id = $driver === 'mysql' ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $ref = $driver === 'mysql' ? 'INT UNSIGNED' : 'INTEGER';
        $table = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $employee = 'FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE';

        return [
            "CREATE TABLE employees (id {$id}, name VARCHAR(255) NOT NULL, department VARCHAR(255) NOT NULL, memo TEXT NOT NULL, active INTEGER NOT NULL, created_at VARCHAR(20) NOT NULL, updated_at VARCHAR(20) NOT NULL){$table}",
            "CREATE TABLE employee_emails (id {$id}, employee_id {$ref} NOT NULL, position INTEGER NOT NULL, address VARCHAR(255) NOT NULL, address_key VARCHAR(255) NOT NULL UNIQUE, {$employee}){$table}",
            "CREATE TABLE employee_cities (id {$id}, employee_id {$ref} NOT NULL, position INTEGER NOT NULL, city_code VARCHAR(7) NOT NULL, label VARCHAR(64) NOT NULL, {$employee}){$table}",
            'CREATE INDEX employee_cities_city_code ON employee_cities (city_code)',
        ];
    }
}
