<?php

declare(strict_types=1);

namespace ReBuji\Migrations;

use Override;
use ReBuji\Database\Migration;

/**
 * Earthquakes (and drills), one contact per person and earthquake, every mail sent, the settings, and the JMA feed's
 * state with the telegrams it returned. A contact keeps the person's name when they are deleted, so the record stays.
 *
 * @author shimomo
 */
final class M0003CreateQuakeTables implements Migration
{
    #[Override]
    public function version(): string
    {
        return '0003';
    }

    #[Override]
    public function description(): string
    {
        return '地震、連絡、送ったメール、設定、気象庁のフィード';
    }

    #[Override]
    public function statements(string $driver): array
    {
        $id = $driver === 'mysql' ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $ref = $driver === 'mysql' ? 'INT UNSIGNED' : 'INTEGER';
        $table = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        // 震源・震度に関する情報 can be over 300 KB, more than MySQL's TEXT holds.
        $xml = $driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';
        $time = 'VARCHAR(20)';

        return [
            "CREATE TABLE settings (name VARCHAR(64) NOT NULL PRIMARY KEY, value TEXT NOT NULL, updated_at {$time} NOT NULL){$table}",
            "CREATE TABLE feed_seen (url VARCHAR(255) NOT NULL PRIMARY KEY, expires_at {$time} NOT NULL){$table}",
            'CREATE INDEX feed_seen_expires_at ON feed_seen (expires_at)',
            "CREATE TABLE feed_states (feed_url VARCHAR(255) NOT NULL PRIMARY KEY, state TEXT NOT NULL){$table}",
            "CREATE TABLE telegrams (id {$id}, url VARCHAR(255) NOT NULL UNIQUE, event_id VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, title VARCHAR(64) NOT NULL, info_type VARCHAR(16) NOT NULL, reported_at {$time} NOT NULL, received_at {$time} NOT NULL, processed_at {$time} NULL, xml {$xml} NOT NULL){$table}",
            'CREATE INDEX telegrams_event_id ON telegrams (event_id)',
            "CREATE TABLE earthquakes (id {$id}, kind VARCHAR(8) NOT NULL, event_id VARCHAR(32) NULL UNIQUE, title VARCHAR(255) NOT NULL, hypocenter VARCHAR(255) NULL, magnitude VARCHAR(16) NULL, max_intensity VARCHAR(4) NULL, detected_at {$time} NOT NULL, basis_title VARCHAR(64) NULL, basis_reported_at {$time} NULL, threshold VARCHAR(4) NULL, delivery VARCHAR(16) NOT NULL, late INTEGER NOT NULL, cancelled_at {$time} NULL, created_at {$time} NOT NULL, updated_at {$time} NOT NULL){$table}",
            "CREATE TABLE notifications (id {$id}, earthquake_id {$ref} NOT NULL, employee_id {$ref} NULL, employee_name VARCHAR(255) NOT NULL, employee_department VARCHAR(255) NOT NULL, token VARCHAR(16) NOT NULL UNIQUE, cities TEXT NOT NULL, priority INTEGER NOT NULL, state VARCHAR(16) NOT NULL, not_before {$time} NULL, sent_count INTEGER NOT NULL, first_sent_at {$time} NULL, last_sent_at {$time} NULL, next_send_at {$time} NULL, replied_at {$time} NULL, cancel_notice VARCHAR(16) NULL, merged_into_id {$ref} NULL, created_at {$time} NOT NULL, updated_at {$time} NOT NULL, UNIQUE (earthquake_id, employee_id), FOREIGN KEY (earthquake_id) REFERENCES earthquakes (id) ON DELETE CASCADE, FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE SET NULL){$table}",
            'CREATE INDEX notifications_state ON notifications (state)',
            'CREATE INDEX notifications_employee_id ON notifications (employee_id)',
            "CREATE TABLE messages (id {$id}, notification_id {$ref} NULL, kind VARCHAR(16) NOT NULL, address VARCHAR(255) NOT NULL, message_id VARCHAR(255) NOT NULL UNIQUE, sent INTEGER NOT NULL, error TEXT NOT NULL, attempted_at {$time} NOT NULL, FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE){$table}",
            'CREATE INDEX messages_attempted_at ON messages (attempted_at)',
        ];
    }
}
