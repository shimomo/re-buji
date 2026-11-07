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
     * @param 'ssl'|'starttls'|string $encryption ssl for an encrypted connection from the start (465), starttls to
     *                                            upgrade when the server offers it (587)
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $encryption,
        public string $username,
        public string $password,
        public string $fromAddress,
        public string $fromName,
    ) {
        //
    }
}
