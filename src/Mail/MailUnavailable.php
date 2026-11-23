<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use RuntimeException;

/**
 * Mail cannot be sent right now (no settings, no connection, failed log-in, a temporary refusal): try again next run.
 * A failure before the log-in was through is the child AccountUnavailable.
 *
 * @author shimomo
 */
class MailUnavailable extends RuntimeException
{
    //
}
