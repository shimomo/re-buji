<?php

declare(strict_types=1);

namespace ReBuji\Settings;

/**
 * The mail account used to send. Replies come back to the same address (design 5.1).
 *
 * @author shimomo
 */
final readonly class SmtpSettings
{
    /**
     * @param 'ssl'|'starttls'|'none'|string $encryption ssl for an encrypted connection from the start (465), starttls
     *                                                   to upgrade, required (587); none only for the test servers
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $encryption,
        public string $username,
        #[\SensitiveParameter]
        public string $password,
        public string $fromAddress,
        public string $fromName,
        public string $caFile = '',
    ) {
        //
    }

    /**
     * Whether the account changed: one of them missing, or any field different. Strictly: inside an object ==, PHP
     * compares numeric strings as numbers, so a password changed from "0123" to "123" would look the same.
     */
    public static function differ(?self $before, ?self $after): bool
    {
        if ($before === null || $after === null) {
            return $before !== $after;
        }

        return get_object_vars($before) !== get_object_vars($after);
    }
}
