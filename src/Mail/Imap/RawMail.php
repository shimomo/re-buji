<?php

declare(strict_types=1);

namespace ReBuji\Mail\Imap;

/**
 * One message of the reply mailbox, as the server holds it.
 *
 * @author shimomo
 */
final readonly class RawMail
{
    public function __construct(public int $uid, public string $raw)
    {
        //
    }
}
