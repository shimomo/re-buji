<?php

declare(strict_types=1);

namespace ReBuji\Tests\Support;

use RuntimeException;

/**
 * The variables that .env.example sets (its lines that are not comments), as Compose's env_file would pass them.
 *
 * @author shimomo
 */
final class EnvExample
{
    /**
     * @return array<string, string>
     */
    public static function vars(): array
    {
        $lines = file(dirname(__DIR__, 2) . '/.env.example', FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException('Cannot read .env.example');
        }

        $vars = [];

        foreach ($lines as $line) {
            if (preg_match('/\A([A-Z0-9_]+)=(.*)\z/', $line, $match) === 1) {
                $vars[$match[1]] = $match[2];
            }
        }

        return $vars;
    }

    /**
     * Every mail variable given but left blank, as the first .env.example had them.
     *
     * @return array<string, string>
     */
    public static function blankMail(): array
    {
        return [
            'REBUJI_SMTP_HOST' => '',
            'REBUJI_SMTP_PORT' => '587',
            'REBUJI_SMTP_ENCRYPTION' => 'starttls',
            'REBUJI_SMTP_USERNAME' => '',
            'REBUJI_SMTP_PASSWORD' => '',
            'REBUJI_MAIL_FROM_ADDRESS' => '',
            'REBUJI_MAIL_FROM_NAME' => '',
            'REBUJI_IMAP_HOST' => '',
            'REBUJI_IMAP_PORT' => '993',
            'REBUJI_IMAP_ENCRYPTION' => 'ssl',
            'REBUJI_IMAP_USERNAME' => '',
            'REBUJI_IMAP_PASSWORD' => '',
            'REBUJI_MAIL_CA_FILE' => '',
        ];
    }
}
