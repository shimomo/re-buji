<?php

declare(strict_types=1);

namespace ReBuji\Mail\Imap;

/**
 * The mailbox replies come back to: the same account mail is sent from (design 5.1).
 *
 * @author shimomo
 */
final readonly class ImapSettings
{
    /**
     * @param 'ssl'|'starttls'|'none'|string $encryption ssl for TLS from the start (993), starttls to upgrade, required
     *                                                   (143); none only for the test servers
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $encryption,
        public string $username,
        public string $password,
    ) {
        //
    }
}
