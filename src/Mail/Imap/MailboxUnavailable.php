<?php

declare(strict_types=1);

namespace ReBuji\Mail\Imap;

use RuntimeException;

/**
 * The reply mailbox cannot be read now (no settings, no connection, a refused log-in, an error answer). The mail stays
 * unseen and is read next run.
 *
 * @author shimomo
 */
final class MailboxUnavailable extends RuntimeException
{
    //
}
