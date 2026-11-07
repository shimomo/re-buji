<?php

declare(strict_types=1);

namespace ReBuji\Mail;

use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

/**
 * A 5xx answer to RCPT TO. It stays an UnexpectedResponseException, so symfony/mailer resets the session and keeps the
 * connection for the next mail.
 *
 * @author shimomo
 *
 * @internal
 */
final class RecipientRefusedResponse extends UnexpectedResponseException
{
    //
}
