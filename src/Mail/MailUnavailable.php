<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use RuntimeException;

/**
 * Mail cannot be sent right now (no settings, no connection, failed log-in, a temporary refusal): try again next run.
 *
 * @author shimomo
 */
final class MailUnavailable extends RuntimeException
{
    //
}
