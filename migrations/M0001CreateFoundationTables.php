<?php

declare(strict_types=1);

namespace ReBuji\Migrations;

use Override;
use ReBuji\Database\Migration;

/**
 * Admins, login attempts and the system state.
 *
 * @author shimomo
 */
final class M0001CreateFoundationTables implements Migration
{
    #[Override]
    public function version(): string
    {
        return '0001';
    }

    #[Override]
    public function description(): string
    {
        return '管理者、ログインの記録、システムの状態';
    }

    #[Override]
    public function statements(string $driver): array
    {
        $id = $driver === 'mysql' ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $table = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        return [
            "CREATE TABLE admins (id {$id}, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, created_at VARCHAR(20) NOT NULL){$table}",
            "CREATE TABLE login_attempts (id {$id}, email VARCHAR(255) NOT NULL, ip VARCHAR(45) NOT NULL, succeeded INTEGER NOT NULL, attempted_at VARCHAR(20) NOT NULL){$table}",
            'CREATE INDEX login_attempts_attempted_at ON login_attempts (attempted_at)',
            "CREATE TABLE system_state (name VARCHAR(64) NOT NULL PRIMARY KEY, value TEXT NOT NULL, updated_at VARCHAR(20) NOT NULL){$table}",
        ];
    }
}
