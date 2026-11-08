<?php

declare(strict_types=1);

namespace ReBuji\Migrations;

use Override;
use ReBuji\Database\Migration;

/**
 * Mail read from the reply mailbox (replies, auto-replies, bounces, and mail that matched nobody), each contact's
 * answer, and which sent mails bounced.
 *
 * @author shimomo
 */
final class M0004CreateReplyTables implements Migration
{
    #[Override]
    public function version(): string
    {
        return '0004';
    }

    #[Override]
    public function description(): string
    {
        return '返信、連絡の答え、戻ってきたメール';
    }

    #[Override]
    public function statements(string $driver): array
    {
        $id = $driver === 'mysql' ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $ref = $driver === 'mysql' ? 'INT UNSIGNED' : 'INTEGER';
        $table = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $time = 'VARCHAR(20)';
        $columns = [
            'notifications' => [
                'answer VARCHAR(16) NULL',
                'answer_source VARCHAR(8) NULL',
                'answered_by VARCHAR(255) NULL',
                'proxy_note TEXT NULL',
                "auto_replied_at {$time} NULL",
                "checked_at {$time} NULL",
                'checked_by VARCHAR(255) NULL',
            ],
            // The subject, to tell a reply whose subject the person rewrote (design 5.2).
            'messages' => ["bounced_at {$time} NULL", 'bounce_reason TEXT NULL', 'subject VARCHAR(255) NULL'],
        ];
        $statements = [
            "CREATE TABLE replies (id {$id}, notification_id {$ref} NULL, kind VARCHAR(16) NOT NULL, matched_by VARCHAR(16) NULL, from_address VARCHAR(255) NOT NULL, subject VARCHAR(255) NOT NULL, body TEXT NOT NULL, message_id VARCHAR(255) NULL UNIQUE, received_at {$time} NOT NULL, created_at {$time} NOT NULL, FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE){$table}",
            'CREATE INDEX replies_notification_id ON replies (notification_id)',
        ];

        // One column per statement: SQLite adds only one at a time.
        foreach ($columns as $name => $added) {
            foreach ($added as $column) {
                $statements[] = "ALTER TABLE {$name} ADD COLUMN {$column}";
            }
        }

        return $statements;
    }
}
